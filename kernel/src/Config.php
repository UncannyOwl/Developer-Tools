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
	 * Environment overrides: WP_ROOT_FOLDER, WP_TEST_KERNEL_DB_HOST, WP_TEST_KERNEL_DB_USER,
	 * WP_TEST_KERNEL_DB_PASSWORD, WP_TEST_KERNEL_DB_NAME, WP_TESTS_DOMAIN, WP_MULTISITE.
	 *
	 * @param array<string, mixed> $input
	 *
	 * @return self
	 */
	public static function from_array( array $input ) {

		$defaults = array(
			'plugin_root'        => '',
			'wp_root'            => '',
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
		);

		$values = array_merge( $defaults, $input );

		$overrides = array(
			'wp_root'     => 'WP_ROOT_FOLDER',
			'db_host'     => 'WP_TEST_KERNEL_DB_HOST',
			'db_user'     => 'WP_TEST_KERNEL_DB_USER',
			'db_password' => 'WP_TEST_KERNEL_DB_PASSWORD',
			'db_name'     => 'WP_TEST_KERNEL_DB_NAME',
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

		$values['legacy_notices'] = ( '1' === getenv( 'WP_TEST_KERNEL_LEGACY_NOTICES' ) );

		if ( '' === $values['cache_dir'] ) {
			$values['cache_dir'] = rtrim( (string) $values['plugin_root'], '/' ) . '/tests/kernel/.cache';
		}

		$config = new self( $values );
		$config->validate();

		return $config;
	}

	/**
	 * @return void
	 */
	private function validate() {

		if ( '' === $this->wp_root() || ! is_readable( $this->wp_root() . 'wp-settings.php' ) ) {
			throw new RuntimeException( 'WordPress not found at "' . $this->wp_root() . '". Set wp_root or WP_ROOT_FOLDER.' );
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
	 * @return string WordPress root with a trailing slash.
	 */
	public function wp_root() {
		return rtrim( (string) $this->values['wp_root'], '/' ) . '/';
	}

	/**
	 * @return string
	 */
	public function plugin_root() {
		return rtrim( (string) $this->values['plugin_root'], '/' );
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
