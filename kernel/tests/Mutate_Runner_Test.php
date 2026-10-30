<?php
/**
 * How bin/mutate sets up one run, without running Infection: the initial run's verdict.
 * Mutate_Infection_Test runs the same rules through Infection.
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Uncanny_Owl\Wp_Test_Kernel\Mutate\Infection_Runner;
use Uncanny_Owl\Wp_Test_Kernel\Mutate\Scope;

#[CoversClass( Infection_Runner::class )]
final class Mutate_Runner_Test extends TestCase {

	/** @var string */
	private $root;

	protected function setUp(): void {
		$this->root = (string) realpath( sys_get_temp_dir() ) . '/mutate-runner-' . uniqid();
		mkdir( $this->root, 0777, true );
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
