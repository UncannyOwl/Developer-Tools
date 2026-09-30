<?php
/**
 * End-to-end tests for bin/generate-trigger-metadata.php and ->dispatcher().
 *
 * Each test writes a one-trigger plugin to a temp dir, runs the script as a
 * subprocess against it and reads the metadata file it writes. The fixture has
 * no Free checkout, so the script runs on its stub framework classes, as Pro's
 * CI build does.
 */

use PHPUnit\Framework\TestCase;

class Generate_Trigger_Metadata_Test extends TestCase {

	const BIN = __DIR__ . '/../../bin/generate-trigger-metadata.php';

	/**
	 * @var string Temp plugin root.
	 */
	private $plugin;

	protected function setUp(): void {
		$this->plugin = sys_get_temp_dir() . '/generate-trigger-metadata-' . uniqid();
	}

	protected function tearDown(): void {
		$this->remove_dir( $this->plugin );
	}

	/**
	 * Write a plugin whose trigger declares $dispatcher, with a dispatcher whose
	 * boot() takes $boot_params.
	 *
	 * @param string $dispatcher  FQCN the trigger declares.
	 * @param string $boot_params Parameter list of Fixture\Foo_Dispatcher::boot().
	 *
	 * @return void
	 */
	private function write_plugin( $dispatcher, $boot_params = '' ) {
		$foo = $this->plugin . '/src/integrations/foo';
		mkdir( $this->plugin . '/vendor/composer', 0777, true );
		mkdir( $foo . '/triggers', 0777, true );
		mkdir( $foo . '/dispatchers', 0777, true );

		file_put_contents(
			$this->plugin . '/vendor/autoload.php',
			"<?php\nspl_autoload_register( function ( \$class ) {\n\tif ( 'Fixture\\\\Foo_Dispatcher' === \$class ) {\n\t\trequire __DIR__ . '/../src/integrations/foo/dispatchers/foo-dispatcher.php';\n\t}\n} );\n"
		);
		file_put_contents(
			$foo . '/dispatchers/foo-dispatcher.php',
			"<?php\nnamespace Fixture;\nclass Foo_Dispatcher {\n\tpublic static function boot( $boot_params ) {}\n}\n"
		);
		file_put_contents(
			$foo . '/triggers/foo-trigger.php',
			"<?php\nnamespace Fixture;\nclass Foo_Trigger extends \\Uncanny_Automator\\Recipe\\Trigger {\n\tpublic static function definition() {\n\t\treturn self::new_definition( 'FOO_EVENT', 'FOO' )->hook( 'automator_foo_event', 10, 1 )->dispatcher( " . var_export( $dispatcher, true ) . " );\n\t}\n}\n"
		);
	}

	/**
	 * @return array{exit:int,raw:string}
	 */
	private function run_generator() {
		$cmd = sprintf(
			'%s %s --plugin-path %s 2>&1',
			escapeshellarg( PHP_BINARY ),
			escapeshellarg( self::BIN ),
			escapeshellarg( $this->plugin )
		);
		exec( $cmd, $lines, $exit );
		return array( 'exit' => $exit, 'raw' => implode( "\n", $lines ) );
	}

	/**
	 * @param string $dir
	 *
	 * @return void
	 */
	private function remove_dir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
		rmdir( $dir );
	}

	public function test_declared_dispatcher_reaches_the_metadata_file() {
		$this->write_plugin( 'Fixture\\Foo_Dispatcher' );

		$r = $this->run_generator();

		$this->assertSame( 0, $r['exit'], $r['raw'] );
		$metadata = include $this->plugin . '/vendor/composer/autoload_trigger_metadata.php';
		$this->assertSame( 'Fixture\\Foo_Dispatcher', $metadata['FOO_EVENT']['dispatcher'] ?? null, 'the entry must carry the declared dispatcher' );
	}

	public function test_dispatcher_whose_boot_needs_an_argument_fails_the_build() {
		$this->write_plugin( 'Fixture\\Foo_Dispatcher', '$helpers' );

		$r = $this->run_generator();

		$this->assertSame( 1, $r['exit'], $r['raw'] );
		$this->assertStringContainsString( 'FOO_EVENT declares Fixture\\Foo_Dispatcher: boot() must take no required argument', $r['raw'] );
	}

	public function test_unloadable_dispatcher_fails_the_build() {
		$this->write_plugin( 'Fixture\\Missing_Dispatcher' );

		$r = $this->run_generator();

		$this->assertSame( 1, $r['exit'], $r['raw'] );
		$this->assertStringContainsString( 'FOO_EVENT declares Fixture\\Missing_Dispatcher: class not loadable', $r['raw'] );
	}
}
