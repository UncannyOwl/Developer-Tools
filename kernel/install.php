<?php
/**
 * Snapshot builder: installs WordPress into a scratch database and activates the profile's plugins.
 *
 * Runs in its own PHP process (see Snapshot::build). Uses core's installer, then activates plugins:
 * a single site's in this same install-mode process, the way wp-browser v3's isolated install did;
 * a network's in a second process booted as the network just installed (network-activate.php says
 * why).
 *
 * Usage: php install.php <base64 JSON config>
 */

error_reporting( E_ALL & ~E_DEPRECATED );

if ( empty( $argv[1] ) ) {
	fwrite( STDERR, "Usage: php install.php <base64 JSON config>\n" );
	exit( 1 );
}

$wp_test_kernel_payload           = $argv[1];
$GLOBALS['wp_test_kernel_config'] = json_decode( (string) base64_decode( $wp_test_kernel_payload, true ), true );

if ( ! is_array( $GLOBALS['wp_test_kernel_config'] ) ) {
	fwrite( STDERR, "install.php: invalid configuration payload.\n" );
	exit( 1 );
}

$wp_test_kernel_multisite = ! empty( $GLOBALS['wp_test_kernel_config']['multisite'] );
$wp_test_kernel_plugins   = (array) $GLOBALS['wp_test_kernel_config']['plugins'];

// core install.php reads its config path and flags from $argv.
$argv = array( $argv[0], __DIR__ . '/wp-tests-config.php', $wp_test_kernel_multisite ? 'run_ms_tests' : 'no_ms_tests', 'no_core_tests' );

require __DIR__ . '/core-phpunit/includes/install.php';

if ( $wp_test_kernel_multisite ) {
	// WordPress booted here as a single site (wp-settings.php defined MULTISITE as false), and stays one.
	passthru( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/network-activate.php' ) . ' ' . escapeshellarg( $wp_test_kernel_payload ), $wp_test_kernel_exit );
	exit( (int) $wp_test_kernel_exit );
}

require_once __DIR__ . '/activate-plugins.php';

exit( (int) wp_test_kernel_activate_plugins( $wp_test_kernel_plugins, false ) );
