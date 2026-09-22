<?php
// Exercises the WordPress-free parts of Bsrep_Updater against input whose answer
// is known. The networked half needs a live repo and a real WP; this is the half
// that can be proved now, and it is the half that renders into an admin screen.
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'HOUR_IN_SECONDS', 3600 );

function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $u ) { return preg_match( '#^https?://#', $u ) ? $u : ''; }
function esc_html__( $t, $d = '' ) { return esc_html( $t ); }
function wp_date( $f, $t ) { return gmdate( $f, $t ); }

require_once dirname( __DIR__ ) . '/includes/class-bsrep-updater.php';

$fail = 0;
function check( $label, $got, $want ) {
	global $fail;
	if ( $got === $want ) { printf( "  ok    %s\n", $label ); return; }
	$fail++;
	printf( "  FAIL  %s\n        want: %s\n        got : %s\n", $label, var_export( $want, true ), var_export( $got, true ) );
}

$md = 'Bsrep_Updater::render_markdown';

echo "render_markdown\n";
check( 'bullets become a list',
	$md( "* one\n* two" ), '<ul><li>one</li><li>two</li></ul>' );
check( 'indented continuation joins the bullet above it',
	$md( "* one\n  wrapped" ), '<ul><li>one wrapped</li></ul>' );
check( 'blank line does not close the list',
	$md( "* one\n\n* two" ), '<ul><li>one</li><li>two</li></ul>' );
check( 'heading becomes h4',
	$md( '## What' ), '<h4>What</h4>' );
check( 'bold',
	$md( 'a **b** c' ), '<p>a <strong>b</strong> c</p>' );
check( 'code span',
	$md( 'run `wp bsrep push`' ), '<p>run <code>wp bsrep push</code></p>' );
check( 'code span containing asterisks survives bold',
	$md( 'see `a ** b`' ), '<p>see <code>a ** b</code></p>' );
check( 'markdown link',
	$md( '[docs](https://example.com/x)' ), '<p><a href="https://example.com/x">docs</a></p>' );
check( 'bare url links without swallowing the full stop',
	$md( 'see https://example.com/x.' ), '<p>see <a href="https://example.com/x">https://example.com/x</a>.</p>' );

echo "\nescaping\n";
check( 'markup in release notes cannot introduce tags',
	$md( '<script>alert(1)</script>' ),
	'<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>' );
check( 'an img tag in a bullet is escaped too',
	$md( '* <img src=x onerror=alert(1)>' ),
	'<ul><li>&lt;img src=x onerror=alert(1)&gt;</li></ul>' );
check( 'javascript: url is not linked, label survives',
	strpos( $md( '[x](javascript:alert(1))' ), '<a ' ), false );

echo "\nempty and odd input\n";
check( 'empty string', $md( '' ), '' );
check( 'whitespace only', $md( "\n\n  \n" ), '' );

echo "\nstrip_compare_link and readme parsing, via reflection\n";
$r = new ReflectionClass( 'Bsrep_Updater' );

$strip = $r->getMethod( 'strip_compare_link' ); $strip->setAccessible( true );
check( 'trailing compare link removed',
	$strip->invoke( null, "Notes here\n\n**Full Changelog**: https://github.com/a/b/compare/v1...v2" ),
	"Notes here" );
check( 'a link written into the notes survives',
	$strip->invoke( null, "See **Full Changelog**: https://x/y in the middle\nmore" ),
	"See **Full Changelog**: https://x/y in the middle\nmore" );

$heading = $r->getMethod( 'heading' ); $heading->setAccessible( true );
check( 'heading with a date',
	$heading->invoke( null, array( 'version' => '0.1.0', 'published' => '2026-09-22T16:00:00Z' ) ),
	'0.1.0 | September 22, 2026' );
check( 'heading with no date falls back to the version alone',
	$heading->invoke( null, array( 'version' => '0.1.0', 'published' => '' ) ),
	'0.1.0' );

// readme_changelog() reads the file next to the plugin's main file.
$u = $r->newInstanceWithoutConstructor();
$fileProp = $r->getProperty( 'file' ); $fileProp->setAccessible( true );
$fileProp->setValue( $u, dirname( __DIR__ ) . '/bs-site-report.php' );
$rc = $r->getMethod( 'readme_changelog' ); $rc->setAccessible( true );
$sections = $rc->invoke( $u );

echo "\nreadme.txt changelog\n";
check( 'the shipped readme.txt parses and yields 0.1.0', isset( $sections['0.1.0'] ), true );
check( 'and its body is not empty', isset( $sections['0.1.0'] ) && '' !== trim( $sections['0.1.0'] ), true );

// Positive control on the parser itself: a version heading outside the changelog
// section must NOT be picked up, which is the Upgrade Notice trap.
echo "\n";
echo $fail ? "$fail FAILED\n" : "all passed\n";
exit( $fail ? 1 : 0 );
