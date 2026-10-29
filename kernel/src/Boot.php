<?php

namespace Uncanny_Owl\Wp_Test_Kernel;

use Uncanny_Owl\Wp_Test_Kernel\TestCase\WPTestCase;

/**
 * Boots WordPress for a test process.
 *
 * Usage from a plugin's PHPUnit bootstrap file:
 *
 *     $core_bootstrap = \Uncanny_Owl\Wp_Test_Kernel\Boot::prepare( array( ... ) );
 *     require $core_bootstrap;            // WordPress loads in the bootstrap file's scope.
 *     \Uncanny_Owl\Wp_Test_Kernel\Boot::finish();
 */
final class Boot {

	/** @var Config|null */
	private static $config;

	/**
	 * Restore this worker's database and stage WordPress' test constants.
	 *
	 * @param array<string, mixed> $settings See Config::from_array().
	 *
	 * @return string Path of core-phpunit's bootstrap.php, to be required by the caller.
	 */
	public static function prepare( array $settings ) {

		$config       = Config::from_array( $settings );
		self::$config = $config;

		foreach ( (array) $config->get( 'env' ) as $name => $value ) {
			putenv( $name . '=' . $value );
			$_ENV[ $name ]    = $value;
			$_SERVER[ $name ] = $value;
		}

		require_once dirname( __DIR__ ) . '/functions.php';

		$snapshot = Snapshot::ensure( $config );
		$db_name  = Worker_Database::prepare( $config, $snapshot );

		$GLOBALS['wp_test_kernel_config'] = $config->to_wordpress_config( $db_name );

		// The snapshot replaces core's per-run installer.
		putenv( 'WP_TESTS_SKIP_INSTALL=1' );

		if ( $config->is_multisite() ) {
			putenv( 'WP_MULTISITE=1' );
		}

		define( 'WP_TESTS_CONFIG_FILE_PATH', dirname( __DIR__ ) . '/wp-tests-config.php' );

		$GLOBALS['wp_tests_options']['active_plugins'] = $config->plugins();

		// Plugins register their own Composer autoloaders while they load, and Composer prepends.
		// Core's bootstrap then probes PHPUnit classes (phpunit6/compat.php), so the kernel's
		// autoloader must be back in front as soon as plugins have loaded.
		require_once dirname( __DIR__ ) . '/core-phpunit/includes/functions.php';
		tests_add_filter( 'plugins_loaded', array( self::class, 'restore_autoloader_priority' ), PHP_INT_MIN );

		require_once dirname( __DIR__ ) . '/core-phpunit/yoast-phpunit-polyfills-autoload-stub.php';

		return dirname( __DIR__ ) . '/core-phpunit/includes/bootstrap.php';
	}

	/**
	 * After WordPress has loaded: keep the old base-class name working and load shared test helpers.
	 *
	 * @return void
	 */
	public static function finish() {

		self::restore_autoloader_priority();

		if ( ! class_exists( 'Codeception\TestCase\WPTestCase', false ) ) {
			class_alias( WPTestCase::class, 'Codeception\TestCase\WPTestCase' );
		}

		foreach ( (array) self::config()->get( 'helpers' ) as $file ) {
			require_once $file;
		}
	}

	/**
	 * Put the kernel's Composer autoloader first again, so PHPUnit 13 classes win even when a
	 * plugin's own vendor/ holds another PHPUnit (a dev install).
	 *
	 * @return void
	 */
	public static function restore_autoloader_priority() {
		$loader = self::config()->get( 'autoloader' );
		if ( $loader instanceof \Composer\Autoload\ClassLoader ) {
			$loader->unregister();
			$loader->register( true );
		}
	}

	/**
	 * @return Config
	 */
	public static function config() {
		if ( null === self::$config ) {
			throw new \RuntimeException( 'wp-test-kernel has not been prepared.' );
		}

		return self::$config;
	}
}
