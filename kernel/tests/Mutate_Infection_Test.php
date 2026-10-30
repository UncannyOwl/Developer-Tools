<?php
/**
 * bin/mutate end to end: real Infection over a small fixture plugin (no WordPress), judged as the
 * plugin's own test run judges it.
 *
 * Needs a coverage driver for the run's initial tests, as bin/mutate does: Xdebug or PCOV loaded, or
 * WP_TEST_KERNEL_COVERAGE_INI_DIR / WP_TEST_KERNEL_COVERAGE_OPTIONS set. Without one these tests are
 * skipped and say so; Mutate_Runner_Test covers each rule without running Infection.
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Uncanny_Owl\Wp_Test_Kernel\Mutate\Cli;
use Uncanny_Owl\Wp_Test_Kernel\Mutate\Infection_Runner;

#[CoversClass( Infection_Runner::class )]
final class Mutate_Infection_Test extends TestCase {

	/** @var string The fixture plugin, copied for this test. */
	private $root;

	protected function setUp(): void {
		if ( ! extension_loaded( 'xdebug' ) && ! extension_loaded( 'pcov' ) && ! getenv( 'WP_TEST_KERNEL_COVERAGE_INI_DIR' ) && ! getenv( 'WP_TEST_KERNEL_COVERAGE_OPTIONS' ) ) {
			$this->markTestSkipped( 'Runs Infection, which needs a coverage driver: load Xdebug or PCOV, or set WP_TEST_KERNEL_COVERAGE_INI_DIR or WP_TEST_KERNEL_COVERAGE_OPTIONS as for bin/mutate.' );
		}

		$this->root = sys_get_temp_dir() . '/mutate-infection-' . uniqid();
		exec( sprintf( 'cp -R %s %s', escapeshellarg( __DIR__ . '/fixtures/mutate' ), escapeshellarg( $this->root ) ) );
		symlink( dirname( __DIR__, 2 ) . '/vendor', $this->root . '/vendor' );
	}

	protected function tearDown(): void {
		putenv( 'MUTATE_FIXTURE_BOOT_WARNING' );
		if ( null !== $this->root ) {
			exec( 'rm -rf ' . escapeshellarg( $this->root ) );
		}
	}

	/**
	 * @param string $path The plugin-relative file to mutate.
	 *
	 * @return string What bin/mutate printed.
	 */
	private function mutate( $path ) {
		ob_start();
		Cli::main( array( 'bin/mutate', '--plugin-path=' . $this->root, '--path=' . $path, '--report-unbaselined' ) );

		return (string) ob_get_clean();
	}

	/**
	 * The run's mutants from Infection's JSON log.
	 *
	 * @return array<int, array{status: string, file: string, line: int, mutator: string, output: string}>
	 */
	private function mutants() {
		$log     = json_decode( (string) file_get_contents( $this->root . '/tests/_output/mutate/run-1/infection.json' ), true );
		$mutants = array();
		foreach ( array( 'killed', 'escaped', 'errored', 'timeouted', 'uncovered' ) as $status ) {
			foreach ( (array) ( $log[ $status ] ?? array() ) as $mutant ) {
				$mutants[] = array(
					'status'  => $status,
					'file'    => substr( (string) realpath( $mutant['mutator']['originalFilePath'] ), strlen( (string) realpath( $this->root ) ) + 1 ),
					'line'    => (int) $mutant['mutator']['originalStartLine'],
					'mutator' => (string) $mutant['mutator']['mutatorName'],
					'output'  => (string) ( $mutant['processOutput'] ?? '' ),
				);
			}
		}

		return $mutants;
	}

	public function test_a_warning_the_plugin_shows_while_it_boots_does_not_stop_the_run(): void {
		putenv( 'MUTATE_FIXTURE_BOOT_WARNING=1' );

		$output = $this->mutate( 'src/Picker.php' );

		$this->assertStringNotContainsString( 'STOPPED', $output, 'A normal test run passes with this warning on stderr, so the initial run must too.' );
		$this->assertNotEmpty( $this->mutants() );
		$this->assertStringContainsString( 'Asset file not found', (string) file_get_contents( $this->root . '/tests/_output/mutate/run-1/initial-tests.err' ), 'The warning is kept for the reader.' );
	}
}
