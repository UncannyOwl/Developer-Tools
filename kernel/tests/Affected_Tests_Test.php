<?php
/**
 * Affected_Tests picks the test files a change reaches, and `wp-test-kernel affected` writes them into
 * a PHPUnit config. `wp-test-kernel order` shares the config writer, so it is checked here as well.
 */

namespace Uncanny_Owl\Wp_Test_Kernel\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Uncanny_Owl\Wp_Test_Kernel\Tools\Affected_Tests;

#[CoversClass( Affected_Tests::class )]
final class Affected_Tests_Test extends TestCase {

	/** @var string */
	private $dir;

	protected function setUp(): void {
		$this->dir = (string) realpath( sys_get_temp_dir() ) . '/affected-tests-' . uniqid();
		mkdir( $this->dir . '/src/blocks', 0777, true );
		mkdir( $this->dir . '/tests/unit', 0777, true );

		$this->write( 'src/blocks/base.php', "<?php\nnamespace Plugin\\Blocks;\nabstract class Base_Block {}\n" );
		$this->write( 'src/blocks/filter.php', "<?php\nnamespace Plugin\\Blocks;\nclass Filter_Block extends Base_Block {}\nclass_alias( Filter_Block::class, 'Plugin\\\\Old_Filter' );\n" );
		$this->write( 'src/blocks/registry.php', "<?php\nnamespace Plugin\\Blocks;\nclass Registry {}\n" );
		$this->write( 'src/functions.php', "<?php\nfunction plugin_option( \$name ) { return \$name; }\n" );
		$this->write( 'src/unused.php', "<?php\nnamespace Plugin;\nclass Nobody_Tests_Me {}\n" );
		$this->write( 'README.md', "Not code.\n" );
		$this->write(
			'phpunit.xml',
			"<?xml version=\"1.0\"?>\n<phpunit bootstrap=\"tests/bootstrap.php\">\n<testsuites><testsuite name=\"all\"><directory>tests</directory></testsuite></testsuites>\n<source><include><directory>src</directory></include></source>\n</phpunit>\n"
		);

		$this->test_file( 'Filter_Test', '#[A\\CoversClass( \\Plugin\\Blocks\\Filter_Block::class )]', '' );
		$this->test_file( 'Registry_Test', '#[A\\CoversClass( \\Plugin\\Blocks\\Registry::class )]', 'new \\Plugin\\Blocks\\Filter_Block();' );
		$this->test_file( 'Registry_Only_Test', '#[A\\CoversClass( \\Plugin\\Blocks\\Registry::class )]', 'new \\Plugin\\Blocks\\Registry();' );
		$this->test_file( 'Alias_Test', '#[A\\CoversNothing]', 'new \\Plugin\\Old_Filter();' );
		$this->test_file( 'Option_Test', '#[A\\CoversNothing]', 'plugin_option( "x" );' );
		$this->test_file( 'Namespace_Test', '#[A\\CoversNamespace( \'Plugin\\\\Blocks\' )]', '' );
	}

	protected function tearDown(): void {
		exec( 'rm -rf ' . escapeshellarg( $this->dir ) );
	}

	private function write( $path, $contents ) {
		file_put_contents( $this->dir . '/' . $path, $contents );
	}

	private function test_file( $class, $attributes, $body ) {
		$this->write(
			"tests/unit/$class.php",
			"<?php\nnamespace Tests;\n\nuse PHPUnit\\Framework\\Attributes as A;\n\n$attributes\nclass $class extends \\PHPUnit\\Framework\\TestCase {\n\tpublic function test_it() { $body }\n}\n"
		);
	}

	/**
	 * @param string[] $changed Paths relative to the fixture.
	 *
	 * @return string[] Selected test classes.
	 */
	private function select( array $changed, ?Affected_Tests $affected = null ) {
		$affected = $affected ?? Affected_Tests::from_config( $this->dir . '/phpunit.xml' );
		$tests    = glob( $this->dir . '/tests/unit/*Test.php' );
		$selected = $affected->select(
			$tests,
			array_map(
				function ( $path ) {
					return $this->dir . '/' . $path;
				},
				$changed
			)
		);

		return array_map(
			static function ( $file ) {
				return basename( $file, '.php' );
			},
			$selected
		);
	}

	public function test_a_changed_class_selects_its_covering_tests_and_the_tests_that_build_it() {
		$this->assertSame(
			array( 'Alias_Test', 'Filter_Test', 'Namespace_Test', 'Registry_Test' ),
			$this->select( array( 'src/blocks/filter.php' ) ),
			'Covered, built, built through its class_alias(), and inside a covered namespace; Registry_Only_Test never names it.'
		);
	}

	public function test_a_changed_parent_selects_the_tests_of_its_subclasses() {
		$this->assertSame( array( 'Alias_Test', 'Filter_Test', 'Namespace_Test', 'Registry_Test' ), $this->select( array( 'src/blocks/base.php' ) ) );
	}

	public function test_a_changed_function_selects_the_tests_that_call_it_from_a_namespace() {
		$this->assertSame( array( 'Option_Test' ), $this->select( array( 'src/functions.php' ) ) );
	}

	public function test_a_changed_test_file_selects_itself() {
		$this->assertSame( array( 'Registry_Only_Test' ), $this->select( array( 'tests/unit/Registry_Only_Test.php' ) ) );
	}

	public function test_changed_files_no_test_names_are_reported_and_missing_ones_ignored() {
		$affected = Affected_Tests::from_config( $this->dir . '/phpunit.xml' );

		$this->assertSame( array(), $this->select( array( 'src/unused.php', 'src/deleted.php', 'README.md' ), $affected ) );
		$this->assertSame( array( $this->dir . '/src/unused.php' ), $affected->unreached, 'Only a source file is reported; a deleted file or one outside <source> is not.' );
	}

	public function test_a_config_without_a_source_is_refused() {
		$this->write( 'bare.xml', "<?xml version=\"1.0\"?>\n<phpunit></phpunit>\n" );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'has no <source>' );
		Affected_Tests::from_config( $this->dir . '/bare.xml' );
	}

	public function test_the_command_writes_the_selected_files_and_the_also_paths_into_one_suite() {
		mkdir( $this->dir . '/tests/slug' );
		$this->write( 'changed.txt', "src/blocks/registry.php\nsrc/unused.php\n" );

		list( $code, $output ) = $this->command( 'affected tests/unit --changed=changed.txt --config=phpunit.xml --out=out/affected.xml --also=tests/slug,tests/missing' );

		$this->assertSame( 0, $code, $output );
		$this->assertStringContainsString( 'No test names src/unused.php', $output );
		$xml = (string) file_get_contents( $this->dir . '/out/affected.xml' );
		$this->assertStringContainsString( '<testsuite name="affected">', $xml );
		$this->assertStringContainsString( '<file>../tests/unit/Registry_Only_Test.php</file>', $xml );
		$this->assertStringContainsString( '<file>../tests/unit/Registry_Test.php</file>', $xml );
		$this->assertStringContainsString( '<directory>../tests/slug</directory>', $xml, 'An --also folder is added as it is.' );
		$this->assertStringNotContainsString( 'missing', $xml, 'An --also path that does not exist is left out.' );
		$this->assertStringNotContainsString( 'Filter_Test', $xml );
		$this->assertStringContainsString( 'bootstrap="../tests/bootstrap.php"', $xml, 'Relative paths still point where they did.' );
		$this->assertStringContainsString( '<directory>../src</directory>', $xml );
	}

	public function test_the_command_writes_nothing_when_no_test_is_reached() {
		list( $code, $output ) = $this->command( 'affected tests/unit --changed=- --config=phpunit.xml --out=out/affected.xml', "README.md\n" );

		$this->assertSame( 0, $code, $output );
		$this->assertStringContainsString( 'nothing written', $output );
		$this->assertFileDoesNotExist( $this->dir . '/out/affected.xml' );
	}

	public function test_the_command_needs_its_options() {
		list( $code, $output ) = $this->command( 'affected tests/unit --config=phpunit.xml' );

		$this->assertSame( 2, $code );
		$this->assertStringContainsString( 'affected needs', $output );
	}

	public function test_order_writes_the_run_order_with_the_same_writer() {
		$this->write(
			'run.xml',
			'<testsuites><testsuite>'
			. '<testcase name="test_it" class="Tests\Registry_Test" file="' . $this->dir . '/tests/unit/Registry_Test.php"/>'
			. '<testcase name="test_it" class="Tests\Filter_Test" file="' . $this->dir . '/tests/unit/Filter_Test.php"/>'
			. '</testsuite></testsuites>'
		);

		list( $code, $output ) = $this->command( 'order --from=run.xml --config=phpunit.xml --out=out/order.xml' );

		$this->assertSame( 0, $code, $output );
		$xml = (string) file_get_contents( $this->dir . '/out/order.xml' );
		$this->assertMatchesRegularExpression( '#<testsuite name="ordered">\s*<file>../tests/unit/Registry_Test.php</file>\s*<file>../tests/unit/Filter_Test.php</file>#', $xml );
		$this->assertStringContainsString( 'bootstrap="../tests/bootstrap.php"', $xml );
	}

	/**
	 * @param string      $arguments
	 * @param string|null $stdin
	 *
	 * @return array{0: int, 1: string}
	 */
	private function command( $arguments, $stdin = null ) {
		if ( ! is_dir( $this->dir . '/out' ) ) {
			mkdir( $this->dir . '/out' );
		}
		$command = 'cd ' . escapeshellarg( $this->dir ) . ' && ' . ( null === $stdin ? '' : 'printf %s ' . escapeshellarg( $stdin ) . ' | ' )
			. escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( dirname( __DIR__, 2 ) . '/bin/wp-test-kernel' ) . ' ' . $arguments . ' 2>&1';
		exec( $command, $output, $code );

		return array( $code, implode( "\n", $output ) );
	}
}
