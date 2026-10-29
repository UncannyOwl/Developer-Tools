<?php
/**
 * Attribute_Adder must add PHPUnit 13 attributes next to docblock tags without changing anything else,
 * and running it twice must change nothing the second time.
 */

use PHPUnit\Framework\TestCase;
use Uncanny_Owl\Wp_Test_Kernel\Tools\Attribute_Adder;

class Attribute_Adder_Test extends TestCase {

	/** @var string */
	private $file;

	protected function setUp(): void {
		$this->file = tempnam( sys_get_temp_dir(), 'attr' ) . '.php';
	}

	protected function tearDown(): void {
		if ( is_file( $this->file ) ) {
			unlink( $this->file );
		}
	}

	/**
	 * @param string $code
	 *
	 * @return string
	 */
	private function process( $code ) {
		file_put_contents( $this->file, $code );
		( new Attribute_Adder() )->process_file( $this->file, true );

		return (string) file_get_contents( $this->file );
	}

	public function test_multi_line_and_one_line_docblocks_get_attributes() {
		$result = $this->process(
			"<?php\n/**\n * @group mcp\n */\nclass A_Test {\n\t/**\n\t * @dataProvider rows\n\t */\n\tpublic function test_a( \$x ) {}\n\n\t/** @dataProvider rows */\n\tpublic function test_b( \$x ) {}\n\n\tpublic static function rows() { return array(); }\n}\n"
		);

		$this->assertStringContainsString( "#[\\PHPUnit\\Framework\\Attributes\\Group( 'mcp' )]\nclass A_Test", $result );
		$this->assertStringContainsString( "\t#[\\PHPUnit\\Framework\\Attributes\\DataProvider( 'rows' )]\n\tpublic function test_a", $result );
		$this->assertStringContainsString( "\t#[\\PHPUnit\\Framework\\Attributes\\DataProvider( 'rows' )]\n\tpublic function test_b", $result );
		$this->assertStringContainsString( '* @dataProvider rows', $result, 'Docblocks stay for PHPUnit 9.' );
	}

	public function test_lifecycle_tags_in_traits_and_process_isolation_are_converted() {
		$result = $this->process(
			"<?php\ntrait T {\n\t/**\n\t * @after\n\t */\n\tprotected function reset() {}\n}\nclass B_Test {\n\t/**\n\t * @runInSeparateProcess\n\t * @preserveGlobalState disabled\n\t */\n\tpublic function test_x() {}\n}\n"
		);

		$this->assertStringContainsString( "#[\\PHPUnit\\Framework\\Attributes\\After]\n\tprotected function reset", $result );
		$this->assertStringContainsString( '#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]', $result );
		$this->assertStringContainsString( '#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]', $result );
	}

	public function test_providers_become_public_static_unless_they_use_this() {
		$result = $this->process(
			"<?php\nclass C_Test {\n\t/** @dataProvider plain */\n\tpublic function test_a( \$x ) {}\n\t/** @dataProvider needs_this */\n\tpublic function test_b( \$x ) {}\n\tprotected function plain() { return array(); }\n\tpublic function needs_this() { return \$this->rows(); }\n}\n"
		);

		$this->assertStringContainsString( 'public static function plain()', $result );
		$this->assertStringContainsString( 'public function needs_this()', $result );
	}

	public function test_a_second_run_changes_nothing() {
		$first = $this->process( "<?php\nclass D_Test {\n\t/**\n\t * @group a\n\t * @group b\n\t * @test\n\t */\n\tpublic function check() {}\n}\n" );

		$this->assertFalse( ( new Attribute_Adder() )->process_file( $this->file, true ) );
		$this->assertSame( $first, file_get_contents( $this->file ) );
		$this->assertSame( 2, substr_count( $first, 'Attributes\Group(' ) );
	}
}
