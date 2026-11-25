<?php
// Pins where Bsrep_Push sends (0.1.11).
//
// A new install sends to Biscuit's receiver with nothing typed. A site where
// someone cleared the field and saved sends nothing. A saved address wins over
// the default. get_option() returns its default only when the option does not
// exist, which is the whole mechanism, so the stub reproduces that exactly.
define( 'ABSPATH', dirname( __DIR__ ) . '/' );

$GLOBALS['opts'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['opts'] ) ? $GLOBALS['opts'][ $k ] : $d; }

require_once dirname( __DIR__ ) . '/includes/class-bsrep-push.php';

$fail = 0;
function check( $label, $got, $want ) {
	global $fail;
	if ( $got === $want ) { printf( "  ok    %s\n", $label ); return; }
	$fail++;
	printf( "  FAIL  %s\n        want: %s\n        got : %s\n", $label, var_export( $want, true ), var_export( $got, true ) );
}

$receiver = 'https://biscuitstudios.com/wp-json/bsrep-receiver/v1/report';

check( 'default is the live receiver route', Bsrep_Push::DEFAULT_ENDPOINT, $receiver );
check( 'never saved: sends to the receiver', Bsrep_Push::endpoint(), $receiver );

$GLOBALS['opts']['bsrep_endpoint_url'] = '';
check( 'cleared and saved: sends nothing', Bsrep_Push::endpoint(), '' );

$GLOBALS['opts']['bsrep_endpoint_url'] = 'https://example.test/wp-json/bsrep-receiver/v1/report';
check( 'a saved address wins over the default', Bsrep_Push::endpoint(), 'https://example.test/wp-json/bsrep-receiver/v1/report' );

define( 'BSREP_ENDPOINT', 'https://constant.test/x' );
check( 'the BSREP_ENDPOINT constant still wins', Bsrep_Push::endpoint(), 'https://constant.test/x' );

echo "\n";
if ( $fail ) {
	printf( "%d failed\n", $fail );
	exit( 1 );
}
echo "all passed\n";
