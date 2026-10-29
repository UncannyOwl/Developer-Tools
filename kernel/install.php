<?php
/**
 * Snapshot builder: installs WordPress into a scratch database and activates the profile's plugins.
 *
 * Runs in its own PHP process (see Snapshot::build). Uses core's installer, then activates plugins in
 * the same install-mode process, the way wp-browser v3's isolated install did.
 *
 * Usage: php install.php <base64 JSON config>
 */

error_reporting( E_ALL & ~E_DEPRECATED );

if ( empty( $argv[1] ) ) {
	fwrite( STDERR, "Usage: php install.php <base64 JSON config>\n" );
	exit( 1 );
}

$GLOBALS['wp_test_kernel_config'] = json_decode( (string) base64_decode( $argv[1], true ), true );

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
	require_once ABSPATH . WPINC . '/class-wp-site-query.php';
	require_once ABSPATH . WPINC . '/class-wp-network-query.php';
	require_once ABSPATH . WPINC . '/ms-blogs.php';
	require_once ABSPATH . WPINC . '/ms-settings.php';
}

foreach ( $wp_test_kernel_plugins as $wp_test_kernel_plugin ) {
	echo ( $wp_test_kernel_multisite ? 'Network-activating ' : 'Activating ' ) . $wp_test_kernel_plugin . PHP_EOL;

	$wp_test_kernel_result = activate_plugin( $wp_test_kernel_plugin, '', $wp_test_kernel_multisite, false );

	// WordPress activates a plugin that prints during activation and only reports the output, as
	// wp-admin does; so does the kernel (wp-browser ignored it), showing what was printed.
	if ( is_wp_error( $wp_test_kernel_result ) && 'unexpected_output' === $wp_test_kernel_result->get_error_code() ) {
		fwrite( STDERR, 'Note: ' . $wp_test_kernel_plugin . ' printed ' . strlen( (string) $wp_test_kernel_result->get_error_data() ) . ' characters while activating:' . PHP_EOL . wp_strip_all_tags( (string) $wp_test_kernel_result->get_error_data() ) . PHP_EOL );
		continue;
	}

	if ( is_wp_error( $wp_test_kernel_result ) ) {
		fwrite( STDERR, $wp_test_kernel_result->get_error_message() . PHP_EOL );
		if ( 'plugin_not_found' === $wp_test_kernel_result->get_error_code() ) {
			fwrite( STDERR, sprintf( 'The kernel loads plugins from %1$s, the folder holding the plugin under test. Put %2$s there (a symlink to the copy in your WordPress install works), or set WP_TEST_KERNEL_PLUGINS_DIR.', WP_PLUGIN_DIR, dirname( $wp_test_kernel_plugin ) ) . PHP_EOL );
		}
		// unexpected_output carries what the plugin printed: show it, it is the whole diagnosis.
		$wp_test_kernel_data = $wp_test_kernel_result->get_error_data();
		if ( is_string( $wp_test_kernel_data ) && '' !== $wp_test_kernel_data ) {
			fwrite( STDERR, $wp_test_kernel_data . PHP_EOL );
		}
		exit( 1 );
	}
}

exit( 0 );
