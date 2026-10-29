<?php

namespace Uncanny_Owl\Wp_Test_Kernel;

use RuntimeException;

/**
 * Kernel settings: what the consuming plugin passes in, with environment overrides for CI and local machines.
 */
final class Config {

	const KERNEL_VERSION = '0.1.0';

	/** @var array<string, mixed> */
	private $values;

	/**
	 * @param array<string, mixed> $values
	 */
	private function __construct( array $values ) {
		$this->values = $values;
	}

	/**
	 * Build the configuration from the plugin's bootstrap array plus environment variables.
	 *
	 * Environment overrides: WP_ROOT_FOLDER, WP_TEST_KERNEL_WP_VERSION, WP_TEST_KERNEL_DB_HOST,
	 * WP_TEST_KERNEL_DB_USER, WP_TEST_KERNEL_DB_PASSWORD, WP_TEST_KERNEL_DB_NAME,
	 * WP_TEST_KERNEL_PLUGINS_DIR, WP_TESTS_DOMAIN, WP_MULTISITE. WP_TEST_KERNEL_DOWNLOADS sets where
	 * WordPress releases are kept.
	 *
	 * @param array<string, mixed> $input
	 *
	 * @return self
	 */
	public static function from_array( array $input ) {

		$defaults = array(
			'plugin_root'        => '',
			// A WordPress install to test with; '' is the kernel's own WordPress, release wp_version.
			'wp_root'            => '',
			// The wordpress.org release the kernel's own WordPress is, such as 7.1.4.
			'wp_version'         => '',
			// Where WordPress loads plugins from; '' is the folder holding plugin_root, so a checkout
			// outside the WordPress install (a worktree) is the copy under test.
			'plugins_dir'        => '',
			'plugins'            => array(),
			'config_file'        => null,
			'table_prefix'       => 'wptests_',
			'domain'             => 'example.org',
			'admin_email'        => 'admin@example.org',
			'title'              => 'Test',
			'tests_dir'          => '',
			'data_dir'           => '',
			'output_dir'         => '',
			'helpers'            => array(),
			'env'                => array(),
			'snapshot_key_files' => array(),
			'db_host'            => '127.0.0.1:3306',
			'db_user'            => 'root',
			'db_password'        => '',
			'db_name'            => 'wp_test_kernel',
			'multisite'          => false,
			'cache_dir'          => '',
			'autoloader'         => null,
			'profile'            => '',
			'legacy_notices'     => false,
			// Print WordPress database errors into the test output (core's test case does); otherwise
			// they go to PHP's error log only.
			'show_db_errors'     => false,
			// Callables run after every test, once WordPress has rolled the test back: for a plugin's own
			// in-process caches that outlive a rollback (the kernel cannot know them).
			'after_each_test'    => array(),
		);

		$values = array_merge( $defaults, $input );

		$overrides = array(
			'wp_root'     => 'WP_ROOT_FOLDER',
			'wp_version'  => 'WP_TEST_KERNEL_WP_VERSION',
			'db_host'     => 'WP_TEST_KERNEL_DB_HOST',
			'db_user'     => 'WP_TEST_KERNEL_DB_USER',
			'db_password' => 'WP_TEST_KERNEL_DB_PASSWORD',
			'db_name'     => 'WP_TEST_KERNEL_DB_NAME',
			'plugins_dir' => 'WP_TEST_KERNEL_PLUGINS_DIR',
			'domain'      => 'WP_TESTS_DOMAIN',
		);

		foreach ( $overrides as $key => $env_name ) {
			$env_value = getenv( $env_name );
			if ( false !== $env_value && '' !== $env_value ) {
				$values[ $key ] = $env_value;
			}
		}

		if ( '1' === getenv( 'WP_MULTISITE' ) ) {
			$values['multisite'] = true;
		}

		$show_db_errors = getenv( 'WP_TEST_KERNEL_SHOW_DB_ERRORS' );
		if ( false !== $show_db_errors && '' !== $show_db_errors ) {
			$values['show_db_errors'] = ( '1' === $show_db_errors );
		}

		// WP_TEST_KERNEL_LEGACY_NOTICES=1 / 0 overrides the plugin's choice either way.
		$legacy = getenv( 'WP_TEST_KERNEL_LEGACY_NOTICES' );
		if ( false !== $legacy && '' !== $legacy ) {
			$values['legacy_notices'] = ( '1' === $legacy );
		}

		$profile = getenv( 'WP_TEST_KERNEL_PROFILE' );
		if ( false !== $profile && '' !== $profile ) {
			$values['profile'] = $profile;
		}
		$values['profile'] = (string) preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $values['profile'] ) );

		if ( '' === $values['cache_dir'] ) {
			$values['cache_dir'] = rtrim( (string) $values['plugin_root'], '/' ) . '/tests/_output/kernel';
		}

		$config = new self( $values );
		$config->validate();

		return $config;
	}

	/**
	 * @return void
	 */
	private function validate() {

		if ( $this->uses_kernel_wordpress() ) {
			if ( 1 !== preg_match( '/^\d+\.\d+(\.\d+)?$/', (string) $this->values['wp_version'] ) ) {
				throw new RuntimeException( 'No WordPress to test with: set wp_version (a wordpress.org release such as 7.1.4) for the kernel\'s own WordPress, or wp_root / WP_ROOT_FOLDER to a WordPress install.' );
			}
		} elseif ( ! is_readable( $this->wp_root() . 'wp-settings.php' ) ) {
			throw new RuntimeException( 'WordPress not found at "' . $this->wp_root() . '". Point WP_ROOT_FOLDER at a WordPress install, or leave it unset to use the kernel\'s own WordPress.' );
		}

		if ( '' === $this->plugin_root() || ! is_dir( $this->plugin_root() ) ) {
			throw new RuntimeException( 'plugin_root is missing or not a directory.' );
		}
	}

	/**
	 * @param string $key
	 *
	 * @return mixed
	 */
	public function get( $key ) {
		return $this->values[ $key ] ?? null;
	}

	/**
	 * @return string WordPress root with a trailing slash: the named install, or the kernel's own
	 *                WordPress, one copy per checkout in the machine's downloads folder. Never inside
	 *                the checkout: code that tells WordPress, plugins and themes apart by path would
	 *                read core files under the plugins folder as the plugin's.
	 */
	public function wp_root() {
		if ( $this->uses_kernel_wordpress() ) {
			$checkout = substr( md5( (string) ( realpath( $this->plugin_root() ) ?: $this->plugin_root() ) ), 0, 10 );

			return $this->downloads_dir() . '/wordpress-' . $this->values['wp_version'] . '-' . $checkout . '/';
		}

		return rtrim( (string) $this->values['wp_root'], '/' ) . '/';
	}

	/**
	 * Whether the tests run on the kernel's own WordPress (no install named).
	 *
	 * @return bool
	 */
	public function uses_kernel_wordpress() {
		return '' === trim( (string) $this->values['wp_root'] );
	}

	/**
	 * Where wordpress.org releases are kept, once per machine: WP_TEST_KERNEL_DOWNLOADS, else
	 * $XDG_CACHE_HOME/wp-test-kernel, else ~/.cache/wp-test-kernel.
	 *
	 * @return string
	 */
	public function downloads_dir() {
		$dir = getenv( 'WP_TEST_KERNEL_DOWNLOADS' );
		if ( false !== $dir && '' !== $dir ) {
			return rtrim( $dir, '/' );
		}

		$cache = getenv( 'XDG_CACHE_HOME' );
		if ( false === $cache || '' === $cache ) {
			$home  = getenv( 'HOME' );
			$cache = ( false === $home || '' === $home ? sys_get_temp_dir() : $home ) . '/.cache';
		}

		return rtrim( $cache, '/' ) . '/wp-test-kernel';
	}

	/**
	 * @return string
	 */
	public function plugin_root() {
		return rtrim( (string) $this->values['plugin_root'], '/' );
	}

	/**
	 * The folder WordPress loads plugins from: the one holding the plugin under test unless set, so a
	 * checkout outside the WordPress install is the copy that runs, and the profile's other plugins
	 * sit beside it.
	 *
	 * @return string
	 */
	public function plugins_dir() {
		$dir = (string) $this->values['plugins_dir'];

		return rtrim( '' === $dir ? dirname( $this->plugin_root() ) : $dir, '/' );
	}

	/**
	 * @return string[]
	 */
	public function plugins() {
		return array_values( (array) $this->values['plugins'] );
	}

	/**
	 * @return bool
	 */
	public function is_multisite() {
		return (bool) $this->values['multisite'];
	}

	/**
	 * @return string
	 */
	public function profile() {
		if ( '' !== $this->values['profile'] ) {
			return $this->values['profile'];
		}

		return $this->is_multisite() ? 'multisite' : 'single';
	}

	/**
	 * The database this process tests against: one per parallel worker (ParaTest's TEST_TOKEN).
	 *
	 * @return string
	 */
	public function worker_db_name() {
		$token = getenv( 'TEST_TOKEN' );
		$token = ( false === $token || '' === $token ) ? '0' : preg_replace( '/[^0-9A-Za-z_]/', '', (string) $token );

		return $this->values['db_name'] . '_' . $this->profile() . '_' . $token;
	}

	/**
	 * @return string
	 */
	public function cache_dir() {
		return rtrim( (string) $this->values['cache_dir'], '/' );
	}

	/**
	 * Everything the kernel's WordPress config file and installer need, as a plain array.
	 *
	 * @param string $db_name
	 *
	 * @return array<string, mixed>
	 */
	public function to_wordpress_config( $db_name ) {
		return array(
			'wp_root'      => $this->wp_root(),
			'plugins_dir'  => $this->plugins_dir(),
			'db_name'      => $db_name,
			'db_host'      => $this->values['db_host'],
			'db_user'      => $this->values['db_user'],
			'db_password'  => $this->values['db_password'],
			'table_prefix' => $this->values['table_prefix'],
			'domain'       => $this->values['domain'],
			'admin_email'  => $this->values['admin_email'],
			'title'        => $this->values['title'],
			'config_file'  => $this->values['config_file'],
			'plugins'      => $this->plugins(),
			'multisite'    => $this->is_multisite(),
		);
	}
}
