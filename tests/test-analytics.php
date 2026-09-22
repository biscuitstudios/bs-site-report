<?php
// Exercises Bsrep_Analytics against a vendor that misbehaves.
//
// The one rule in CLAUDE.md is that anything not measured is null with a reason,
// never a zero. Independent Analytics is another vendor's code, bundling its own
// Laravel database layer and its own PDO connection, so it can throw where $wpdb
// would not. On September 22, 2026 it did exactly that on Biscuit Dev and took
// Bsrep_Collector::build() down with it, killing the payload, the download button
// and the scheduled push on a site where every other section was fine.
//
// These tests pin both halves of the fix: a throwing vendor degrades rather than
// fatals, and an unreadable vendor shape never becomes zeros.
define( 'ABSPATH', dirname( __DIR__ ) . '/' );

function wp_timezone() { return new DateTimeZone( 'UTC' ); }

require_once dirname( __DIR__ ) . '/includes/class-bsrep-analytics.php';

$fail = 0;
function check( $label, $got, $want ) {
	global $fail;
	if ( $got === $want ) { printf( "  ok    %s\n", $label ); return; }
	$fail++;
	printf( "  FAIL  %s\n        want: %s\n        got : %s\n", $label, var_export( $want, true ), var_export( $got, true ) );
}

// The vendor function is defined once; this switch decides how it behaves.
$GLOBALS['iawp_mode'] = 'ok';

function iawp_analytics( $from, $to ) {
	switch ( $GLOBALS['iawp_mode'] ) {
		case 'throw':
			throw new PDOException( 'SQLSTATE[HY000] [2002] No such file or directory' );
		case 'garbage':
			return 'not an array';
		case 'null':
			return null;
	}
	return array( 'views' => 10, 'visitors' => 4, 'sessions' => 6 );
}

function iawp_top_posts( $args ) {
	if ( 'throw' === $GLOBALS['iawp_mode'] ) {
		throw new PDOException( 'down' );
	}
	return array();
}

$a = new Bsrep_Analytics();

echo "a vendor that throws\n";
$GLOBALS['iawp_mode'] = 'throw';
$got = null;
$threw = false;
try { $got = $a->collect( 2 ); } catch ( \Throwable $e ) { $threw = true; }
check( 'collect() does not throw', $threw, false );
check( 'section reports unavailable', $got['available'] ?? null, false );
check( 'a reason is attached', isset( $got['reason'] ) && '' !== $got['reason'], true );
check( 'the reason names the vendor exception', (bool) strpos( $got['reason'] ?? '', 'PDOException' ), true );
check( 'no views key is invented', array_key_exists( 'last_12_months', $got ), false );

echo "\nan unreadable vendor shape is not a quiet month\n";
foreach ( array( 'garbage', 'null' ) as $mode ) {
	$GLOBALS['iawp_mode'] = $mode;
	$threw = false;
	try { $got = $a->collect( 2 ); } catch ( \Throwable $e ) { $threw = true; }
	check( "$mode: collect() does not throw", $threw, false );
	check( "$mode: reports unavailable rather than zeros", $got['available'] ?? null, false );
	check( "$mode: no zero totals are published", array_key_exists( 'totals_of_series', $got ), false );
}

echo "\nthe happy path still works\n";
$GLOBALS['iawp_mode'] = 'ok';
$got = $a->collect( 2 );
check( 'available', $got['available'] ?? null, true );
check( 'monthly series has one entry per month', count( $got['monthly'] ), 2 );
check( 'views are read from the vendor', $got['monthly'][0]['views'], 10 );
check( 'visitors are read from the vendor', $got['monthly'][0]['visitors'], 4 );
check( 'ai_referrals stays honestly unavailable', $got['ai_referrals']['available'], false );

echo "\n";
if ( $fail ) {
	printf( "%d failed\n", $fail );
	exit( 1 );
}
echo "all passed\n";
