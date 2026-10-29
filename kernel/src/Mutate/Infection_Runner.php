<?php

namespace Uncanny_Owl\Wp_Test_Kernel\Mutate;

use DOMDocument;
use RuntimeException;

/**
 * Runs Infection for one plan run: the run's files mutated, judged only by the run's tests.
 *
 * Writes a PHPUnit config holding just those tests (and a coverage source of just those files) and an
 * Infection config beside it, then reads Infection's JSON log. Infection drives PHPUnit 13 directly,
 * runs only the tests covering each mutated line, and gives each thread its own TEST_TOKEN, so each
 * thread gets its own kernel database.
 */
final class Infection_Runner {

	/** @var Scope */
	private $scope;

	/** @var string Absolute path of the tests/kernel project. */
	private $kernel_dir;

	/** @var int */
	private $threads;

	/** @var string */
	private $coverage_options;

	/**
	 * @param Scope  $scope
	 * @param string $kernel_dir
	 * @param int    $threads
	 * @param string $coverage_options PHP options that turn a coverage driver on for the initial run.
	 */
	public function __construct( Scope $scope, $kernel_dir, $threads, $coverage_options ) {
		$this->scope            = $scope;
		$this->kernel_dir       = rtrim( $kernel_dir, '/' );
		$this->threads          = max( 1, (int) $threads );
		$this->coverage_options = (string) $coverage_options;
	}

	/**
	 * @param int                                        $index
	 * @param array{files: string[], tests: string[]}    $run
	 * @param string[]                                   $extra_args Inspection arguments passed to Infection.
	 *
	 * @return array{tallies: array<string, Tally>, stopped: string|null, seconds: int}
	 */
	public function run( $index, array $run, array $extra_args = array() ) {

		$dir = $this->kernel_dir . '/.cache/mutate/run-' . $index;
		$this->reset_dir( $dir );

		$root = $this->scope->root();
		$this->write_phpunit_config( $dir, $run );
		$this->write_infection_config( $dir, $run );

		// A coverage driver loaded through an ini scan directory reaches PHPUnit's process-isolated
		// children too; `-d` options (coverage_options) reach only the initial run's own process.
		$ini_dir = (string) ( getenv( 'WP_TEST_KERNEL_COVERAGE_INI_DIR' ) ?: $this->scope->get( 'coverage_ini_dir' ) );
		$env     = '' === $ini_dir ? '' : 'PHP_INI_SCAN_DIR=' . escapeshellarg( ':' . $ini_dir ) . ' ';

		$filter  = implode( ',', $run['files'] );
		$command = sprintf(
			'cd %s && ' . $env . '%s vendor/bin/infection --configuration=%s --threads=%d --filter=%s --only-covering-test-cases --no-progress --no-interaction --initial-tests-php-options=%s %s 2>&1',
			escapeshellarg( $this->kernel_dir ),
			escapeshellarg( PHP_BINARY ),
			escapeshellarg( $dir . '/infection.json5' ),
			$this->threads,
			escapeshellarg( $filter ),
			escapeshellarg( $this->coverage_options ),
			implode( ' ', array_map( 'escapeshellarg', $extra_args ) )
		);

		$started = time();
		$output  = array();
		$exit    = 0;
		exec( $command, $output, $exit );
		file_put_contents( $dir . '/infection.out', implode( "\n", $output ) );

		$log = $dir . '/infection.json';
		if ( 0 !== $exit || ! is_readable( $log ) ) {
			return array(
				'tallies' => array(),
				'stopped' => sprintf( 'Infection exited %d before writing its log (see %s/infection.out)', $exit, $dir ),
				'seconds' => time() - $started,
			);
		}

		return array(
			'tallies' => Tally::per_file_from_infection( $log, $root ),
			'stopped' => null,
			'seconds' => time() - $started,
		);
	}

	/**
	 * @param string                                  $dir
	 * @param array{files: string[], tests: string[]} $run
	 *
	 * @return void
	 */
	private function write_phpunit_config( $dir, array $run ) {
		$base = $this->kernel_dir . '/' . ( $this->scope->get( 'phpunit' ) ?: 'phpunit.xml' );
		$doc  = new DOMDocument();
		$doc->preserveWhiteSpace = false;
		$doc->formatOutput       = true;
		if ( ! $doc->load( $base ) ) {
			throw new RuntimeException( 'Cannot read ' . $base );
		}

		$phpunit = $doc->documentElement;
		$phpunit->setAttribute( 'bootstrap', $this->kernel_dir . '/' . $phpunit->getAttribute( 'bootstrap' ) );
		$phpunit->setAttribute( 'cacheDirectory', $dir . '/phpunit-cache' );
		$phpunit->removeAttribute( 'xsi:noNamespaceSchemaLocation' );

		foreach ( array( 'testsuites', 'source', 'coverage' ) as $tag ) {
			while ( $node = $doc->getElementsByTagName( $tag )->item( 0 ) ) {
				$node->parentNode->removeChild( $node );
			}
		}

		$suites = $doc->createElement( 'testsuites' );
		$suite  = $doc->createElement( 'testsuite' );
		$suite->setAttribute( 'name', 'mutate' );
		foreach ( $run['tests'] as $test ) {
			$suite->appendChild( $doc->createElement( 'file', $this->scope->root() . '/' . $test ) );
		}
		$suites->appendChild( $suite );
		$phpunit->appendChild( $suites );

		$source  = $doc->createElement( 'source' );
		$include = $doc->createElement( 'include' );
		foreach ( $run['files'] as $file ) {
			$include->appendChild( $doc->createElement( 'file', $this->scope->root() . '/' . $file ) );
		}
		$source->appendChild( $include );
		$phpunit->appendChild( $source );

		$doc->save( $dir . '/phpunit.xml' );
	}

	/**
	 * @param string                                  $dir
	 * @param array{files: string[], tests: string[]} $run
	 *
	 * @return void
	 */
	private function write_infection_config( $dir, array $run ) {
		$directories = array();
		foreach ( $run['files'] as $file ) {
			$directories[ $this->scope->root() . '/' . dirname( $file ) ] = true;
		}

		$config = array(
			'source'   => array( 'directories' => array_keys( $directories ) ),
			'phpUnit'  => array(
				'configDir'  => $dir,
				'customPath' => $this->kernel_dir . '/vendor/bin/phpunit',
			),
			'tmpDir'   => $dir . '/tmp',
			'logs'     => array(
				'json' => $dir . '/infection.json',
				'text' => $dir . '/infection.log',
			),
			'mutators' => (object) array( '@default' => true ),
			'timeout'  => (int) ( $this->scope->get( 'timeout' ) ?: 120 ),
		);

		file_put_contents( $dir . '/infection.json5', json_encode( $config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * @param string $dir
	 *
	 * @return void
	 */
	private function reset_dir( $dir ) {
		if ( is_dir( $dir ) ) {
			exec( 'rm -rf ' . escapeshellarg( $dir ) );
		}
		if ( ! mkdir( $dir, 0777, true ) && ! is_dir( $dir ) ) {
			throw new RuntimeException( 'Cannot create ' . $dir );
		}
	}
}
