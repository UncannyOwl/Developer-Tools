<?php
/**
 * How bin/mutate sets up one run, without running Infection: the coverage source, the initial run's
 * verdict, and when a mutant run may stop. Mutate_Infection_Test runs the same rules through Infection.
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Uncanny_Owl\Wp_Test_Kernel\Mutate\Coverage_Targets;
use Uncanny_Owl\Wp_Test_Kernel\Mutate\Infection_Runner;
use Uncanny_Owl\Wp_Test_Kernel\Mutate\Scope;
use Uncanny_Owl\Wp_Test_Kernel\Tools\Source_Index;

#[CoversClass( Infection_Runner::class )]
#[CoversClass( Coverage_Targets::class )]
#[CoversClass( Source_Index::class )]
final class Mutate_Runner_Test extends TestCase {

	/** @var string */
	private $root;

	protected function setUp(): void {
		$this->root = (string) realpath( sys_get_temp_dir() ) . '/mutate-runner-' . uniqid();
		$files      = array(
			'src/Alpha.php'              => "<?php\nnamespace Plugin;\nclass Alpha extends Base { use Helper_Trait; }\n",
			'src/Base.php'               => "<?php\nnamespace Plugin;\nabstract class Base {}\n",
			'src/Helper_Trait.php'       => "<?php\nnamespace Plugin;\ntrait Helper_Trait { use Deep_Trait; }\n",
			'src/Deep_Trait.php'         => "<?php\nnamespace Plugin;\ntrait Deep_Trait {}\n",
			'src/Beta.php'               => "<?php\nnamespace Plugin;\nfinal class Beta {}\n",
			'src/gamma.php'              => "<?php\nnamespace Plugin\\Gamma;\nfunction gamma_fn() {}\n",
			'src/Delta/Thing.php'        => "<?php\nnamespace Plugin\\Delta;\nclass Thing {}\n",
			'src/Delta/Inner/Other.php'  => "<?php\nnamespace Plugin\\Delta\\Inner;\nclass Other {}\n",
			'src/Contract.php'           => "<?php\nnamespace Plugin;\ninterface Contract {}\n",
			'src/Impl.php'               => "<?php\nnamespace Plugin;\nclass Impl extends Base implements Contract {}\n",
			'src/Unrelated.php'          => "<?php\nnamespace Plugin;\nclass Unrelated {}\n",
			'src/listed.php'             => "<?php\n",
			'src/views/Page.php'         => "<?php\nnamespace Plugin;\nclass Page {}\n",
			'tests/unit/Beta_Test.php'   => "<?php\nnamespace Tests;\nuse PHPUnit\\Framework\\Attributes as A;\nuse Plugin\\Alpha;\n\n"
				. "#[A\\CoversClass( \\Plugin\\Beta::class )]\n"
				. "final class Beta_Test extends \\PHPUnit\\Framework\\TestCase {}\n",
			'tests/unit/Alpha_Test.php'  => "<?php\nnamespace Tests;\nuse PHPUnit\\Framework\\Attributes as A;\nuse Plugin\\Alpha;\n\n"
				. "#[A\\CoversClass( Alpha::class )]\n"
				. "#[A\\CoversFunction( 'Plugin\\\\Gamma\\\\gamma_fn' )]\n"
				. "#[A\\UsesNamespace( 'Plugin\\\\Delta' )]\n"
				. "#[A\\CoversClassesThatImplementInterface( \\Plugin\\Contract::class )]\n"
				. "#[A\\CoversFile( __DIR__ . '/../../src/listed.php' )]\n"
				. "#[A\\CoversClass( \\Plugin\\Page::class )]\n"
				. "final class Alpha_Test extends \\PHPUnit\\Framework\\TestCase {}\n",
			'phpunit.xml.dist'           => '<?xml version="1.0"?>' . "\n"
				. '<phpunit bootstrap="tests/bootstrap.php" failOnWarning="true" failOnRisky="true">'
				. '<testsuites><testsuite name="unit"><directory>tests/unit</directory></testsuite></testsuites>'
				. '<source><include><directory suffix=".php">src</directory></include><exclude><directory>src/views</directory></exclude></source>'
				. '<logging><junit outputFile="build/junit.xml"/></logging>'
				. "</phpunit>\n",
		);
		foreach ( $files as $path => $content ) {
			if ( ! is_dir( dirname( $this->root . '/' . $path ) ) ) {
				mkdir( dirname( $this->root . '/' . $path ), 0777, true );
			}
			file_put_contents( $this->root . '/' . $path, $content );
		}
	}

	protected function tearDown(): void {
		exec( 'rm -rf ' . escapeshellarg( $this->root ) );
	}

	/**
	 * @return Infection_Runner
	 */
	private function runner() {
		return new Infection_Runner( new Scope( $this->root, array( 'paths' => array( 'src' ) ) ), $this->root, 1, '' );
	}

	/**
	 * Writes the run's PHPUnit config and returns it.
	 *
	 * @param array{files: string[], tests: string[]} $run
	 *
	 * @return DOMDocument
	 */
	private function phpunit_config( array $run ) {
		$dir = $this->root . '/run';
		mkdir( $dir );
		( new ReflectionMethod( Infection_Runner::class, 'write_phpunit_config' ) )->invoke( $this->runner(), $dir, $run );

		$doc = new DOMDocument();
		$doc->load( $dir . '/phpunit.xml' );

		return $doc;
	}

	/**
	 * @param DOMDocument $doc
	 *
	 * @return string[] Plugin-relative files of the config's <source><include>, sorted.
	 */
	private function source_files( DOMDocument $doc ) {
		$files = array();
		foreach ( $doc->getElementsByTagName( 'include' )->item( 0 )->childNodes as $node ) {
			if ( $node instanceof DOMElement ) {
				$files[] = substr( $node->textContent, strlen( $this->root ) + 1 );
			}
		}
		sort( $files );

		return $files;
	}

	public function test_the_coverage_source_holds_the_run_and_every_file_its_tests_name_as_targets(): void {
		$doc = $this->phpunit_config(
			array(
				'files' => array( 'src/Beta.php' ),
				'tests' => array( 'tests/unit/Alpha_Test.php', 'tests/unit/Beta_Test.php' ),
			)
		);

		$this->assertSame(
			array(
				'src/Alpha.php',          // CoversClass.
				'src/Base.php',           // Alpha's parent: PHPUnit credits its lines to Alpha.
				'src/Beta.php',           // The run's own file.
				'src/Contract.php',       // CoversClassesThatImplementInterface: the interface and ...
				'src/Deep_Trait.php',     // A trait Alpha's trait uses.
				'src/Delta/Inner/Other.php', // UsesNamespace reaches the namespaces below it.
				'src/Delta/Thing.php',
				'src/Helper_Trait.php',
				'src/Impl.php',           // ... every class implementing it.
				'src/gamma.php',          // CoversFunction.
				'src/listed.php',         // CoversFile.
			),
			$this->source_files( $doc ),
			'Unrelated.php is named by no test; views/Page.php is outside the plugin\'s <source>, so its target stays invalid, as in the plugin\'s own run.'
		);
	}

	public function test_a_source_that_reads_which_files_are_first_party_stays_whole(): void {
		$config = $this->root . '/phpunit.xml.dist';
		file_put_contents( $config, str_replace( '<source>', '<source restrictWarnings="true" baseline="tests/baseline.xml">', (string) file_get_contents( $config ) ) );

		$doc    = $this->phpunit_config( array( 'files' => array( 'src/Beta.php' ), 'tests' => array( 'tests/unit/Beta_Test.php' ) ) );
		$source = $doc->getElementsByTagName( 'source' )->item( 0 );

		$this->assertSame( 'true', $source->getAttribute( 'restrictWarnings' ), 'Which warnings count is the plugin\'s setting.' );
		$this->assertSame( $this->root . '/tests/baseline.xml', $source->getAttribute( 'baseline' ) );
		$this->assertSame( $this->root . '/src', $source->getElementsByTagName( 'include' )->item( 0 )->getElementsByTagName( 'directory' )->item( 0 )->textContent, 'Narrowed, warnings from the plugin\'s other files would stop counting.' );
		$this->assertSame( $this->root . '/src/views', $source->getElementsByTagName( 'exclude' )->item( 0 )->getElementsByTagName( 'directory' )->item( 0 )->textContent );
	}

	public function test_the_initial_run_is_judged_by_phpunits_exit_code_not_by_its_stderr(): void {
		// A stand-in PHPUnit: warns on stderr while it boots (as Automator does without built assets),
		// writes the coverage Infection reads, and exits with STUB_EXIT.
		mkdir( $this->root . '/vendor/bin', 0777, true );
		file_put_contents(
			$this->root . '/vendor/bin/phpunit',
			'<?php' . "\n"
			. 'fwrite( STDERR, "Warning: Asset file not found for handle \"uap-redirect\"\n" );' . "\n"
			. '$xml = $argv[ array_search( "--coverage-xml", $argv, true ) + 1 ];' . "\n"
			. '$junit = $argv[ array_search( "--log-junit", $argv, true ) + 1 ];' . "\n"
			. 'mkdir( $xml, 0777, true );' . "\n"
			. 'file_put_contents( $xml . "/index.xml", "<phpunit/>" );' . "\n"
			. 'file_put_contents( $junit, "<testsuites/>" );' . "\n"
			. 'echo "OK (1 test, 1 assertion)\n";' . "\n"
			. 'exit( (int) getenv( "STUB_EXIT" ) );' . "\n"
		);
		$dir = $this->root . '/run';
		mkdir( $dir );
		$initial = new ReflectionMethod( Infection_Runner::class, 'run_initial_tests' );

		putenv( 'STUB_EXIT=0' );
		$passed = $initial->invoke( $this->runner(), $dir );
		putenv( 'STUB_EXIT=1' );
		$failed = $initial->invoke( $this->runner(), $dir );
		putenv( 'STUB_EXIT' );

		$this->assertNull( $passed, 'PHPUnit passed, as `composer unit-tests` would; the warning on stderr is no verdict.' );
		$this->assertStringContainsString( 'Asset file not found', (string) file_get_contents( $dir . '/initial-tests.err' ) );
		$this->assertStringContainsString( 'PHPUnit exited 1', (string) $failed );
	}
}
