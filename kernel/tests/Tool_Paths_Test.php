<?php
/**
 * Where the tools live: the vendor/ Developer-Tools was installed into, whichever project that is.
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Uncanny_Owl\Wp_Test_Kernel\Tools\Tool_Paths;

#[CoversClass( Tool_Paths::class )]
final class Tool_Paths_Test extends TestCase {

	/** @var string */
	private $root;

	protected function setUp(): void {
		$this->root = (string) realpath( sys_get_temp_dir() ) . '/tool-paths-' . uniqid();
		mkdir( $this->root, 0777, true );
	}

	protected function tearDown(): void {
		exec( 'rm -rf ' . escapeshellarg( $this->root ) );
	}

	/**
	 * @param string $path
	 *
	 * @return string
	 */
	private function file( $path ) {
		$file = $this->root . '/' . $path;
		if ( ! is_dir( dirname( $file ) ) ) {
			mkdir( dirname( $file ), 0777, true );
		}
		file_put_contents( $file, "<?php\n" );

		return $file;
	}

	public function test_a_plugin_that_keeps_its_tools_in_their_own_project_gets_that_projects_vendor(): void {
		// Automator Free's layout: the plugin's vendor/ holds only what WordPress loads.
		$this->file( 'plugin/vendor/autoload.php' );
		$this->file( 'plugin/tests/tools/vendor/autoload.php' );
		$this->file( 'plugin/tests/tools/vendor/uncanny-owl/developer-tools/bin/mutate' );

		$package = $this->root . '/plugin/tests/tools/vendor/uncanny-owl/developer-tools';

		$this->assertSame( $this->root . '/plugin/tests/tools/vendor', Tool_Paths::vendor_dir( $package ), 'PHPUnit and Infection are in the tools project, not in the plugin\'s runtime vendor/.' );
		$this->assertSame( $this->root . '/plugin/tests/tools/vendor/bin', Tool_Paths::bin_dir( $package ) );
	}

	public function test_a_plugin_that_requires_developer_tools_itself_gets_its_own_vendor(): void {
		$this->file( 'plugin/vendor/autoload.php' );
		$this->file( 'plugin/vendor/uncanny-owl/developer-tools/bin/mutate' );

		$this->assertSame( $this->root . '/plugin/vendor/bin', Tool_Paths::bin_dir( $this->root . '/plugin/vendor/uncanny-owl/developer-tools/' ) );
	}

	public function test_developer_tools_as_the_root_project_uses_its_own_vendor(): void {
		$this->file( 'developer-tools/vendor/autoload.php' );

		$this->assertSame( $this->root . '/developer-tools/vendor', Tool_Paths::vendor_dir( $this->root . '/developer-tools' ) );
		$this->assertSame( dirname( __DIR__, 2 ) . '/vendor', Tool_Paths::vendor_dir(), 'This checkout is the root project, with its own vendor/.' );
	}

	public function test_no_composer_install_is_a_clear_error(): void {
		mkdir( $this->root . '/vendor/uncanny-owl/developer-tools', 0777, true );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'run composer install' );

		Tool_Paths::vendor_dir( $this->root . '/vendor/uncanny-owl/developer-tools' );
	}
}
