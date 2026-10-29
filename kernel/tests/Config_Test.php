<?php
/**
 * Config must give each parallel worker its own database and let CI override settings by environment.
 */

use PHPUnit\Framework\TestCase;
use Uncanny_Owl\Wp_Test_Kernel\Config;
use Uncanny_Owl\Wp_Test_Kernel\Expectations;
use Uncanny_Owl\Wp_Test_Kernel\Attributes\Expected_Deprecated;

class Config_Test extends TestCase {

	/** @var string */
	private $wp_root;

	protected function setUp(): void {
		$this->wp_root = sys_get_temp_dir() . '/wp-kernel-config-test';
		if ( ! is_dir( $this->wp_root ) ) {
			mkdir( $this->wp_root );
		}
		touch( $this->wp_root . '/wp-settings.php' );
	}

	protected function tearDown(): void {
		putenv( 'TEST_TOKEN' );
		putenv( 'WP_MULTISITE' );
		putenv( 'WP_TEST_KERNEL_DB_HOST' );
		putenv( 'WP_TEST_KERNEL_PLUGINS_DIR' );
		putenv( 'WP_TEST_KERNEL_SHOW_DB_ERRORS' );
	}

	/**
	 * @return Config
	 */
	private function config() {
		return Config::from_array(
			array(
				'plugin_root' => __DIR__,
				'wp_root'     => $this->wp_root,
				'db_name'     => 'automator_kernel',
			)
		);
	}

	public function test_each_worker_gets_its_own_database() {
		$this->assertSame( 'automator_kernel_single_0', $this->config()->worker_db_name() );

		putenv( 'TEST_TOKEN=3' );
		$this->assertSame( 'automator_kernel_single_3', $this->config()->worker_db_name() );

		putenv( 'WP_MULTISITE=1' );
		$this->assertSame( 'automator_kernel_multisite_3', $this->config()->worker_db_name() );
	}

	public function test_environment_overrides_the_database_host() {
		putenv( 'WP_TEST_KERNEL_DB_HOST=db:3307' );

		$this->assertSame( 'db:3307', $this->config()->get( 'db_host' ) );
	}

	public function test_plugins_load_from_the_folder_holding_the_plugin_under_test() {
		$config = $this->config()->to_wordpress_config( 'x' );

		$this->assertSame( dirname( __DIR__ ), $config['plugins_dir'], 'A checkout outside the WordPress install is the copy WordPress loads.' );
	}

	public function test_the_plugins_folder_can_be_set() {
		putenv( 'WP_TEST_KERNEL_PLUGINS_DIR=/srv/plugins/' );

		$this->assertSame( '/srv/plugins', $this->config()->plugins_dir() );
	}

	public function test_database_errors_stay_out_of_the_test_output_unless_asked() {
		$this->assertFalse( $this->config()->get( 'show_db_errors' ) );

		putenv( 'WP_TEST_KERNEL_SHOW_DB_ERRORS=1' );
		$this->assertTrue( $this->config()->get( 'show_db_errors' ) );
	}

	public function test_a_missing_wordpress_root_is_refused() {
		$this->expectException( RuntimeException::class );

		Config::from_array( array( 'plugin_root' => __DIR__, 'wp_root' => '/nonexistent' ) );
	}

	#[Expected_Deprecated( 'old_function' )]
	public function test_expectations_read_attributes_and_docblock_tags() {
		$this->assertSame(
			array(
				'class'  => array(),
				'method' => array( 'expectedDeprecated' => array( 'old_function' ) ),
			),
			Expectations::for_test( self::class, __FUNCTION__ )
		);

		$this->assertSame(
			array( 'expectedIncorrectUsage' => array( 'register_rest_route' ) ),
			Expectations::for_test( self::class, 'docblock_fixture' )['method']
		);
	}

	/**
	 * @expectedIncorrectUsage register_rest_route
	 */
	public function docblock_fixture() {
	}

	public function test_a_named_profile_gets_its_own_databases() {
		putenv( 'WP_TEST_KERNEL_PROFILE=Migration' );
		putenv( 'TEST_TOKEN=2' );

		$this->assertSame( 'automator_kernel_migration_2', $this->config()->worker_db_name() );
		putenv( 'WP_TEST_KERNEL_PROFILE' );
	}

	public function test_legacy_notice_mode_is_the_plugins_choice_unless_the_environment_overrides_it() {
		$settings = array(
			'plugin_root'    => __DIR__,
			'wp_root'        => $this->wp_root,
			'legacy_notices' => true,
		);
		$this->assertTrue( Config::from_array( $settings )->get( 'legacy_notices' ) );

		putenv( 'WP_TEST_KERNEL_LEGACY_NOTICES=0' );
		$this->assertFalse( Config::from_array( $settings )->get( 'legacy_notices' ) );
		putenv( 'WP_TEST_KERNEL_LEGACY_NOTICES' );
	}

	public function test_after_each_test_callbacks_are_kept_as_given() {
		$callback = 'strlen';
		$config   = Config::from_array( array( 'plugin_root' => __DIR__, 'wp_root' => $this->wp_root, 'after_each_test' => array( $callback ) ) );

		$this->assertSame( array( $callback ), $config->get( 'after_each_test' ) );
		$this->assertSame( array(), $this->config()->get( 'after_each_test' ), 'None by default.' );
	}
}
