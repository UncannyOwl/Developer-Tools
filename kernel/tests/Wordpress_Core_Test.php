<?php
/**
 * The kernel's own WordPress: resolved from wp_version, extracted once per checkout from a verified
 * release archive.
 */

use PHPUnit\Framework\TestCase;
use Uncanny_Owl\Wp_Test_Kernel\Config;
use Uncanny_Owl\Wp_Test_Kernel\Wordpress_Core;

class Wordpress_Core_Test extends TestCase {

	/** @var string */
	private $work;

	protected function setUp(): void {
		$this->work = sys_get_temp_dir() . '/wptk-core-test-' . getmypid() . '-' . uniqid(); // Phar caches archives by path within a process.
		@mkdir( $this->work . '/plugin', 0777, true );
		putenv( 'WP_TEST_KERNEL_DOWNLOADS=' . $this->work . '/downloads' );
	}

	protected function tearDown(): void {
		putenv( 'WP_TEST_KERNEL_DOWNLOADS' );
		putenv( 'WP_TEST_KERNEL_WP_VERSION' );
		putenv( 'XDG_CACHE_HOME' );
		exec( 'rm -rf ' . escapeshellarg( $this->work ) );
	}

	private function config( array $settings = array() ): Config {
		return Config::from_array(
			array_merge(
				array(
					'plugin_root' => $this->work . '/plugin',
					'wp_version'  => '9.9.9',
				),
				$settings
			)
		);
	}

	/**
	 * A release archive like wordpress.org's, with a matching .sha1 beside it.
	 */
	private function fake_release( string $version, string $checksum = '' ): string {
		$dir = $this->work . '/downloads';
		@mkdir( $dir, 0777, true );

		$tar = $dir . '/wordpress-' . $version . '.tar';
		$phar = new PharData( $tar );
		$phar->addFromString( 'wordpress/wp-settings.php', '<?php // settings' );
		$phar->addFromString( 'wordpress/wp-includes/version.php', '<?php $wp_version = \'' . $version . '\';' );
		$phar->addFromString( 'wordpress/wp-content/themes/twentytwentyfive/style.css', '/* theme */' );
		$phar->compress( Phar::GZ );
		unset( $phar );
		unlink( $tar );

		$archive = $tar . '.gz';
		file_put_contents( $archive . '.sha1', '' === $checksum ? sha1_file( $archive ) : $checksum );

		return $archive;
	}

	public function test_no_wordpress_named_means_the_kernels_own_release_outside_the_checkout(): void {
		$config = $this->config();

		$this->assertTrue( $config->uses_kernel_wordpress() );
		$this->assertMatchesRegularExpression( '#^' . preg_quote( $this->work, '#' ) . '/downloads/wordpress-9\.9\.9-[0-9a-f]{10}/$#', $config->wp_root() );
		$this->assertStringStartsNotWith( $this->work . '/plugin/', $config->wp_root(), 'Core files never sit inside the plugin under test.' );
	}

	public function test_each_checkout_gets_its_own_copy(): void {
		@mkdir( $this->work . '/other', 0777, true );

		$this->assertNotSame( $this->config()->wp_root(), $this->config( array( 'plugin_root' => $this->work . '/other' ) )->wp_root() );
	}

	public function test_without_a_release_or_an_install_there_is_nothing_to_test_with(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'No WordPress to test with' );

		$this->config( array( 'wp_version' => '' ) );
	}

	public function test_the_release_can_be_chosen_for_one_run(): void {
		putenv( 'WP_TEST_KERNEL_WP_VERSION=6.9' );

		$this->assertMatchesRegularExpression( '#/wordpress-6\.9-[0-9a-f]{10}/$#', $this->config()->wp_root() );
	}

	public function test_releases_are_kept_once_per_machine(): void {
		putenv( 'WP_TEST_KERNEL_DOWNLOADS' );
		putenv( 'XDG_CACHE_HOME=/var/cache/me' );

		$this->assertSame( '/var/cache/me/wp-test-kernel', $this->config()->downloads_dir() );
	}

	public function test_a_verified_release_is_extracted_once_with_its_own_wp_content(): void {
		$this->fake_release( '9.9.9' );
		$config = $this->config();

		Wordpress_Core::ensure( $config );

		$root = $config->wp_root();
		$this->assertFileExists( $root . 'wp-settings.php' );
		$this->assertFileExists( $root . 'wp-content/themes/twentytwentyfive/style.css', 'The release\'s default theme comes with it.' );
		$this->assertDirectoryExists( $root . 'wp-content/uploads' );
		$this->assertDirectoryDoesNotExist( $root . 'wp-content/mu-plugins', 'No must-use plugins of a dev site.' );

		file_put_contents( $root . 'probe.txt', 'kept' );
		Wordpress_Core::ensure( $config );
		$this->assertFileExists( $root . 'probe.txt', 'A second run reuses the extracted copy.' );
	}

	public function test_an_archive_that_does_not_match_its_checksum_is_not_used(): void {
		$archive = $this->fake_release( '9.9.9', str_repeat( 'a', 40 ) );

		$verified = new ReflectionMethod( Wordpress_Core::class, 'verified' );

		$this->assertFalse( $verified->invoke( null, $archive, $archive . '.sha1' ) );
	}

	public function test_a_named_install_is_used_as_it_is(): void {
		@mkdir( $this->work . '/site', 0777, true );
		touch( $this->work . '/site/wp-settings.php' );

		$config = $this->config( array( 'wp_root' => $this->work . '/site' ) );
		Wordpress_Core::ensure( $config );

		$this->assertFalse( $config->uses_kernel_wordpress() );
		$this->assertSame( $this->work . '/site/', $config->wp_root() );
		$this->assertDirectoryDoesNotExist( $this->work . '/downloads', 'Nothing is downloaded or extracted for a named install.' );
	}
}
