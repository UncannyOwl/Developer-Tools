<?php

namespace Uncanny_Owl\Wp_Test_Kernel\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Uncanny_Owl\Wp_Test_Kernel\Tools\Covers_Check;
use Uncanny_Owl\Wp_Test_Kernel\Tools\Source_Index;

#[CoversClass( Covers_Check::class )]
final class Covers_Check_Test extends TestCase {

	/** @var string */
	private $dir;

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/covers-check-' . uniqid();
		mkdir( $this->dir . '/src/views', 0777, true );
		mkdir( $this->dir . '/tests', 0777, true );
		file_put_contents( $this->dir . '/src/a.php', "<?php\nnamespace Plugin\\Svc;\nclass Cart {}\ntrait Dots {}\ninterface Resolver {}\nfunction helper() {}\nclass_alias( Cart::class, 'Plugin\\\\Old_Cart' );\n" );
		file_put_contents( $this->dir . '/src/views/page.php', "<?php echo 'page';\n" );
		file_put_contents( $this->dir . '/outside.php', "<?php\n" );
	}

	protected function tearDown(): void {
		exec( 'rm -rf ' . escapeshellarg( $this->dir ) );
	}

	/**
	 * @param string $attributes Attribute lines above the test class.
	 * @param string $class      The class declaration.
	 *
	 * @return string[] Problem messages.
	 */
	private function check( $attributes, $class = 'class A_Test extends \\PHPUnit\\Framework\\TestCase {}' ) {
		$file = $this->dir . '/tests/A_Test.php';
		file_put_contents( $file, "<?php\nnamespace Tests;\n\nuse PHPUnit\\Framework\\Attributes as A;\nuse Plugin\\Svc\\Cart;\n\n" . $attributes . "\n" . $class . "\n" );
		$check = new Covers_Check( Source_Index::build( array( $this->dir . '/src' ) ), array( $this->dir . '/src' ) );
		$check->check_file( $file );

		return array_column( $check->problems, 'message' );
	}

	public function test_valid_targets_of_every_kind_pass() {
		$problems = $this->check(
			"#[A\\CoversClass( Cart::class )]\n#[A\\CoversTrait( \\Plugin\\Svc\\Dots::class )]\n#[A\\CoversFunction( 'Plugin\\\\Svc\\\\helper' )]\n#[A\\CoversNamespace( 'Plugin' )]\n#[A\\CoversFile( __DIR__ . '/../src/views/page.php' )]"
		);

		$this->assertSame( array(), $problems );
	}

	public function test_wrong_kinds_and_missing_targets_are_reported() {
		$problems = implode( "\n", $this->check(
			"#[A\\CoversClass( \\Plugin\\Svc\\Dots::class )]\n#[A\\CoversTrait( Cart::class )]\n#[A\\CoversClass( \\Plugin\\Svc\\Resolver::class )]\n#[A\\CoversClass( \\Plugin\\Gone::class )]\n#[A\\CoversClass( \\Plugin\\Old_Cart::class )]\n#[A\\CoversFunction( 'missing' )]\n#[A\\CoversNamespace( 'Elsewhere' )]"
		) );

		$this->assertStringContainsString( 'is a trait; PHPUnit needs CoversTrait', $problems );
		$this->assertStringContainsString( 'CoversTrait \\Plugin\\Svc\\Cart is a class', $problems );
		$this->assertStringContainsString( 'is an interface and holds no code', $problems );
		$this->assertStringContainsString( 'CoversClass \\Plugin\\Gone is not declared', $problems );
		$this->assertStringContainsString( 'is a class_alias() of \\Plugin\\Svc\\Cart', $problems );
		$this->assertStringContainsString( "CoversFunction 'missing' is not declared", $problems );
		$this->assertStringContainsString( "CoversNamespace 'Elsewhere' holds no class", $problems );
	}

	public function test_files_must_exist_inside_the_source() {
		$problems = implode( "\n", $this->check( "#[A\\CoversFile( __DIR__ . '/../src/views/gone.php' )]\n#[A\\CoversFile( __DIR__ . '/../outside.php' )]" ) );

		$this->assertStringContainsString( 'names a file that does not exist', $problems );
		$this->assertStringContainsString( 'names a file outside the source', $problems );
	}

	public function test_covers_method_and_contradictions_and_missing_metadata_are_reported() {
		$this->assertStringContainsString( 'use CoversClass', implode( "\n", $this->check( "#[A\\CoversMethod( Cart::class, 'total' )]" ) ) );
		$this->assertStringContainsString( 'CoversNothing beside other coverage targets', implode( "\n", $this->check( "#[A\\CoversNothing]\n#[A\\CoversClass( Cart::class )]" ) ) );
		$this->assertStringContainsString( 'declares no coverage target', implode( "\n", $this->check( '' ) ) );
		$this->assertSame( array(), $this->check( '#[A\\CoversNothing]' ), 'CoversNothing alone is a declaration.' );
	}

	public function test_only_concrete_test_cases_are_checked() {
		$this->assertSame( array(), $this->check( '', 'abstract class Base_Test extends \\PHPUnit\\Framework\\TestCase {}' ) );
		$this->assertSame( array(), $this->check( '', 'class Fixture_Test extends \\Plugin\\Svc\\Cart {}' ), 'A fixture named ..._Test that extends product code is not a test case.' );
		$this->assertSame( array(), $this->check( '', 'class Helper {}' ) );
	}
}
