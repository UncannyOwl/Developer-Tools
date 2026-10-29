<?php
/**
 * WordPress test configuration, filled from the kernel's settings.
 *
 * Required by core-phpunit's bootstrap.php (test process) and install.php (snapshot builder).
 * Mirrors the constants wp-browser v3's WPLoader defined, so results match the old runner.
 */

if ( empty( $GLOBALS['wp_test_kernel_config'] ) || ! is_array( $GLOBALS['wp_test_kernel_config'] ) ) {
	fwrite( STDERR, "wp-test-kernel: configuration missing; boot through Uncanny_Owl\\Wp_Test_Kernel\\Boot.\n" );
	exit( 1 );
}

$wp_test_kernel_config = $GLOBALS['wp_test_kernel_config'];

define( 'ABSPATH', $wp_test_kernel_config['wp_root'] );

// Plugins load from the folder holding the plugin under test, which may be a checkout outside this
// WordPress install (a worktree): that checkout is the copy under test.
define( 'WP_PLUGIN_DIR', $wp_test_kernel_config['plugins_dir'] );

define( 'DB_NAME', $wp_test_kernel_config['db_name'] );
define( 'DB_USER', $wp_test_kernel_config['db_user'] );
define( 'DB_PASSWORD', $wp_test_kernel_config['db_password'] );
define( 'DB_HOST', $wp_test_kernel_config['db_host'] );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );

$table_prefix = $wp_test_kernel_config['table_prefix']; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

define( 'WP_TESTS_DOMAIN', $wp_test_kernel_config['domain'] );
define( 'WP_TESTS_EMAIL', $wp_test_kernel_config['admin_email'] );
define( 'WP_TESTS_TITLE', $wp_test_kernel_config['title'] );
define( 'WP_PHP_BINARY', PHP_BINARY );
define( 'WPLANG', '' );

defined( 'WP_DEBUG' ) || define( 'WP_DEBUG', true );
// null: wp_debug_mode() leaves display_errors as the kernel set it (stderr). WordPress's default (true)
// would display errors on stdout, and a plugin's warning while it loads would then make every later
// header() or setcookie() in a test warn "headers already sent".
defined( 'WP_DEBUG_DISPLAY' ) || define( 'WP_DEBUG_DISPLAY', null );

if ( ! empty( $wp_test_kernel_config['config_file'] ) ) {
	require_once $wp_test_kernel_config['config_file'];
}

unset( $wp_test_kernel_config );
