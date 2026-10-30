<?php
/**
 * Snapshot builder, multisite: network-activates the profile's plugins on the network install.php
 * has just installed.
 *
 * install.php boots WordPress before the network exists, so wp-settings.php defines MULTISITE as
 * false there and is_multisite() stays false for the rest of that process (wp-browser v3 redefined
 * is_multisite() with Patchwork after installing the network; the kernel has no Patchwork).
 * Activating there records each network activation in the main site's options instead of the
 * network's, finds a plugin's Requires Plugins dependency inactive although it was network-activated
 * a moment earlier, and runs every activation hook as single-site code. This process boots
 * WordPress as that network, the way the test process does (core-phpunit's bootstrap.php), and
 * still in install mode, like install.php.
 *
 * Usage: php network-activate.php <base64 JSON config>, run by install.php.
 */

error_reporting( E_ALL & ~E_DEPRECATED );

$GLOBALS['wp_test_kernel_config'] = json_decode( (string) base64_decode( (string) ( $argv[1] ?? '' ), true ), true );

if ( ! is_array( $GLOBALS['wp_test_kernel_config'] ) ) {
	fwrite( STDERR, "network-activate.php: invalid configuration payload.\n" );
	exit( 1 );
}

// Read before wp-tests-config.php, which unsets the configuration once it has defined the constants.
$wp_test_kernel_plugins = (array) $GLOBALS['wp_test_kernel_config']['plugins'];

// What core-phpunit's install.php defines before WordPress loads...
define( 'WP_INSTALLING', true );
define( 'DISABLE_WP_CRON', true );

// ...and what its bootstrap.php defines to run as a network.
define( 'MULTISITE', true );
define( 'SUBDOMAIN_INSTALL', false );
$GLOBALS['base'] = '/';

require_once __DIR__ . '/wp-tests-config.php';
require_once __DIR__ . '/core-phpunit/includes/functions.php';

if ( ! defined( 'WP_DEFAULT_THEME' ) ) {
	define( 'WP_DEFAULT_THEME', 'default' );
}

tests_reset__SERVER();

$PHP_SELF            = '/index.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- as core-phpunit's install.php.
$GLOBALS['PHP_SELF'] = '/index.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- as core-phpunit's install.php.
$_SERVER['PHP_SELF'] = '/index.php';

tests_add_filter( 'wp_die_handler', '_wp_die_handler_filter_exit' );

require_once ABSPATH . 'wp-settings.php';

// install.php's plugins activate with the installer's admin includes loaded; so do these.
require_once ABSPATH . 'wp-admin/includes/upgrade.php';

require_once __DIR__ . '/core-phpunit/includes/mock-mailer.php';
$phpmailer = new MockPHPMailer(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- as core-phpunit's install.php.

require_once __DIR__ . '/activate-plugins.php';

exit( (int) wp_test_kernel_activate_plugins( $wp_test_kernel_plugins, true ) );
