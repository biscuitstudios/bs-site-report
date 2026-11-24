<?php
// Exercises Bsrep_Accessibility::collect(), the call Bsrep_Collector::build()
// makes, with the images_missing_alt count added in 0.1.5.
//
// The count is a WHERE clause on Accessibility Checker's issues table, so a mock
// that pattern-matched the SQL would only prove the SQL matches the mock. This
// stands $wpdb up on an in-memory SQLite table with the same columns, and the
// real query runs on real rows either side of every line it draws: open against
// ignored, globally ignored, the linked-image rule, another rule, another site.
//
// The stub keeps the one $wpdb quirk that matters here: get_var() turns an empty
// string into null (see THEME-RULES.md), and a failed query returns null with
// last_error set.
define( 'ABSPATH', dirname( __DIR__ ) . '/' );

require_once dirname( __DIR__ ) . '/includes/class-bsrep-accessibility.php';

$fail = 0;
function check( $label, $got, $want ) {
	global $fail;
	if ( $got === $want ) { printf( "  ok    %s\n", $label ); return; }
	$fail++;
	printf( "  FAIL  %s\n        want: %s\n        got : %s\n", $label, var_export( $want, true ), var_export( $got, true ) );
}

// --- WordPress stubs ---------------------------------------------------------

$GLOBALS['plugins'] = array( 'accessibility-checker/accessibility-checker.php' => array() );
$GLOBALS['active']  = array( 'accessibility-checker/accessibility-checker.php' );

function get_plugins() { return $GLOBALS['plugins']; }
function is_plugin_active( $f ) { return in_array( $f, $GLOBALS['active'], true ); }
function get_current_blog_id() { return 1; }
function get_post_meta( $id, $key, $single ) {
	return array( 'errors' => 2, 'contrast_errors' => 1, 'warnings' => 3, 'passed_tests' => 90 );
}

class WP_Query {
	public $posts = array( 11, 12 );
	public function __construct( $args ) {}
}

class Test_Wpdb {
	public $prefix     = 'wp_';
	public $last_error = '';
	public $pdo;

	public function __construct() {
		$this->pdo = new PDO( 'sqlite::memory:' );
		$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
	}

	public function esc_like( $t ) { return addcslashes( $t, '_%\\' ); }

	public function prepare( $q, ...$args ) {
		$pdo = $this->pdo;
		return preg_replace_callback(
			'/%[ids]/',
			function ( $m ) use ( &$args, $pdo ) {
				$v = array_shift( $args );
				if ( '%i' === $m[0] ) { return '`' . str_replace( '`', '``', $v ) . '`'; }
				if ( '%d' === $m[0] ) { return (string) (int) $v; }
				return $pdo->quote( (string) $v );
			},
			$q
		);
	}

	public function get_var( $sql ) {
		$this->last_error = '';
		// MySQL's SHOW TABLES has no SQLite spelling. Same question, same answer.
		if ( preg_match( "/^SHOW TABLES LIKE ('.*')$/", $sql, $m ) ) {
			$sql = "SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE $m[1] ESCAPE '\\'";
		}
		try {
			$v = $this->pdo->query( $sql )->fetchColumn();
		} catch ( PDOException $e ) {
			$this->last_error = $e->getMessage();
			return null;
		}
		return ( false === $v || '' === $v ) ? null : (string) $v;
	}
}

// Accessibility Checker's columns, from admin/class-update-database.php. siteid
// is text there, so a text column is kept here too.
function make_table( $wpdb, $columns = null ) {
	$wpdb->pdo->exec( 'DROP TABLE IF EXISTS wp_accessibility_checker' );
	$wpdb->pdo->exec(
		'CREATE TABLE wp_accessibility_checker (' . ( $columns ?? 'id INTEGER PRIMARY KEY AUTOINCREMENT, postid INTEGER NOT NULL, siteid TEXT NOT NULL, rule TEXT NOT NULL, object TEXT NOT NULL, ignre INTEGER NOT NULL, ignre_global INTEGER NOT NULL' ) . ')'
	);
}
function add_issue( $wpdb, $rule, $ignre = 0, $global = 0, $site = '1', $post = 11 ) {
	$s = $wpdb->pdo->prepare( 'INSERT INTO wp_accessibility_checker (postid, siteid, rule, object, ignre, ignre_global) VALUES (?, ?, ?, ?, ?, ?)' );
	$s->execute( array( $post, $site, $rule, '<img src="x.jpg">', $ignre, $global ) );
}
function alt() { return ( new Bsrep_Accessibility() )->collect()['images_missing_alt'] ?? 'MISSING'; }

$GLOBALS['wpdb'] = new Test_Wpdb();
$wpdb            = $GLOBALS['wpdb'];

// --- Either side of each line ------------------------------------------------

echo "images_missing_alt, one fixture row at a time\n";
make_table( $wpdb );

check( 'an empty table is a real, measured zero', alt()['value'], 0 );
check( '...and says it was measured', alt()['available'], true );

add_issue( $wpdb, 'img_alt_missing' );
check( 'an open img_alt_missing issue counts', alt()['value'], 1 );

add_issue( $wpdb, 'img_alt_missing', 1, 0 );
check( 'an ignored one does not', alt()['value'], 1 );

add_issue( $wpdb, 'img_alt_missing', 0, 1 );
check( 'a globally ignored one does not', alt()['value'], 1 );

add_issue( $wpdb, 'img_linked_alt_missing' );
check( 'an open linked-image issue counts', alt()['value'], 2 );

add_issue( $wpdb, 'img_linked_alt_missing', 1, 0 );
check( 'an ignored linked-image issue does not', alt()['value'], 2 );

add_issue( $wpdb, 'empty_paragraph_tag' );
add_issue( $wpdb, 'img_alt_empty' );
check( 'another rule\'s issue does not, img_alt_empty included', alt()['value'], 2 );

add_issue( $wpdb, 'img_alt_missing', 0, 0, '2' );
check( 'another site\'s issue in the same table does not', alt()['value'], 2 );

add_issue( $wpdb, 'img_alt_missing', 0, 0, '1', 12 );
check( 'an open issue on a second page counts', alt()['value'], 3 );

check( 'the rules are named in the payload', alt()['rules'], array( 'img_alt_missing', 'img_linked_alt_missing' ) );

echo "\nthe existing summary fields are untouched\n";
$section = ( new Bsrep_Accessibility() )->collect();
check( 'errors still summed from _edac_summary', $section['errors'], 4 );
check( 'section still available', $section['available'], true );

echo "\nnot measurable is null with a reason, never 0\n";
$wpdb->pdo->exec( 'DROP TABLE wp_accessibility_checker' );
$got = alt();
check( 'missing table: value is null', $got['value'], null );
check( 'missing table: unavailable', $got['available'], false );
check( 'missing table: a reason is given', isset( $got['reason'] ) && '' !== $got['reason'], true );

// A table with the right name but no ignre_global column: a schema change.
make_table( $wpdb, 'id INTEGER PRIMARY KEY, postid INTEGER, siteid TEXT, rule TEXT, object TEXT, ignre INTEGER' );
$got = alt();
check( 'changed schema: value is null', $got['value'], null );
check( 'changed schema: a reason is given', isset( $got['reason'] ) && '' !== $got['reason'], true );

// A table whose name only matches through an unescaped underscore must not
// pass the existence check.
$wpdb->pdo->exec( 'DROP TABLE wp_accessibility_checker' );
$wpdb->pdo->exec( 'CREATE TABLE wpXaccessibility_checker (id INTEGER)' );
check( 'a look-alike table name is not taken for theirs', alt()['value'], null );

echo "\nplugin not there\n";
$GLOBALS['active'] = array();
$section           = ( new Bsrep_Accessibility() )->collect();
check( 'installed, inactive: installed stays true', $section['installed'], true );
check( 'installed, inactive: no alt count is invented', array_key_exists( 'images_missing_alt', $section ), false );

$GLOBALS['plugins'] = array();
$section            = ( new Bsrep_Accessibility() )->collect();
check( 'not installed: installed is false', $section['installed'], false );
check( 'not installed: available is false', $section['available'], false );
check( 'not installed: no alt count is invented', array_key_exists( 'images_missing_alt', $section ), false );

echo "\n";
echo $fail ? "$fail FAILED\n" : "all passed\n";
exit( $fail ? 1 : 0 );
