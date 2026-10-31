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
use Uncanny_Owl\Wp_Test_Kernel\Mutate\Coverage_Targets;
use Uncanny_Owl\Wp_Test_Kernel\Mutate\Infection_Runner;

#[CoversClass( Infection_Runner::class )]
#[CoversClass( Coverage_Targets::class )]
final class Mutate_Infection_Test extends TestCase {

	/** @var string The fixture plugin, copied for this test. */
	private $root;

	protected function setUp(): void {
		if ( ! extension_loaded( 'xdebug' ) && ! extension_loaded( 'pcov' ) && ! getenv( 'WP_TEST_KERNEL_COVERAGE_INI_DIR' ) && ! getenv( 'WP_TEST_KERNEL_COVERAGE_OPTIONS' ) ) {
			$this->markTestSkipped( 'Runs Infection, which needs a coverage driver: load Xdebug or PCOV, or set WP_TEST_KERNEL_COVERAGE_INI_DIR or WP_TEST_KERNEL_COVERAGE_OPTIONS as for bin/mutate.' );
		}

		$this->root = sys_get_temp_dir() . '/mutate-infection-' . uniqid();
		exec( sprintf( 'cp -R %s %s', escapeshellarg( __DIR__ . '/fixtures/mutate' ), escapeshellarg( $this->root ) ) );
		// The fixture keeps its test tools in a Composer project of their own (Automator Free's
		// tests/tools), and has no vendor/ of its own: bin/mutate runs the PHPUnit and Infection
		// Developer-Tools was installed with.
		mkdir( $this->root . '/tests/tools', 0777, true );
		symlink( dirname( __DIR__, 2 ) . '/vendor', $this->root . '/tests/tools/vendor' );
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

	/**
	 * @param string $mutator
	 * @param string $code    A fragment of the mutated line in src/Picker.php.
	 *
	 * @return array{status: string, file: string, line: int, mutator: string, output: string}
	 */
	private function picker_mutant( $mutator, $code ) {
		$line = 0;
		foreach ( file( $this->root . '/src/Picker.php' ) as $number => $text ) {
			if ( false !== strpos( $text, $code ) && false === strpos( $text, '`' ) ) {
				$line = $number + 1;
			}
		}
		foreach ( $this->mutants() as $mutant ) {
			if ( $mutator === $mutant['mutator'] && $line === $mutant['line'] ) {
				return $mutant;
			}
		}
		$this->fail( sprintf( 'No %s mutant on line %d of src/Picker.php.', $mutator, $line ) );
	}

	public function test_a_warning_the_plugin_shows_while_it_boots_does_not_stop_the_run(): void {
		putenv( 'MUTATE_FIXTURE_BOOT_WARNING=1' );

		$output = $this->mutate( 'src/Picker.php' );

		$this->assertStringNotContainsString( 'STOPPED', $output, 'A normal test run passes with this warning on stderr, so the initial run must too.' );
		$this->assertNotEmpty( $this->mutants() );
		$this->assertStringContainsString( 'Asset file not found', (string) file_get_contents( $this->root . '/tests/_output/mutate/run-1/initial-tests.err' ), 'The warning is kept for the reader.' );
	}

	public function test_tests_that_cover_classes_outside_the_run_neither_stop_it_nor_get_them_mutated(): void {
		$output = $this->mutate( 'src/Shelf/Shelf.php' );

		$this->assertStringNotContainsString( 'STOPPED', $output, 'Label_Test, beside Shelf_Test, covers Label: a valid target in the plugin\'s <source>.' );
		$this->assertSame( array( 'src/Shelf/Shelf.php' ), array_values( array_unique( array_column( $this->mutants(), 'file' ) ) ), 'Only the run\'s file is mutated.' );
		$this->assertNotContains( 'uncovered', array_column( $this->mutants(), 'status' ), 'Shelf_Test\'s coverage of Shelf counts (PHPUnit drops a test\'s coverage when one of its targets is invalid).' );
	}

	public function test_a_mutant_only_a_warning_shows_is_killed_when_the_plugin_fails_on_warnings(): void {
		$this->mutate( 'src/Picker.php' );

		$mutant = $this->picker_mutant( 'GreaterThan', 'count( $items ) > 0' );
		$this->assertSame( 'killed', $mutant['status'] );
		$this->assertStringContainsString( 'PHP warning', $mutant['output'], 'Every assertion passes on this mutant; the warning kills it.' );
	}

	public function test_a_mutant_run_does_not_stop_at_a_warning_the_plugin_lets_pass(): void {
		$config = $this->root . '/phpunit.xml.dist';
		file_put_contents( $config, str_replace( 'failOnWarning="true"', 'failOnWarning="false"', (string) file_get_contents( $config ) ) );

		$this->mutate( 'src/Picker.php' );

		$this->assertSame( 'killed', $this->picker_mutant( 'LessThan', '$index < count( $items )' )['status'], 'The first covering test only warns; the next one fails, as the plugin\'s own run would show.' );
		$this->assertSame( 'escaped', $this->picker_mutant( 'GreaterThan', 'count( $items ) > 0' )['status'], 'With failOnWarning off the plugin\'s own run passes this mutant, so it escapes.' );
	}
}
