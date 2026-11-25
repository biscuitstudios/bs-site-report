<?php
// Exercises Bsrep_Analytics_Tables, the 0.1.7 reader for Independent Analytics'
// own tables, and the way Bsrep_Analytics wires it into the payload.
//
// Same approach as test-accessibility.php: $wpdb stands on an in-memory SQLite
// copy of IA's tables (columns from IA's migrations, DB version 53), and the real
// queries run on real rows. A mock that pattern-matched the SQL would only prove
// the SQL matches the mock.
//
// The site is in America/New_York and IA stores UTC, so every window edge has a
// row a second inside and a second outside it, plus one row that only a window
// built in UTC instead of the site's timezone would let in.
//
// IA drops bots before storing anything (REST_API::track_view()), so there is no
// bot row to fixture. That is a fact about IA, not a gap in this suite.
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'ARRAY_A', 'ARRAY_A' );

require_once dirname( __DIR__ ) . '/includes/class-bsrep-analytics.php';
require_once dirname( __DIR__ ) . '/includes/class-bsrep-analytics-tables.php';

$fail = 0;
function check( $label, $got, $want ) {
	global $fail;
	if ( $got === $want ) { printf( "  ok    %s\n", $label ); return; }
	$fail++;
	printf( "  FAIL  %s\n        want: %s\n        got : %s\n", $label, var_export( $want, true ), var_export( $got, true ) );
}

// --- WordPress stubs ---------------------------------------------------------

function wp_timezone() { return new DateTimeZone( 'America/New_York' ); }

$GLOBALS['options'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['options'] ) ? $GLOBALS['options'][ $k ] : $d; }

// WordPress's posts, as top_pages asks after them: id => type, status, title.
// A post missing here has been deleted. get_the_title() returns texturized,
// entity-encoded text the way core does.
$GLOBALS['wp_posts']      = array();
$GLOBALS['wp_post_types'] = array( 'page', 'post', 'project', 'attachment' );
$GLOBALS['get_posts']     = array();
function post_type_exists( $t ) { return in_array( $t, $GLOBALS['wp_post_types'], true ); }
function get_posts( $args ) {
	$GLOBALS['get_posts'][] = $args;
	$out = array();
	foreach ( $args['post__in'] as $id ) {
		$p = $GLOBALS['wp_posts'][ $id ] ?? null;
		if ( $p && in_array( $p[0], (array) $args['post_type'], true ) && $p[1] === $args['post_status'] ) {
			$out[] = $id;
		}
	}
	return $out;
}
function get_the_title( $id ) { return $GLOBALS['wp_posts'][ $id ][2] ?? ''; }
function get_post_type( $id ) { return $GLOBALS['wp_posts'][ $id ][0] ?? false; }
function get_permalink( $id ) { return 10 === $id ? 'https://example.test/' : 'https://example.test/?p=' . $id; }

function set_ia_options() {
	$GLOBALS['options'] = array(
		'iawp_db_version'     => '53',
		'iawp_is_migrating'   => '0',
		'iawp_pruning_cutoff' => 'disabled',
	);
}

class Test_Wpdb {
	public $prefix     = 'wp_';
	public $last_error = '';
	public $pdo;
	public $queries    = array();

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
		$this->queries[]  = $sql;
		if ( preg_match( "/^SHOW TABLES LIKE ('.*')$/", $sql, $m ) ) {
			$sql = "SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE $m[1] ESCAPE '\\'";
		}
		try {
			$v = $this->pdo->query( $sql )->fetchColumn();
		} catch ( PDOException $e ) {
			$this->last_error = $e->getMessage();
			return null;
		}
		// $wpdb->get_var() returns null for no row and for an empty string.
		return ( false === $v || '' === $v ) ? null : (string) $v;
	}

	public function get_results( $sql, $output ) {
		$this->last_error = '';
		$this->queries[]  = $sql;
		try {
			$rows = $this->pdo->query( $sql )->fetchAll( PDO::FETCH_ASSOC );
		} catch ( PDOException $e ) {
			$this->last_error = $e->getMessage();
			return null;
		}
		// $wpdb returns every column as a string or null.
		return array_map( fn( $r ) => array_map( fn( $v ) => null === $v ? null : (string) $v, $r ), $rows );
	}
}

// --- IA's tables, as DB version 53 leaves them ---------------------------------

function build_tables() {
	global $wpdb;
	$wpdb = new Test_Wpdb();
	$p    = 'wp_independent_analytics_';
	$db   = $wpdb->pdo;

	$db->exec( "CREATE TABLE {$p}sessions ( session_id INTEGER PRIMARY KEY, visitor_id TEXT, referrer_id INTEGER, city_id INTEGER, country_id INTEGER, device_type_id INTEGER, created_at TEXT NOT NULL )" );
	$db->exec( "CREATE TABLE {$p}views ( id INTEGER PRIMARY KEY, session_id INTEGER, viewed_at TEXT NOT NULL, resource_id INTEGER )" );
	$db->exec( "CREATE TABLE {$p}resources ( id INTEGER PRIMARY KEY, resource TEXT NOT NULL, singular_id INTEGER, cached_title TEXT )" );
	$db->exec( "CREATE TABLE {$p}device_types ( device_type_id INTEGER PRIMARY KEY, device_type TEXT NOT NULL )" );
	$db->exec( "CREATE TABLE {$p}countries ( country_id INTEGER PRIMARY KEY, country_code TEXT NOT NULL, country TEXT NOT NULL, continent TEXT NOT NULL )" );
	$db->exec( "CREATE TABLE {$p}cities ( city_id INTEGER PRIMARY KEY, country_id INTEGER NOT NULL, subdivision TEXT NOT NULL, city TEXT NOT NULL )" );
	$db->exec( "CREATE TABLE {$p}referrers ( id INTEGER PRIMARY KEY, referrer TEXT, domain TEXT, referrer_type_id INTEGER NOT NULL )" );
	$db->exec( "CREATE TABLE {$p}referrer_types ( id INTEGER PRIMARY KEY, referrer_type TEXT NOT NULL )" );

	// IA stores device types capitalised (Utils/Device.php detect_type()).
	$db->exec( "INSERT INTO {$p}device_types VALUES (1,'Desktop'),(2,'Mobile'),(3,'Tablet')" );
	$db->exec( "INSERT INTO {$p}countries VALUES (1,'US','United States','North America'),(2,'CA','Canada','North America')" );
	$db->exec( "INSERT INTO {$p}cities VALUES (1,1,'Georgia','Atlanta'),(2,1,'Georgia','Decatur'),(3,2,'Ontario','Toronto'),(4,1,'Georgia','Athens'),(5,1,'Georgia','Macon'),(6,1,'Georgia','Savannah')" );
	$db->exec( "INSERT INTO {$p}referrer_types VALUES (1,'Direct'),(2,'Search'),(3,'AI'),(4,'Social'),(5,'Referrer')" );
	// Direct is the row IA writes with an empty domain (Views/View.php).
	$db->exec( "INSERT INTO {$p}referrers VALUES (1,'Direct','',1),(2,'Google','www.google.com',2),(3,'ChatGPT','chatgpt.com',3),(4,'Perplexity','perplexity.ai',3),(5,'Facebook','www.facebook.com',4),(6,'Newsletter Site','news.example.org',5),(7,'Bing','www.bing.com',2)" );

	// session_id, visitor, referrer, city, device, created_at (UTC), viewed_at (UTC, null = no view).
	$mid  = '2026-06-01 12:00:00';
	$rows = array(
		array( 1, 'A', 1, 1, 1, $mid, $mid ),                       // A twice, on two devices
		array( 2, 'A', 2, 1, 2, $mid, $mid ),
		array( 3, 'B', 2, 1, 1, $mid, $mid ),
		array( 18, 'B', 2, 1, 1, '2026-07-01 12:00:00', '2026-07-01 12:00:00' ), // B again, same device, referrer and city: one person
		array( 4, 'C', 2, 2, 2, $mid, $mid ),
		array( 5, 'D', 3, 3, 2, $mid, $mid ),                       // ChatGPT, filed AI by IA
		array( 6, 'E', 4, null, 3, $mid, $mid ),                    // Perplexity, AI; no city
		array( 7, 'F', 5, 4, null, $mid, $mid ),                    // no device type
		array( 8, 'G', 1, 5, 1, $mid, $mid ),
		array( 9, 'H', 6, 6, 1, $mid, $mid ),
		array( 10, 'I', 7, 1, 1, '2025-12-01 05:00:00', '2025-12-01 05:00:00' ), // first second of December 1, New York
		array( 11, 'J', 1, 2, 1, '2026-11-26 04:59:59', '2026-11-26 04:59:59' ), // last second of November 25, New York
		array( 12, 'K', 1, 2, 1, '2026-11-26 04:59:50', '2026-11-26 05:00:10' ), // session in, its only view out
		array( 13, null, 1, 1, 1, $mid, $mid ),                     // no visitor id: a session, not a visitor
		array( 14, 'X1', 3, 1, 2, '2025-12-01 04:59:59', '2025-12-01 04:59:59' ), // a second before the window
		array( 15, 'X2', 2, 1, 1, '2026-11-26 05:00:00', '2026-11-26 05:00:00' ), // a second after it
		array( 16, 'X3', 3, 3, 2, '2025-12-01 02:00:00', '2025-12-01 02:00:00' ), // December 1 in UTC, November 30 in New York
		array( 17, 'L', 1, 1, 1, $mid, null ),                      // a session with no views at all
	);

	$view_id = 0;
	foreach ( $rows as $r ) {
		$st = $db->prepare( "INSERT INTO {$p}sessions (session_id, visitor_id, referrer_id, city_id, device_type_id, created_at) VALUES (?,?,?,?,?,?)" );
		$st->execute( array_slice( $r, 0, 6 ) );
		if ( null !== $r[6] ) {
			$db->prepare( "INSERT INTO {$p}views (id, session_id, viewed_at) VALUES (?,?,?)" )->execute( array( ++$view_id, $r[0], $r[6] ) );
		}
	}
}

function reader() {
	return new Bsrep_Analytics_Tables(
		new DateTimeImmutable( '2025-12-01 15:30:00', wp_timezone() ),
		new DateTimeImmutable( '2026-11-25 09:10:00', wp_timezone() )
	);
}

// --- Counting ----------------------------------------------------------------

set_ia_options();
build_tables();
$got = reader()->collect();

echo "the window\n";
check( 'from is the first day, as a date', $got['devices']['from'], '2025-12-01' );
check( 'to is the last day, as a date', $got['devices']['to'], '2026-11-25' );
check( 'every block carries the window', array( $got['cities']['to'], $got['referrers']['to'], $got['ai_referrals']['to'] ), array( '2026-11-25', '2026-11-25', '2026-11-25' ) );
$sql = implode( "\n", $wpdb->queries );
check( 'the window reaches SQL in UTC, from midnight in New York', strpos( $sql, "'2025-12-01 05:00:00'" ) !== false, true );
check( 'and ends at 23:59:59 in New York', strpos( $sql, "'2026-11-26 04:59:59'" ) !== false, true );
check( 'nothing the reader sent writes', (bool) preg_grep( '/^\s*(INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|CREATE|TRUNCATE)\b/i', $wpdb->queries ), false );

echo "\ndevices\n";
$d = $got['devices'];
check( 'available', $d['available'], true );
check( 'total_visitors counts people once, edges and all, nobody outside', $d['total_visitors'], 10 );
check( 'visitors_with_device_type leaves out the visitor IA could not type', $d['visitors_with_device_type'], 9 );
check( 'rows, lowercased, by visitors', $d['rows'], array(
	array( 'type' => 'desktop', 'visitors' => 6 ),
	array( 'type' => 'mobile', 'visitors' => 3 ),
	array( 'type' => 'tablet', 'visitors' => 1 ),
) );
check( 'the source names the tables and the DB version', strpos( $d['source'], 'device_types' ) !== false && strpos( $d['source'], 'version 53' ) !== false, true );

echo "\ncities\n";
$c = $got['cities'];
check( 'available', $c['available'], true );
check( 'total_visitors includes the visitor with no city', $c['total_visitors'], 10 );
check( 'visitors_with_city is what IA\'s Cities screen shows', $c['visitors_with_city'], 9 );
check( 'fewer than 25 cities: all six, by visitors, ties by name', $c['rows'], array(
	array( 'city' => 'Atlanta', 'region' => 'Georgia', 'country_code' => 'US', 'visitors' => 3 ),
	array( 'city' => 'Decatur', 'region' => 'Georgia', 'country_code' => 'US', 'visitors' => 2 ),
	array( 'city' => 'Athens', 'region' => 'Georgia', 'country_code' => 'US', 'visitors' => 1 ),
	array( 'city' => 'Macon', 'region' => 'Georgia', 'country_code' => 'US', 'visitors' => 1 ),
	array( 'city' => 'Savannah', 'region' => 'Georgia', 'country_code' => 'US', 'visitors' => 1 ),
	array( 'city' => 'Toronto', 'region' => 'Ontario', 'country_code' => 'CA', 'visitors' => 1 ),
) );

echo "\ncities, cut at 25\n";
// 24 more one-visitor towns, City 01 to City 24, so 30 cities in all and 28 of
// them tied at one visitor. The cut has to fall inside that tie, by name.
$extra_city_rows = function () {
	global $wpdb;
	for ( $i = 1; $i <= 24; $i++ ) {
		$id = 100 + $i;
		$wpdb->pdo->exec( sprintf( "INSERT INTO wp_independent_analytics_cities VALUES (%d,1,'Virginia','City %02d')", $id, $i ) );
		$wpdb->pdo->exec( sprintf( "INSERT INTO wp_independent_analytics_sessions (session_id, visitor_id, referrer_id, city_id, device_type_id, created_at) VALUES (%d,'Z%d',1,%d,1,'2026-06-02 12:00:00')", $id, $i, $id ) );
		$wpdb->pdo->exec( sprintf( "INSERT INTO wp_independent_analytics_views (id, session_id, viewed_at) VALUES (%d,%d,'2026-06-02 12:00:00')", $id, $id ) );
	}
};
$extra_city_rows();
$c25 = reader()->block( 'cities' );
$names = array_column( $c25['rows'], 'city' );
check( 'exactly 25 rows', count( $c25['rows'] ), 25 );
check( 'the two with most visitors lead', array_slice( $names, 0, 2 ), array( 'Atlanta', 'Decatur' ) );
check( 'then the tie at one, by name, up to the cut', array_slice( $names, 2 ), array_merge( array( 'Athens' ), array_map( fn( $i ) => sprintf( 'City %02d', $i ), range( 1, 22 ) ) ) );
check( 'City 23, City 24, Macon, Savannah and Toronto fall past the cut', array_values( array_intersect( array( 'City 23', 'City 24', 'Macon', 'Savannah', 'Toronto' ), $names ) ), array() );
check( 'total_visitors still everyone, the 24 new towns included', $c25['total_visitors'], 34 );
check( 'visitors_with_city base unchanged in meaning', $c25['visitors_with_city'], 33 );
check( 'row shape unchanged', array_keys( $c25['rows'][24] ?? array() ), array( 'city', 'region', 'country_code', 'visitors' ) );

echo "\nreferrers\n";
$r = $got['referrers'];
check( 'available', $r['available'], true );
check( 'total_visitors', $r['total_visitors'], 10 );
check( 'visitors_with_referrer', $r['visitors_with_referrer'], 10 );
check( 'top six, named and typed as IA files them', $r['rows'], array(
	array( 'name' => 'Direct', 'type' => 'Direct', 'visitors' => 3 ),
	array( 'name' => 'Google', 'type' => 'Search', 'visitors' => 3 ),
	array( 'name' => 'Bing', 'type' => 'Search', 'visitors' => 1 ),
	array( 'name' => 'ChatGPT', 'type' => 'AI', 'visitors' => 1 ),
	array( 'name' => 'Facebook', 'type' => 'Social', 'visitors' => 1 ),
	array( 'name' => 'Newsletter Site', 'type' => 'Referrer', 'visitors' => 1 ),
) );

echo "\nai_referrals\n";
$a = $got['ai_referrals'];
check( 'available', $a['available'], true );
check( 'value is every visitor IA files as AI, in the window only', $a['value'], 2 );
check( 'a note on the ChatGPT gap travels with it', isset( $a['note'] ) && strpos( $a['note'], '2.16.4' ) !== false, true );

echo "\na measured zero is still a number\n";
$wpdb->pdo->exec( "UPDATE wp_independent_analytics_referrers SET referrer_type_id = 5 WHERE referrer_type_id = 3" );
$z = reader()->block( 'ai_referrals' );
check( 'no AI rows: available', $z['available'], true );
check( 'no AI rows: value 0', $z['value'], 0 );

// --- Guards --------------------------------------------------------------------

function refused( $label, $block, $needle ) {
	check( "$label: unavailable", $block['available'], false );
	check( "$label: reason says why", isset( $block['reason'] ) && false !== strpos( $block['reason'], $needle ), true );
	check( "$label: no number is sent", array_key_exists( 'value', $block ) || array_key_exists( 'total_visitors', $block ) || array_key_exists( 'rows', $block ), false );
	check( "$label: the window is still stated", $block['from'] ?? null, '2025-12-01' );
}

echo "\na DB version this was not written against\n";
set_ia_options();
build_tables();
$GLOBALS['options']['iawp_db_version'] = '54';
$g = reader()->collect();
foreach ( array( 'devices', 'cities', 'referrers', 'ai_referrals' ) as $name ) {
	refused( "$name on 54", $g[ $name ], 'version is 54' );
}
check( 'and not a single query ran', count( $wpdb->queries ), 0 );

echo "\nIA not set up at all\n";
unset( $GLOBALS['options']['iawp_db_version'] );
refused( 'no version option', reader()->block( 'devices' ), 'not set' );

echo "\na migration in progress\n";
set_ia_options();
$GLOBALS['options']['iawp_is_migrating'] = '1';
refused( 'migrating', reader()->block( 'cities' ), 'migration' );

echo "\npruning\n";
set_ia_options();
$GLOBALS['options']['iawp_pruning_cutoff'] = 'ninety-days';
refused( 'ninety days kept', reader()->block( 'referrers' ), 'ninety-days' );
$GLOBALS['options']['iawp_pruning_cutoff'] = 'one-year';
check( 'one year kept still covers the window', reader()->block( 'referrers' )['available'], true );

echo "\na missing table costs its own block only\n";
set_ia_options();
build_tables();
$wpdb->pdo->exec( 'DROP TABLE wp_independent_analytics_cities' );
$g = reader()->collect();
refused( 'cities without its table', $g['cities'], 'wp_independent_analytics_cities does not exist' );
check( 'devices still read', $g['devices']['available'], true );
check( 'devices still right', $g['devices']['total_visitors'], 10 );

echo "\na failed query\n";
build_tables();
$wpdb->pdo->exec( 'DROP TABLE wp_independent_analytics_device_types' );
$wpdb->pdo->exec( 'CREATE TABLE wp_independent_analytics_device_types ( device_type_id INTEGER PRIMARY KEY, renamed TEXT )' );
refused( 'devices on a changed schema', reader()->block( 'devices' ), 'query failed' );

// --- Wired into the payload ------------------------------------------------------

echo "\nthe payload\n";
$GLOBALS['analytics_calls'] = array();
function iawp_analytics( $from, $to ) {
	$GLOBALS['analytics_calls'][] = $from->format( 'Y-m-d' ) . '..' . $to->format( 'Y-m-d' );
	return array( 'views' => 10, 'visitors' => 4, 'sessions' => 6 );
}
$GLOBALS['top_posts_called'] = false;
function iawp_top_posts( $args ) { $GLOBALS['top_posts_called'] = true; return array(); }

set_ia_options();
build_tables();
$p   = ( new Bsrep_Analytics() )->collect( 12 );
$now = new DateTimeImmutable( 'now', wp_timezone() );
$start = $now->modify( 'first day of -11 month' )->format( 'Y-m-d' );
check( 'section available', $p['available'], true );
check( 'all four blocks are in it', array_keys( array_intersect_key( $p, array_flip( array( 'devices', 'cities', 'referrers', 'ai_referrals' ) ) ) ), array( 'devices', 'cities', 'referrers', 'ai_referrals' ) );
check( 'countries left alone', $p['countries']['available'], false );
check( 'blocks start on the month the monthly series starts', $p['devices']['from'], $start );
check( 'and that month is the series\' first', $p['monthly'][0]['month'], substr( $start, 0, 7 ) );
check( 'blocks end today', $p['devices']['to'], $now->format( 'Y-m-d' ) );
check( 'top_pages states its window', array( $p['top_pages']['from'], $p['top_pages']['to'] ), array( $start, $now->format( 'Y-m-d' ) ) );
check( 'top_pages comes from the tables now', strpos( $p['top_pages']['source'], 'resources' ) !== false, true );
check( 'iawp_top_posts() is no longer called', $GLOBALS['top_posts_called'], false );
check( 'top_pages still sits before devices', array_search( 'top_pages', array_keys( $p ), true ) < array_search( 'devices', array_keys( $p ), true ), true );


// --- 0.1.9: top_pages, every page, post and project ---------------------------------

// Adds $visitors sessions in the window, each its own visitor, and $views views
// of one resource spread across them.
function add_views( $resource, $views, $visitors, $when = '2026-06-03 12:00:00' ) {
	global $wpdb;
	static $sid = 1000, $vid = 1000;
	for ( $i = 0; $i < $visitors; $i++ ) {
		++$sid;
		$wpdb->pdo->exec( "INSERT INTO wp_independent_analytics_sessions (session_id, visitor_id, referrer_id, city_id, device_type_id, created_at) VALUES ($sid,'P$sid',1,1,1,'$when')" );
		$n = intdiv( $views, $visitors ) + ( $i < $views % $visitors ? 1 : 0 );
		for ( $j = 0; $j < $n; $j++ ) {
			++$vid;
			$wpdb->pdo->exec( "INSERT INTO wp_independent_analytics_views (id, session_id, viewed_at, resource_id) VALUES ($vid,$sid,'$when',$resource)" );
		}
	}
}

function build_pages() {
	global $wpdb;
	set_ia_options();
	build_tables();
	$GLOBALS['wp_post_types'] = array( 'page', 'post', 'project', 'attachment' );
	$GLOBALS['get_posts']     = array();
	$GLOBALS['wp_posts']      = array(
		10 => array( 'page', 'publish', 'Home' ),                        // static front page, singular
		20 => array( 'post', 'publish', 'alpha post' ),
		21 => array( 'post', 'publish', 'Beta post' ),
		30 => array( 'project', 'publish', 'Zeta&#8217;s project' ),
		50 => array( 'post', 'draft', 'A draft' ),
		60 => array( 'attachment', 'inherit', 'A photo' ),
		70 => array( 'page', 'private', 'A private page' ),
		// 40 is not here: deleted, though IA still has its resource row.
	);
	for ( $i = 1; $i <= 9; $i++ ) {
		$GLOBALS['wp_posts'][ 100 + $i ] = array( 'page', 'publish', sprintf( 'Filler %02d', $i ) );
	}
	$r = "INSERT INTO wp_independent_analytics_resources VALUES ";
	$wpdb->pdo->exec( $r . "(1,'singular',10,'Home'),(2,'home',NULL,'Blog'),(3,'singular',20,'alpha post'),(4,'singular',21,'Beta post'),(5,'singular',30,'Zeta project'),(6,'singular',40,'Atlanta Girls School'),(7,'singular',50,'A draft'),(8,'404',NULL,'404'),(9,'singular',60,'A photo'),(10,'singular',70,'A private page'),(11,'author_archive',NULL,'Lucy')" );
	for ( $i = 1; $i <= 9; $i++ ) {
		$wpdb->pdo->exec( $r . sprintf( "(%d,'singular',%d,'Filler %02d')", 20 + $i, 100 + $i, $i ) );
	}
	add_views( 1, 50, 30 );          // Home
	add_views( 2, 49, 20 );          // the posts page: IA's 'home', not singular
	add_views( 6, 48, 40 );          // deleted
	add_views( 7, 47, 40 );          // draft
	add_views( 8, 46, 40 );          // 404
	add_views( 9, 45, 40 );          // attachment
	add_views( 10, 44, 40 );         // private
	add_views( 11, 43, 40 );         // author archive
	add_views( 5, 10, 8 );           // Zeta: ties on views, wins on visitors
	add_views( 3, 10, 5 );           // alpha post and Beta post tie on both,
	add_views( 4, 10, 5 );           // so title decides, case-insensitively
	add_views( 3, 99, 9, '2025-11-30 12:00:00' ); // alpha post before the window
	for ( $i = 1; $i <= 9; $i++ ) {
		add_views( 20 + $i, 2, 1 );
	}
}

function top_row( $id, $title, $type, $views, $visitors, $sessions ) {
	return array( 'id' => $id, 'title' => $title, 'type' => $type, 'url' => get_permalink( $id ), 'views' => $views, 'visitors' => $visitors, 'sessions' => $sessions );
}

echo "\ntop_pages\n";
build_pages();
$t = reader()->block( 'top_pages' );
check( 'available', $t['available'], true );
check( 'window stated', array( $t['from'], $t['to'] ), array( '2025-12-01', '2026-11-25' ) );
check( 'source names the resources table', strpos( $t['source'], 'resources' ) !== false, true );
check( 'post types covered', $t['post_types'], array( 'page', 'post', 'project' ) );
check( 'ten rows: Home, then by views, visitors, title; nothing deleted, unpublished or not singular', $t['rows'], array(
	top_row( 10, 'Home', 'page', 50, 30, 30 ),
	top_row( 30, "Zeta\u{2019}s project", 'project', 10, 8, 8 ),
	top_row( 20, 'alpha post', 'post', 10, 5, 5 ),
	top_row( 21, 'Beta post', 'post', 10, 5, 5 ),
	top_row( 101, 'Filler 01', 'page', 2, 1, 1 ),
	top_row( 102, 'Filler 02', 'page', 2, 1, 1 ),
	top_row( 103, 'Filler 03', 'page', 2, 1, 1 ),
	top_row( 104, 'Filler 04', 'page', 2, 1, 1 ),
	top_row( 105, 'Filler 05', 'page', 2, 1, 1 ),
	top_row( 106, 'Filler 06', 'page', 2, 1, 1 ),
) );
check( 'WordPress is asked once, not once per row', count( $GLOBALS['get_posts'] ), 1 );
check( 'and asked for ids of published posts of the covered types', array( $GLOBALS['get_posts'][0]['fields'], $GLOBALS['get_posts'][0]['post_status'], $GLOBALS['get_posts'][0]['post_type'] ), array( 'ids', 'publish', array( 'page', 'post', 'project' ) ) );
check( 'the posts page, 404 and author archive never reach WordPress', array_values( array_intersect( array( 0 ), $GLOBALS['get_posts'][0]['post__in'] ) ), array() );

echo "\na site with no project type\n";
build_pages();
$GLOBALS['wp_post_types'] = array( 'page', 'post' );
$t = reader()->block( 'top_pages' );
check( 'still available', $t['available'], true );
check( 'post_types says what was covered', $t['post_types'], array( 'page', 'post' ) );
check( 'no project row', in_array( 'project', array_column( $t['rows'], 'type' ), true ), false );
check( 'Filler 07 moves up into the ten', end( $t['rows'] )['title'], 'Filler 07' );

echo "\nno resources table\n";
build_pages();
$wpdb->pdo->exec( 'DROP TABLE wp_independent_analytics_resources' );
$g = reader()->collect();
refused( 'top_pages without resources', $g['top_pages'], 'wp_independent_analytics_resources does not exist' );
check( 'devices unaffected', $g['devices']['available'], true );

// --- 0.1.8: last_12_months and prior_12_months on the same window ---------------

class Fixed_Now_Analytics extends Bsrep_Analytics {
	public $at;
	protected function now( DateTimeZone $timezone ) { return new DateTimeImmutable( $this->at, $timezone ); }
}

function at( $when ) {
	$GLOBALS['analytics_calls'] = array();
	$a     = new Fixed_Now_Analytics();
	$a->at = $when;
	return $a->collect( 12 );
}

echo "\nwindows lined up, November 25, 2026\n";
set_ia_options();
build_tables();
$p = at( '2026-11-25 09:10:00' );
check( 'last_12_months states the monthly window', array( $p['last_12_months']['from'], $p['last_12_months']['to'] ), array( '2025-12-01', '2026-11-25' ) );
check( 'which is the window the blocks use', array( $p['devices']['from'], $p['devices']['to'] ), array( $p['last_12_months']['from'], $p['last_12_months']['to'] ) );
check( 'and the first month of the series', substr( $p['last_12_months']['from'], 0, 7 ), $p['monthly'][0]['month'] );
check( 'iawp_analytics() is asked for exactly that window', in_array( '2025-12-01..2026-11-25', $GLOBALS['analytics_calls'], true ), true );
check( 'the figures still come from it', array( $p['last_12_months']['views'], $p['last_12_months']['visitors'], $p['last_12_months']['sessions'] ), array( 10, 4, 6 ) );
check( 'prior_12_months is the same window a year earlier', array( $p['prior_12_months']['from'], $p['prior_12_months']['to'] ), array( '2024-12-01', '2025-11-25' ) );
check( 'iawp_analytics() is asked for that too', in_array( '2024-12-01..2025-11-25', $GLOBALS['analytics_calls'], true ), true );
check( 'nothing asks for the old rolling window', in_array( '2025-11-25..2026-11-25', $GLOBALS['analytics_calls'], true ), false );

echo "\non the first of a month, just after midnight\n";
$p = at( '2026-12-01 00:30:00' );
check( 'last_12_months', array( $p['last_12_months']['from'], $p['last_12_months']['to'] ), array( '2026-01-01', '2026-12-01' ) );
check( 'prior_12_months', array( $p['prior_12_months']['from'], $p['prior_12_months']['to'] ), array( '2025-01-01', '2025-12-01' ) );

echo "\non February 29\n";
$p = at( '2028-02-29 12:00:00' );
check( 'last_12_months starts March 1, 2027', $p['last_12_months']['from'], '2027-03-01' );
check( 'prior_12_months ends the day before it, not on it', $p['prior_12_months']['to'], '2027-02-28' );
check( 'prior_12_months starts a year before the current one', $p['prior_12_months']['from'], '2026-03-01' );

echo "\n";
if ( $fail ) {
	printf( "%d failed\n", $fail );
	exit( 1 );
}
echo "all passed\n";
