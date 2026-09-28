<?php
// Exercises how the structure scan chooses which URLs to scan.
//
// On georgiaplanning.org, September 22, 2026, the scan kept the first 600 URLs
// of one list in post-type order. Every slot went to news posts, not one page,
// event or product was scanned, and the result still read "600 of 1,236" as
// though it described the site. Bsrep_Scanner::share() replaces the slice.
// These tests pin that the cap is shared, that small types are scanned in full,
// and that the result says how it was sampled.
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'BSREP_VERSION', 'test' );

require_once dirname( __DIR__ ) . '/includes/class-bsrep-scanner.php';

$fail = 0;
function check( $label, $got, $want ) {
	global $fail;
	if ( $got === $want ) { printf( "  ok    %s\n", $label ); return; }
	$fail++;
	printf( "  FAIL  %s\n        want: %s\n        got : %s\n", $label, var_export( $want, true ), var_export( $got, true ) );
}

function urls( $type, $n ) {
	$out = array();
	for ( $i = 1; $i <= $n; $i++ ) {
		$out[] = array( 'url' => "https://example.org/$type/$i/", 'post_type' => $type, 'post_id' => $i );
	}
	return $out;
}

function types_of( array $urls ) {
	$c = array();
	foreach ( $urls as $u ) { $c[ $u['post_type'] ] = ( $c[ $u['post_type'] ] ?? 0 ) + 1; }
	return $c;
}

echo "a site shaped like georgiaplanning.org\n";
// Posts first, because that is the order get_post_types() hands them over,
// which is exactly the order that starved every other type.
$groups = array(
	'front_page'   => urls( 'front_page', 1 ),
	'post'         => urls( 'post', 600 ),
	'tribe_events' => urls( 'tribe_events', 180 ),
	'product'      => urls( 'product', 12 ),
	'page'         => urls( 'page', 140 ),
);
$r = Bsrep_Scanner::share( $groups, 600 );
$t = types_of( $r['urls'] );
check( 'the cap holds', count( $r['urls'] ), 600 );
check( 'the front page is first', $r['urls'][0]['post_type'], 'front_page' );
check( 'pages are scanned, all of them', $t['page'] ?? 0, 140 );
check( 'products are scanned, all of them', $t['product'] ?? 0, 12 );
check( 'events are scanned, all of them', $t['tribe_events'] ?? 0, 180 );
check( 'posts get the room that is left', $t['post'] ?? 0, 600 - 1 - 140 - 12 - 180 );
check( 'kept counts match what was queued', $r['kept'], array( 'front_page' => 1, 'post' => 267, 'tribe_events' => 180, 'product' => 12, 'page' => 140 ) );
check( 'within a type the given order is kept', $r['urls'][1]['url'], 'https://example.org/post/1/' );
check( 'no URL is queued twice', count( array_unique( array_column( $r['urls'], 'url' ) ) ), 600 );

echo "when every type is bigger than its share\n";
$big = array( 'post' => urls( 'post', 500 ), 'page' => urls( 'page', 500 ), 'product' => urls( 'product', 500 ) );
$t   = types_of( Bsrep_Scanner::share( $big, 600 )['urls'] );
check( 'each gets an equal share', $t, array( 'post' => 200, 'page' => 200, 'product' => 200 ) );

echo "when everything fits\n";
$small = array( 'front_page' => urls( 'front_page', 1 ), 'page' => urls( 'page', 28 ) );
$r     = Bsrep_Scanner::share( $small, 600 );
check( 'nothing is dropped', count( $r['urls'] ), 29 );
check( 'an empty type is harmless', count( Bsrep_Scanner::share( array( 'post' => array(), 'page' => urls( 'page', 3 ) ), 600 )['urls'] ), 3 );
check( 'a zero cap queues nothing', Bsrep_Scanner::share( $groups, 0 )['urls'], array() );
check( 'no groups at all queues nothing', Bsrep_Scanner::share( array(), 600 )['urls'], array() );

echo "the result says how it was sampled\n";
$pages = array(
	array( 'url' => 'https://example.org/', 'post_type' => 'front_page', 'status' => 200, 'h1_count' => 1 ),
	array( 'url' => 'https://example.org/page/1/', 'post_type' => 'page', 'status' => 200, 'h1_count' => 1 ),
);
$sampled = array(
	'front_page' => array( 'published' => 1, 'queued' => 1 ),
	'page'       => array( 'published' => 140, 'queued' => 1 ),
	'product'    => array( 'published' => 12, 'queued' => 0 ),
);
$agg = Bsrep_Scanner::aggregate( $pages, array( 'urls_found' => 153, 'urls_dropped' => 151, 'sampled' => $sampled ) );
check( 'a shared scan is labelled shared', $agg['sampling'] ?? null, 'shared' );
check( 'the per-type sample is carried', $agg['sampled_by_type'] ?? null, $sampled );
check( 'a sampled type with no scanned pages still has a row', $agg['by_post_type']['product']['pages'] ?? null, 0 );
$old = Bsrep_Scanner::aggregate( $pages, array( 'urls_found' => 2 ) );
check( 'an old-style result is not labelled shared', array_key_exists( 'sampling', $old ), false );

echo "\n";
if ( $fail ) { printf( "%d failed\n", $fail ); exit( 1 ); }
echo "all passed\n";
