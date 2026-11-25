<?php
// Exercises Bsrep_Seo, the seo.search_engines_allowed block (0.1.10).
//
// Each rule SEOPress uses to noindex a home page gets a fixture with it on and
// one with it off. SEOPRESS_VERSION is a constant and cannot be undefined, so
// the SEOPress-not-active half runs in a second PHP process: this file calls
// itself with the argument "no-seopress".
define( 'ABSPATH', dirname( __DIR__ ) . '/' );

$no_seopress = in_array( 'no-seopress', $argv ?? array(), true );
if ( ! $no_seopress ) {
	define( 'SEOPRESS_VERSION', '10.4' );
}

$GLOBALS['opts']  = array();
$GLOBALS['posts'] = array();
$GLOBALS['meta']  = array();

function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['opts'] ) ? $GLOBALS['opts'][ $k ] : $d; }
function get_post( $id ) { return $GLOBALS['posts'][ (int) $id ] ?? null; }
function get_post_meta( $id, $k, $single = false ) { return $GLOBALS['meta'][ (int) $id ][ $k ] ?? ''; }

require_once dirname( __DIR__ ) . '/includes/class-bsrep-seo.php';

$fail = 0;
function check( $label, $got, $want ) {
	global $fail;
	if ( $got === $want ) { printf( "  ok    %s\n", $label ); return; }
	$fail++;
	printf( "  FAIL  %s\n        want: %s\n        got : %s\n", $label, var_export( $want, true ), var_export( $got, true ) );
}

function post( $id, $type = 'page', $password = '', $title = 'Home' ) {
	return (object) array( 'ID' => $id, 'post_type' => $type, 'post_password' => $password, 'post_title' => $title );
}

// A healthy site: public, static front page 10, SEOPress titles module on,
// nothing set to noindex.
function reset_site() {
	$GLOBALS['opts'] = array(
		'blog_public'    => '1',
		'show_on_front'  => 'page',
		'page_on_front'  => '10',
		'page_for_posts' => '20',
		'seopress_toggle' => array( 'toggle-titles' => '1' ),
		'seopress_titles_option_name' => array(
			'seopress_titles_single_titles' => array( 'post' => array( 'noindex' => '1' ) ),
		),
	);
	$GLOBALS['posts'] = array( 10 => post( 10 ), 20 => post( 20, 'page', '', 'Blog' ) );
	$GLOBALS['meta']  = array();
}

function block() {
	return ( new Bsrep_Seo() )->collect()['search_engines_allowed'];
}

if ( $no_seopress ) {
	echo "SEOPress not active\n";
	reset_site();
	$b = block();
	check( 'available', $b['available'], true );
	check( 'home_noindex is null, not false', $b['home_noindex'], null );
	check( 'allowed on blog_public alone', $b['allowed'], true );
	check( 'source says SEOPress is not active', (bool) strpos( $b['source'], 'SEOPress is not active' ), true );
	$GLOBALS['opts']['blog_public'] = '0';
	check( 'Discourage still blocks without SEOPress', block()['allowed'], false );
	exit( $fail ? 1 : 0 );
}

echo "Shape\n";
reset_site();
$b = block();
check( 'keys', array_keys( $b ), array( 'available', 'source', 'allowed', 'blog_public', 'home_noindex', 'blocked_by', 'checked_at' ) );
check( 'available', $b['available'], true );
check( 'allowed on a healthy site', $b['allowed'], true );
check( 'blog_public is a bool', $b['blog_public'], true );
check( 'home_noindex is false, not null, with SEOPress on', $b['home_noindex'], false );
check( 'nothing blocking', $b['blocked_by'], array() );
check( 'checked_at is ISO 8601 UTC', (bool) preg_match( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/', $b['checked_at'] ), true );
check( 'source names SEOPress and its version', (bool) strpos( $b['source'], 'SEOPress 10.4' ), true );

echo "blog_public\n";
reset_site();
$GLOBALS['opts']['blog_public'] = '0';
$b = block();
check( 'Discourage on: not allowed', $b['allowed'], false );
check( 'Discourage on: blog_public false', $b['blog_public'], false );
check( 'Discourage on: says which setting', (bool) strpos( $b['blocked_by'][0] ?? '', 'Discourage search engines' ), true );
check( 'Discourage on does not change home_noindex', $b['home_noindex'], false );
$GLOBALS['opts']['blog_public'] = 1;
check( 'integer 1 reads as public', block()['blog_public'], true );
unset( $GLOBALS['opts']['blog_public'] );
$b = block();
check( 'missing blog_public: unavailable', $b['available'], false );
check( 'missing blog_public: no allowed key', array_key_exists( 'allowed', $b ), false );
check( 'missing blog_public: reason', (bool) strpos( $b['reason'], 'blog_public option is missing' ), true );
$GLOBALS['opts']['blog_public'] = '2';
$b = block();
check( 'unknown blog_public value: unavailable, not guessed', $b['available'], false );
check( 'unknown value: reason quotes it', (bool) strpos( $b['reason'], '"2"' ), true );

echo "SEOPress module switch\n";
reset_site();
$GLOBALS['meta'][10]['_seopress_robots_index'] = 'yes';
check( 'front page noindex with module on: blocked', block()['home_noindex'], true );
$GLOBALS['opts']['seopress_toggle'] = array( 'toggle-titles' => '0' );
$b = block();
check( 'titles module off: SEOPress prints nothing, home_noindex false', $b['home_noindex'], false );
check( 'titles module off: allowed', $b['allowed'], true );
check( 'titles module off: source says so', (bool) strpos( $b['source'], 'module is off' ), true );
unset( $GLOBALS['opts']['seopress_toggle'] );
check( 'no toggle option at all: treated as off', block()['home_noindex'], false );

echo "Static front page\n";
reset_site();
$GLOBALS['meta'][10]['_seopress_robots_index'] = 'yes';
$b = block();
check( 'front page meta yes: not allowed', $b['allowed'], false );
check( 'front page meta yes: names the page', (bool) strpos( $b['blocked_by'][0] ?? '', '"Home"' ), true );
reset_site();
$GLOBALS['meta'][10]['_seopress_robots_index'] = 'no';
check( 'front page meta other than yes: allowed', block()['allowed'], true );
reset_site();
$GLOBALS['meta'][99]['_seopress_robots_index'] = 'yes';
check( 'another page noindexed: front page still allowed', block()['allowed'], true );
reset_site();
$GLOBALS['opts']['seopress_titles_option_name']['seopress_titles_noindex'] = '1';
$b = block();
check( 'global noindex: blocked', $b['home_noindex'], true );
check( 'global noindex: says so', (bool) strpos( $b['blocked_by'][0] ?? '', 'whole site' ), true );
reset_site();
$GLOBALS['opts']['seopress_titles_option_name']['seopress_titles_single_titles']['page'] = array( 'noindex' => '1' );
check( 'noindex on the page post type: blocked', block()['home_noindex'], true );
reset_site();
check( 'noindex on posts only: page front still allowed', block()['allowed'], true );
reset_site();
$GLOBALS['posts'][10] = post( 10, 'page', 'secret' );
check( 'password protected front page: blocked', block()['home_noindex'], true );
reset_site();
$GLOBALS['meta'][20]['_seopress_robots_index'] = 'yes';
check( 'posts page noindex with a static front: front still allowed', block()['allowed'], true );
reset_site();
$GLOBALS['meta'][10]['_seopress_robots_index'] = 'yes';
$GLOBALS['opts']['blog_public'] = '0';
check( 'both blocked: both named', count( block()['blocked_by'] ), 2 );
reset_site();
unset( $GLOBALS['posts'][10] );
$b = block();
check( 'front page set to a missing post: unavailable', $b['available'], false );
check( 'missing front page: reason names the id', (bool) strpos( $b['reason'], 'post 10' ), true );

echo "Latest posts on the front\n";
reset_site();
$GLOBALS['opts']['show_on_front'] = 'posts';
$GLOBALS['meta'][10]['_seopress_robots_index'] = 'yes';
check( 'latest posts: old front page meta does not count', block()['allowed'], true );
$GLOBALS['meta'][20]['_seopress_robots_index'] = 'yes';
check( 'latest posts: posts page meta counts, as in SEOPress', block()['home_noindex'], true );
reset_site();
$GLOBALS['opts']['show_on_front']  = 'posts';
$GLOBALS['opts']['page_for_posts'] = '0';
check( 'latest posts, no posts page: allowed', block()['allowed'], true );
$GLOBALS['opts']['seopress_titles_option_name']['seopress_titles_noindex'] = '1';
check( 'latest posts: global noindex counts', block()['home_noindex'], true );
reset_site();
$GLOBALS['opts']['page_on_front'] = '0';
$GLOBALS['meta'][10]['_seopress_robots_index'] = 'yes';
check( 'static front with page 0 is latest posts', block()['allowed'], true );

echo "SEOPress not active (second process)\n";
passthru( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' no-seopress', $code );
check( 'SEOPress-not-active suite passed', $code, 0 );

echo "\n";
if ( $fail ) {
	printf( "%d failed\n", $fail );
	exit( 1 );
}
echo "all passed\n";
