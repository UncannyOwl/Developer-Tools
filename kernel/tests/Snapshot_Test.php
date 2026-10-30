<?php
/**
 * The snapshot activates the profile's plugins the way wp-admin would: a network activation on a
 * multisite profile is the network's, and a plugin whose Requires Plugins header names a plugin
 * activated before it activates too.
 *
 * Builds real snapshots: needs MySQL (skipped without it) and the kernel's own WordPress, a release
 * downloaded once per machine.
 */

use PHPUnit\Framework\TestCase;
use Uncanny_Owl\Wp_Test_Kernel\Config;
use Uncanny_Owl\Wp_Test_Kernel\Database;
use Uncanny_Owl\Wp_Test_Kernel\Snapshot;
use Uncanny_Owl\Wp_Test_Kernel\Wordpress_Core;

class Snapshot_Test extends TestCase {

	const BASE      = 'wptk-fixture-base/wptk-fixture-base.php';
	const DEPENDENT = 'wptk-fixture-dependent/wptk-fixture-dependent.php';

	/** @var string */
	private $cache_dir;

	/** @var Database|null */
	private $database;

	/** @var string */
	private $restored = '';

	protected function setUp(): void {
		$this->cache_dir = sys_get_temp_dir() . '/wptk-snapshot-test-' . getmypid() . '-' . uniqid();
	}

	protected function tearDown(): void {
		if ( null !== $this->database && '' !== $this->restored ) {
			$this->database->drop( $this->restored );
		}
		exec( 'rm -rf ' . escapeshellarg( $this->cache_dir ) );
	}

	public function test_a_network_activates_a_plugin_after_the_plugin_its_requires_plugins_header_names(): void {
		$db = $this->restore( $this->build( true ) );

		$network_active = $this->unserialized( $this->value( $db, 'sitemeta', 'meta_key', 'meta_value', 'active_sitewide_plugins' ) );

		$this->assertIsArray( $network_active, 'The network records its active plugins.' );
		$this->assertArrayHasKey( self::BASE, $network_active, 'The required plugin is network-active.' );
		$this->assertArrayHasKey( self::DEPENDENT, $network_active, 'The plugin that requires it is network-active.' );
		$this->assertSame( 'network', $this->value( $db, 'sitemeta', 'meta_key', 'meta_value', 'wptk_fixture_base_activated' ), 'The required plugin activated on a network, network-wide.' );
		$this->assertSame( 'network', $this->value( $db, 'sitemeta', 'meta_key', 'meta_value', 'wptk_fixture_dependent_activated' ), 'The plugin that requires it activated on a network, network-wide.' );
		$this->assertSame( '', $this->value( $db, 'options', 'option_name', 'option_value', 'active_sitewide_plugins' ), 'No network activation lands in the main site\'s options.' );
	}

	public function test_a_single_site_activates_a_plugin_after_the_plugin_its_requires_plugins_header_names(): void {
		$db = $this->restore( $this->build( false ) );

		$active = $this->unserialized( $this->value( $db, 'options', 'option_name', 'option_value', 'active_plugins' ) );

		$this->assertSame( array( self::BASE, self::DEPENDENT ), $active );
		$this->assertSame( 'site', $this->value( $db, 'options', 'option_name', 'option_value', 'wptk_fixture_base_activated' ) );
		$this->assertSame( 'site', $this->value( $db, 'options', 'option_name', 'option_value', 'wptk_fixture_dependent_activated' ) );
	}

	public function test_the_key_covers_files_that_exist(): void {
		foreach ( Snapshot::INSTALLER_FILES as $file ) {
			$this->assertFileExists( dirname( __DIR__ ) . '/' . $file, 'A renamed installer file must be renamed in the key, or a fix to it never reaches a cached snapshot.' );
		}
	}

	/**
	 * Build the snapshot of a profile that activates the base fixture, then the one that requires it.
	 *
	 * @return string The snapshot's path.
	 */
	private function build( bool $multisite ): string {
		$config = Config::from_array(
			array(
				'plugin_root' => __DIR__ . '/fixtures/plugins/wptk-fixture-dependent',
				'wp_version'  => '7.1.4', // Requires Plugins needs WordPress 6.5 or later.
				'plugins'     => array( self::BASE, self::DEPENDENT ),
				'multisite'   => $multisite,
				'profile'     => $multisite ? 'multisite' : 'single',
				'db_name'     => 'wptk_snapshot_test',
				'cache_dir'   => $this->cache_dir,
			)
		);

		$this->skip_without_mysql( $config );
		$this->database = Database::from_config( $config );
		$this->restored = $config->get( 'db_name' ) . '_check_' . getmypid();

		Wordpress_Core::ensure( $config );

		return Snapshot::ensure( $config );
	}

	private function restore( string $snapshot ): string {
		$this->database->recreate( $this->restored );
		$this->database->import( $this->restored, $snapshot );

		return $this->restored;
	}

	/**
	 * One row's value from a table of the restored snapshot, '' when there is no such row.
	 */
	private function value( string $db, string $table, string $key_column, string $value_column, string $key ): string {
		return trim( $this->database->sql( sprintf( "SELECT `%s` FROM `%s`.`wptests_%s` WHERE `%s` = '%s';", $value_column, $db, $table, $key_column, $key ) ) );
	}

	/**
	 * @return mixed
	 */
	private function unserialized( string $value ) {
		return unserialize( $value, array( 'allowed_classes' => false ) );
	}

	private function skip_without_mysql( Config $config ): void {
		if ( ! function_exists( 'mysqli_init' ) ) {
			$this->markTestSkipped( 'mysqli is not loaded.' );
		}
		mysqli_report( MYSQLI_REPORT_OFF );
		list( $host, $port, $socket ) = Database::parse_host( (string) $config->get( 'db_host' ) );
		$connection                   = mysqli_init();
		if ( ! @$connection->real_connect( $host, (string) $config->get( 'db_user' ), (string) $config->get( 'db_password' ), '', (int) $port, '' === $socket ? null : $socket ) ) {
			$this->markTestSkipped( 'No MySQL server at ' . $config->get( 'db_host' ) . ' for user ' . $config->get( 'db_user' ) . '.' );
		}
		$connection->close();
	}
}
