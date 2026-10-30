<?php
/**
 * Attribute_Adder must add PHPUnit 13 attributes next to docblock tags without changing anything else,
 * and running it twice must change nothing the second time.
 */

use PHPUnit\Framework\TestCase;
use Uncanny_Owl\Wp_Test_Kernel\Tools\Attribute_Adder;
use Uncanny_Owl\Wp_Test_Kernel\Tools\Source_Index;

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
	private function process( $code, $covers = false ) {
		file_put_contents( $this->file, $code );
		( new Attribute_Adder() )->process_file( $this->file, true, $covers );

		return (string) file_get_contents( $this->file );
	}

	public function test_coverage_tags_become_class_attributes_and_leave_the_docblocks() {
		$result = $this->process(
			"<?php\nnamespace Tests\\App;\n\nuse Vendor\\Pkg\\Order_Service;\nuse Vendor\\Pkg as P;\n\n/**\n * Orders.\n *\n * @coversDefaultClass \\Vendor\\Pkg\\Cart\n * @group orders\n */\nclass Order_Test {\n\t/**\n\t * @covers ::total\n\t */\n\tpublic function test_a() {}\n\n\t/**\n\t * Ships it.\n\t * @covers Order_Service::ship\n\t * @covers P\\Tax\n\t * @covers Local_Thing\n\t * @covers automator_get_option\n\t */\n\tpublic function test_b() {}\n\n\t/** @covers ::total */\n\tpublic function test_c() {}\n}\n",
			true
		);

		$this->assertStringContainsString( "#[\\PHPUnit\\Framework\\Attributes\\CoversClass( \\Vendor\\Pkg\\Tax::class )]", $result );
		$this->assertStringContainsString( "#[\\PHPUnit\\Framework\\Attributes\\CoversClass( \\Tests\\App\\Local_Thing::class )]", $result, 'An unimported name is in the file\'s namespace.' );
		$this->assertStringContainsString( "#[\\PHPUnit\\Framework\\Attributes\\CoversClass( \\Vendor\\Pkg\\Cart::class )]", $result, '::method takes @coversDefaultClass, and a method target becomes its class.' );
		$this->assertStringContainsString( "#[\\PHPUnit\\Framework\\Attributes\\CoversClass( \\Vendor\\Pkg\\Order_Service::class )]", $result, 'A use import resolves.' );
		$this->assertStringNotContainsString( 'CoversMethod', $result, 'CoversMethod would leave the private helpers a method calls uncredited.' );
		$this->assertStringContainsString( "#[\\PHPUnit\\Framework\\Attributes\\CoversFunction( 'automator_get_option' )]", $result );
		$this->assertSame( 1, substr_count( $result, 'Cart::class' ), 'Two tests covering one method give one attribute.' );
		$this->assertStringContainsString( "#[\\PHPUnit\\Framework\\Attributes\\CoversClass( \\Vendor\\Pkg\\Tax::class )]\n", $result );
		$this->assertMatchesRegularExpression( '/\\)\\]\\nclass Order_Test \\{/', $result, 'The attributes sit on the class.' );
		$this->assertStringNotContainsString( '@covers', $result );
		$this->assertStringContainsString( " * Orders.\n *\n * @group orders\n */", $result, 'Other docblock content stays.' );
		$this->assertStringContainsString( "\t * Ships it.\n\t */", $result );
		$this->assertStringNotContainsString( " *\n */", $this->process( "<?php\n/**\n * Summary.\n *\n * @covers \\Foo\n */\nclass H_Test {}\n", true ), 'No blank line is left above the close.' );
		$this->assertStringNotContainsString( "\t/**\n\t */", $result, 'A docblock left empty goes.' );
		$this->assertStringContainsString( "}\n\n\tpublic function test_c() {}", $result, 'A one-line docblock that held only a coverage tag goes.' );
	}

	public function test_a_whole_class_target_absorbs_its_methods_and_covers_nothing_stays_alone() {
		$result = $this->process( "<?php\n/**\n * @covers \\Foo\n * @covers \\Foo::bar\n */\nclass D_Test {}\n/**\n * @coversNothing\n */\nclass E_Test {}\n", true );

		$this->assertStringContainsString( "CoversClass( \\Foo::class )", $result );
		$this->assertStringNotContainsString( 'CoversMethod', $result );
		$this->assertStringContainsString( "#[\\PHPUnit\\Framework\\Attributes\\CoversNothing]\nclass E_Test", $result );
	}

	public function test_double_colon_without_a_default_class_is_a_global_function() {
		$result = $this->process( "<?php\nclass I_Test {\n\t/**\n\t * @covers ::automator_sort_options\n\t */\n\tpublic function test_a() {}\n}\n", true );

		$this->assertStringContainsString( "#[\\PHPUnit\\Framework\\Attributes\\CoversFunction( 'automator_sort_options' )]\nclass I_Test", $result );
	}

	public function test_a_tag_inside_a_one_line_docblock_goes_and_its_text_stays() {
		$result = $this->process( "<?php\nclass J_Test {\n\t/** Business rule: ids persist. @covers \\Repo::set_id */\n\tpublic function test_a() {}\n}\n", true );

		$this->assertStringContainsString( "\t/** Business rule: ids persist. */\n", $result );
		$this->assertStringContainsString( "CoversClass( \\Repo::class )]\nclass J_Test", $result );
	}

	public function test_a_file_header_docblock_covers_the_files_one_class() {
		$result = $this->process( "<?php\n/**\n * Zapier helpers.\n *\n * @covers \\Uncanny_Automator\\Zapier_Helpers\n */\n\nuse Foo\\Bar;\n\nclass K_Test {}\n", true );

		$this->assertStringContainsString( "#[\\PHPUnit\\Framework\\Attributes\\CoversClass( \\Uncanny_Automator\\Zapier_Helpers::class )]\nclass K_Test", $result );
		$this->assertStringContainsString( "/**\n * Zapier helpers.\n */", $result );
	}

	public function test_covers_nothing_on_a_method_stays_on_that_method() {
		$result = $this->process( "<?php\n/**\n * @covers \\Svc\n */\nclass L_Test {\n\t/**\n\t * @coversNothing\n\t */\n\tpublic function test_smoke() {}\n}\n", true );

		$this->assertStringContainsString( "\t#[\\PHPUnit\\Framework\\Attributes\\CoversNothing]\n\tpublic function test_smoke", $result );
		$this->assertStringContainsString( "CoversClass( \\Svc::class )]\nclass L_Test", $result );
	}

	public function test_requires_tags_become_attributes() {
		$result = $this->process( "<?php\nclass M_Test {\n\t/**\n\t * @requires PHP 8.1\n\t * @requires extension mysqli\n\t * @requires function iconv\n\t * @requires PHPUnit >= 10\n\t * @requires PHP ^8.1\n\t */\n\tpublic function test_a() {}\n}\n" );

		$this->assertStringContainsString( "RequiresPhp( '>= 8.1.0' )", $result, 'A bare version means at least that version, in the full form PHPUnit 13 compares.' );
		$this->assertStringContainsString( "RequiresPhp( '^8.1' )", $result, 'A Composer-style constraint passes as it is.' );
		$this->assertStringContainsString( "RequiresPhpExtension( 'mysqli' )", $result );
		$this->assertStringContainsString( "RequiresFunction( 'iconv' )", $result );
		$this->assertStringContainsString( "RequiresPhpunit( '>= 10.0.0' )", $result );
	}

	public function test_with_a_source_index_targets_are_checked_against_what_exists() {
		$src = sys_get_temp_dir() . '/attr-src-' . uniqid();
		mkdir( $src );
		file_put_contents( $src . '/a.php', "<?php\nnamespace Plugin\\Svc;\nclass Base { public function shared() {} }\nclass Cart extends Base { public function total() {} }\nfunction helper() {}\n" );
		file_put_contents( $src . '/b.php', "<?php\nfunction automator_sort_options() {}\n" );

		$adder = new Attribute_Adder();
		$adder->use_source_index( Source_Index::build( array( $src ) ) );
		file_put_contents(
			$this->file,
			"<?php\n/**\n * @coversDefaultClass \\Plugin\\Svc\\Cart\n */\nclass N_Test {\n\t/**\n\t * @covers ::total\n\t * @covers ::shared\n\t * @covers ::automator_sort_options\n\t * @covers \\Plugin\\Wrong\\Cart::total\n\t * @covers \\Plugin\\Svc\\Cart::gone\n\t * @covers \\Plugin\\Svc\\Missing\n\t */\n\tpublic function test_a() {}\n\n\t/**\n\t * Business rule: totals add up.\n\t *\n\t * @covers \\Plugin\\Svc\\helper()\n\t */\n\t/**\n\t * @runInSeparateProcess\n\t */\n\tpublic function test_b() {}\n}\n"
		);
		$adder->process_file( $this->file, false, true );
		$result = (string) file_get_contents( $this->file );
		$notes  = implode( "\n", $adder->notes );
		exec( 'rm -rf ' . escapeshellarg( $src ) );

		$this->assertSame( 1, substr_count( $result, 'CoversClass( \\Plugin\\Svc\\Cart::class )' ), 'Own and inherited methods both exist, and give the one class.' );
		$this->assertStringNotContainsString( 'has no method shared', $notes, 'An inherited method exists.' );
		$this->assertStringContainsString( "CoversFunction( 'automator_sort_options' )", $result, '::name is the function when the default class has no such method.' );
		$this->assertStringContainsString( "CoversFunction( 'Plugin\\\\Svc\\\\helper' )", $result, 'A tag in the earlier of two docblocks is found.' );
		$this->assertStringContainsString( 'the one class of that name is \\Plugin\\Svc\\Cart', $notes, 'A wrong namespace resolves to the only class of that name, and says so.' );
		$this->assertStringContainsString( '@covers \\Plugin\\Svc\\Cart::gone', $result, 'A missing method is not converted: its line stays.' );
		$this->assertStringContainsString( 'has no method gone', $notes );
		$this->assertStringContainsString( '@covers \\Plugin\\Svc\\Missing', $result );
		$this->assertStringContainsString( '\\Plugin\\Svc\\Missing does not exist', $notes );
		$this->assertStringNotContainsString( '@covers ::total', $result );
	}

	public function test_trait_methods_exist_and_an_alias_becomes_its_real_class() {
		$src = sys_get_temp_dir() . '/attr-src-' . uniqid();
		mkdir( $src );
		file_put_contents( $src . '/a.php', "<?php\nnamespace Plugin;\ntrait Dots { public function by_dots() {} }\nclass Token { use Dots; }\nclass Client { public function send() {} }\nclass_alias( Client::class, 'Plugin\\\\Old\\\\Api_Client' );\nclass_alias( '\\\\Plugin\\\\Client', 'Legacy_Client' );\n" );

		$adder = new Attribute_Adder();
		$adder->use_source_index( Source_Index::build( array( $src ) ) );
		file_put_contents(
			$this->file,
			"<?php\nclass P_Test {\n\t/**\n\t * @covers \\Plugin\\Token::by_dots\n\t * @covers \\Plugin\\Old\\Api_Client::send\n\t * @covers \\Legacy_Client\n\t */\n\tpublic function test_a() {}\n}\n"
		);
		$adder->process_file( $this->file, false, true );
		$result = (string) file_get_contents( $this->file );
		$notes  = implode( "\n", $adder->notes );
		exec( 'rm -rf ' . escapeshellarg( $src ) );

		$this->assertStringContainsString( 'CoversClass( \\Plugin\\Token::class )', $result, 'A method from a used trait exists on the class.' );
		$this->assertStringNotContainsString( 'by_dots', $notes );
		$this->assertStringContainsString( 'CoversClass( \\Plugin\\Client::class )', $result, 'A class_alias() name is covered as its real class.' );
		$this->assertStringNotContainsString( 'Api_Client', $result );
		$this->assertStringNotContainsString( 'Legacy_Client', $result );
		$this->assertStringContainsString( '\\Plugin\\Old\\Api_Client is a class_alias() of \\Plugin\\Client', $notes );
	}

	public function test_a_trait_takes_covers_trait_and_an_interface_is_refused() {
		$src = sys_get_temp_dir() . '/attr-src-' . uniqid();
		mkdir( $src );
		file_put_contents( $src . '/a.php', "<?php\nnamespace Plugin;\ntrait Setup { public function setup() {} }\ninterface Resolver { public function resolve(); }\n" );

		$adder = new Attribute_Adder();
		$adder->use_source_index( Source_Index::build( array( $src ) ) );
		file_put_contents( $this->file, "<?php\nclass Q_Test {\n\t/**\n\t * @covers \\Plugin\\Setup::setup\n\t * @covers \\Plugin\\Resolver\n\t */\n\tpublic function test_a() {}\n}\n" );
		$adder->process_file( $this->file, false, true );
		$result = (string) file_get_contents( $this->file );
		$notes  = implode( "\n", $adder->notes );
		exec( 'rm -rf ' . escapeshellarg( $src ) );

		$this->assertStringContainsString( 'CoversTrait( \\Plugin\\Setup::class )', $result, 'PHPUnit rejects a trait given to CoversClass.' );
		$this->assertStringNotContainsString( 'CoversClass( \\Plugin\\Resolver', $result );
		$this->assertStringContainsString( '@covers \\Plugin\\Resolver', $result, 'An interface target stays for a fix by hand.' );
		$this->assertStringContainsString( 'is an interface and holds no code', $notes );
	}

	public function test_a_method_tag_without_a_default_class_belongs_to_the_one_class_the_test_covers() {
		$src = sys_get_temp_dir() . '/attr-src-' . uniqid();
		mkdir( $src );
		file_put_contents( $src . '/a.php', "<?php\nnamespace Plugin;\nclass Helpers { public function form_config() {} }\nfunction shared() {}\n" );

		$adder = new Attribute_Adder();
		$adder->use_source_index( Source_Index::build( array( $src ) ) );
		file_put_contents( $this->file, "<?php\n/**\n * @covers \\Plugin\\Helpers\n */\nclass R_Test {\n\t/**\n\t * @covers ::form_config\n\t * @covers ::Plugin\\shared\n\t * @covers ::gone\n\t */\n\tpublic function test_a() {}\n}\n" );
		$adder->process_file( $this->file, false, true );
		$result = (string) file_get_contents( $this->file );
		$notes  = implode( "\n", $adder->notes );
		exec( 'rm -rf ' . escapeshellarg( $src ) );

		$this->assertSame( 1, substr_count( $result, 'CoversClass( \\Plugin\\Helpers::class )' ) );
		$this->assertStringNotContainsString( '@covers ::form_config', $result, 'The method of the covered class converts.' );
		$this->assertStringContainsString( 'it is a method of \\Plugin\\Helpers', $notes );
		$this->assertStringContainsString( "CoversFunction( 'Plugin\\\\shared' )", $result, 'A function of that name keeps PHPUnit\'s reading.' );
		$this->assertStringContainsString( '@covers ::gone', $result, 'A method the class lacks stays for a fix by hand.' );
	}

	public function test_context_classes_count_for_inheritance_and_are_never_targets() {
		$base  = sys_get_temp_dir() . '/attr-base-' . uniqid();
		$addon = sys_get_temp_dir() . '/attr-addon-' . uniqid();
		mkdir( $base );
		mkdir( $addon );
		file_put_contents( $base . '/a.php', "<?php\nnamespace Core;\nabstract class Trigger { public function register_trigger() {} }\n" );
		file_put_contents( $addon . '/b.php', "<?php\nnamespace Addon;\nclass Joined extends \\Core\\Trigger {}\n" );

		$adder = new Attribute_Adder();
		$adder->use_source_index( Source_Index::build( array( $addon ), array( $base ) ) );
		file_put_contents( $this->file, "<?php\n/**\n * @coversDefaultClass \\Addon\\Joined\n */\nclass S_Test {\n\t/**\n\t * @covers ::register_trigger\n\t * @covers \\Core\\Trigger\n\t */\n\tpublic function test_a() {}\n}\n" );
		$adder->process_file( $this->file, false, true );
		$result = (string) file_get_contents( $this->file );
		$notes  = implode( "\n", $adder->notes );
		exec( 'rm -rf ' . escapeshellarg( $base ) . ' ' . escapeshellarg( $addon ) );

		$this->assertStringContainsString( 'CoversClass( \\Addon\\Joined::class )', $result, 'A method inherited from the parent plugin exists.' );
		$this->assertStringNotContainsString( 'CoversClass( \\Core\\Trigger', $result );
		$this->assertStringContainsString( '@covers \\Core\\Trigger', $result, "The parent plugin's class stays for a fix by hand." );
		$this->assertStringContainsString( 'declared outside the source directories', $notes );
	}

	public function test_coverage_conversion_runs_once() {
		$code  = "<?php\n/**\n * @covers \\Foo::bar\n */\nclass F_Test {\n}\n";
		$first = $this->process( $code, true );
		file_put_contents( $this->file, $first );
		( new Attribute_Adder() )->process_file( $this->file, true, true );

		$this->assertSame( $first, (string) file_get_contents( $this->file ) );
	}

	public function test_without_the_flag_coverage_tags_stay_and_are_reported() {
		$adder = new Attribute_Adder();
		file_put_contents( $this->file, "<?php\n/**\n * @covers \\Foo\n */\nclass G_Test {}\n" );
		$adder->process_file( $this->file, false );

		$this->assertStringContainsString( '@covers \\Foo', (string) file_get_contents( $this->file ) );
		$this->assertStringContainsString( 'pass --covers', implode( "\n", $adder->notes ) );
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
