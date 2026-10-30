<?php

namespace Uncanny_Owl\Wp_Test_Kernel\Mutate;

use DOMDocument;
use DOMElement;
use RuntimeException;

/**
 * Runs Infection for one plan run: the run's files mutated, judged only by the run's tests.
 *
 * Writes a PHPUnit config holding just those tests and an Infection config beside it, runs the run's
 * tests once with coverage (the initial run), then has Infection mutate the run's files against that
 * coverage and reads its JSON log. Infection drives PHPUnit 13 directly, runs only the tests covering
 * each mutated line, and gives each thread its own TEST_TOKEN, so each thread gets its own kernel
 * database.
 *
 * A run is judged as the plugin's own test run judges a test:
 *
 * - The initial run passes when PHPUnit exits 0, as `composer unit-tests` does. Its stderr (a plugin's
 *   warning while WordPress boots, a snapshot note) goes to a file. Infection runs no initial run of
 *   its own: it stops one at the first byte on stderr, so a warning that a normal run only prints
 *   ended the whole run.
 * - The coverage source holds the run's files and every file its tests name as a coverage target, as
 *   the plugin's <source> does; Infection mutates only the run's files.
 */
final class Infection_Runner {

	/** <source> attributes that read which files are the plugin's own: with one on, the source stays whole. */
	const FIRST_PARTY_ATTRIBUTES = array( 'restrictNotices', 'restrictWarnings', 'ignoreSelfDeprecations', 'ignoreDirectDeprecations', 'ignoreIndirectDeprecations' );

	/** @var Scope */
	private $scope;

	/** @var string Absolute path of the project that holds vendor/ and the PHPUnit config (the plugin root). */
	private $project_dir;

	/** @var int */
	private $threads;

	/** @var string */
	private $coverage_options;

	/** @var Coverage_Targets|false|null False when the plugin's config declares no <source>; null until read. */
	private $coverage_targets = null;

	/**
	 * @param Scope  $scope
	 * @param string $project_dir
	 * @param int    $threads
	 * @param string $coverage_options PHP options that turn a coverage driver on for the initial run.
	 */
	public function __construct( Scope $scope, $project_dir, $threads, $coverage_options ) {
		$this->scope            = $scope;
		$this->project_dir      = rtrim( $project_dir, '/' );
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

		$dir = $this->project_dir . '/tests/_output/mutate/run-' . $index;
		$this->reset_dir( $dir );

		$root    = $this->scope->root();
		$started = time();
		$this->write_phpunit_config( $dir, $run );
		$this->write_infection_config( $dir, $run );

		$stopped = $this->run_initial_tests( $dir );
		if ( null !== $stopped ) {
			return array(
				'tallies' => array(),
				'stopped' => $stopped,
				'seconds' => time() - $started,
			);
		}

		// The JSON log is what is read. Printing escaped mutants to the console can crash Infection
		// ("Incorrectly nested style tag found" when a mutated line reads like a console tag), so a
		// normal run prints none; an inspection run (--show-mutations) still asks for them.
		$show = preg_grep( '/^--show-mutations/', $extra_args ) ? '' : '--show-mutations=0 ';

		// --with-uncovered: Infection 0.35 skips code no test runs unless told otherwise, which would
		// report an untested file as "nothing to mutate" and make the overall score equal the covered one.
		$filter  = implode( ',', $run['files'] );
		$command = sprintf(
			'cd %s && %s vendor/bin/infection --configuration=%s --threads=%d --filter=%s --only-covering-test-cases --with-uncovered --skip-initial-tests --coverage=%s --no-progress --no-interaction ' . $show . '%s 2>&1',
			escapeshellarg( $this->project_dir ),
			escapeshellarg( PHP_BINARY ),
			escapeshellarg( $dir . '/infection.json5' ),
			$this->threads,
			escapeshellarg( $filter ),
			escapeshellarg( $dir . '/coverage' ),
			implode( ' ', array_map( 'escapeshellarg', $extra_args ) )
		);

		$output = array();
		$exit   = 0;
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
	 * The run's tests, once, with coverage: what Infection's own initial run did, judged as a normal
	 * run is judged. PHPUnit's exit code is the verdict; stdout and stderr are kept for the reader.
	 *
	 * A coverage driver loaded through an ini scan directory reaches PHPUnit's process-isolated
	 * children too; `-d` options (coverage_options) reach only this process. Neither reaches the mutant
	 * runs, which collect no coverage.
	 *
	 * @param string $dir The run's folder, holding phpunit.xml.
	 *
	 * @return string|null Why the run stops, or null when the tests pass and the coverage is written.
	 */
	private function run_initial_tests( $dir ) {
		$ini_dir = (string) ( getenv( 'WP_TEST_KERNEL_COVERAGE_INI_DIR' ) ?: $this->scope->get( 'coverage_ini_dir' ) );
		$env     = ( '' === $ini_dir ? '' : 'PHP_INI_SCAN_DIR=' . escapeshellarg( ':' . $ini_dir ) . ' ' ) . 'XDEBUG_MODE=coverage ';

		// PCOV reads only its own folder (by default src/, lib/ or app/); the plugin root holds every
		// source. coverage_options come after it, so they can still name another.
		$php_options = array_merge( array( '-d', 'pcov.directory=' . $this->scope->root() ), preg_split( '/\s+/', trim( $this->coverage_options ), -1, PREG_SPLIT_NO_EMPTY ) );

		$command = sprintf(
			'cd %s && %s%s %s vendor/bin/phpunit --configuration %s --coverage-xml %s --log-junit %s --exclude-source-from-xml-coverage > %s 2> %s',
			escapeshellarg( $this->project_dir ),
			$env,
			escapeshellarg( PHP_BINARY ),
			implode( ' ', array_map( 'escapeshellarg', $php_options ) ),
			escapeshellarg( $dir . '/phpunit.xml' ),
			escapeshellarg( $dir . '/coverage/coverage-xml' ),
			escapeshellarg( $dir . '/coverage/junit.xml' ),
			escapeshellarg( $dir . '/initial-tests.out' ),
			escapeshellarg( $dir . '/initial-tests.err' )
		);

		$output = array();
		$exit   = 0;
		exec( $command, $output, $exit );

		if ( 0 !== $exit ) {
			return sprintf( "the run's tests do not pass before any mutant (PHPUnit exited %d; see %s/initial-tests.out and initial-tests.err)", $exit, $dir );
		}
		if ( ! is_readable( $dir . '/coverage/coverage-xml/index.xml' ) || ! is_readable( $dir . '/coverage/junit.xml' ) ) {
			return sprintf( 'the initial run wrote no coverage: is a coverage driver on? (see %s/initial-tests.out and initial-tests.err)', $dir );
		}

		return null;
	}

	/**
	 * @param string                                  $dir
	 * @param array{files: string[], tests: string[]} $run
	 *
	 * @return void
	 */
	private function write_phpunit_config( $dir, array $run ) {
		$base = $this->base_config_path();
		$doc  = new DOMDocument();
		$doc->preserveWhiteSpace = false;
		$doc->formatOutput       = true;
		if ( ! $doc->load( $base ) ) {
			throw new RuntimeException( 'Cannot read ' . $base );
		}

		$phpunit = $doc->documentElement;
		if ( '' !== $phpunit->getAttribute( 'bootstrap' ) ) {
			$phpunit->setAttribute( 'bootstrap', Coverage_Targets::absolute( $phpunit->getAttribute( 'bootstrap' ), dirname( $base ) ) );
		}
		$phpunit->setAttribute( 'cacheDirectory', $dir . '/phpunit-cache' );
		$phpunit->removeAttribute( 'xsi:noNamespaceSchemaLocation' );
		// The run's suite replaces the base config's suites, so a default suite named there would select nothing.
		$phpunit->removeAttribute( 'defaultTestSuite' );

		$base_source = $doc->getElementsByTagName( 'source' )->item( 0 );
		$base_source = $base_source instanceof DOMElement ? $base_source->cloneNode( true ) : null;

		// The initial run writes the coverage Infection reads; the plugin's own reports and logs would
		// overwrite files outside the run.
		foreach ( array( 'testsuites', 'source', 'coverage', 'logging' ) as $tag ) {
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

		$phpunit->appendChild( $this->source_element( $doc, $base_source, dirname( $base ), $run ) );

		$doc->save( $dir . '/phpunit.xml' );
	}

	/**
	 * The run's coverage source: the plugin's <source> with its settings, holding the run's files and
	 * every file the run's tests name as a coverage target.
	 *
	 * When the plugin's <source> sets an attribute that reads which files are its own (restrictWarnings,
	 * ignoreIndirectDeprecations, ...), narrowing it would change which warnings and deprecations count,
	 * so it is kept whole (paths made absolute), at the cost of a larger coverage report.
	 *
	 * @param DOMDocument                             $doc
	 * @param DOMElement|null                         $base_source A copy of the plugin's <source>.
	 * @param string                                  $base_dir
	 * @param array{files: string[], tests: string[]} $run
	 *
	 * @return DOMElement
	 */
	private function source_element( DOMDocument $doc, $base_source, $base_dir, array $run ) {
		$source = $doc->createElement( 'source' );
		$whole  = false;

		if ( null !== $base_source ) {
			foreach ( iterator_to_array( $base_source->attributes ) as $attribute ) {
				$value = 'baseline' === $attribute->name ? Coverage_Targets::absolute( $attribute->value, $base_dir ) : $attribute->value;
				$source->setAttribute( $attribute->name, $value );
				if ( in_array( $attribute->name, self::FIRST_PARTY_ATTRIBUTES, true ) && 'true' === strtolower( trim( $attribute->value ) ) ) {
					$whole = true;
				}
			}
			foreach ( iterator_to_array( $base_source->childNodes ) as $child ) {
				if ( ! $child instanceof DOMElement ) {
					continue;
				}
				$is_list = in_array( $child->tagName, array( 'include', 'exclude' ), true );
				if ( $is_list && ! $whole ) {
					continue;
				}
				$copy = $doc->importNode( $child, true );
				if ( $is_list ) {
					foreach ( $copy->childNodes as $entry ) {
						if ( $entry instanceof DOMElement ) {
							$entry->nodeValue = Coverage_Targets::absolute( trim( $entry->textContent ), $base_dir );
						}
					}
				}
				$source->appendChild( $copy );
			}
		}

		if ( $whole ) {
			return $source;
		}

		$files = array();
		foreach ( $run['files'] as $file ) {
			$path           = $this->scope->root() . '/' . $file;
			$real           = realpath( $path );
			$files[ $path ] = false === $real ? $path : $real;
		}
		$targets = $this->coverage_targets();
		if ( $targets instanceof Coverage_Targets ) {
			$tests = array_map(
				function ( $test ) {
					return $this->scope->root() . '/' . $test;
				},
				$run['tests']
			);
			foreach ( $targets->files_for( $tests ) as $file ) {
				$files[ $file ] = $file;
			}
		}
		$files = array_unique( array_values( $files ) );
		sort( $files );

		$include = $doc->createElement( 'include' );
		foreach ( $files as $file ) {
			$include->appendChild( $doc->createElement( 'file', $file ) );
		}
		$source->insertBefore( $include, $source->firstChild );

		return $source;
	}

	/**
	 * The plugin's <source>, read once for every run of this invocation.
	 *
	 * @return Coverage_Targets|false
	 */
	private function coverage_targets() {
		if ( null === $this->coverage_targets ) {
			$base = $this->base_config_path();
			$doc  = new DOMDocument();
			$doc->load( $base );
			$source = $doc->getElementsByTagName( 'source' )->item( 0 );

			$this->coverage_targets = $source instanceof DOMElement ? Coverage_Targets::from_source( $source, dirname( $base ) ) : false;
		}

		return $this->coverage_targets;
	}

	/**
	 * @return string
	 */
	private function base_config_path() {
		return $this->project_dir . '/' . ( $this->scope->get( 'phpunit' ) ?: 'phpunit.xml.dist' );
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
				'customPath' => $this->project_dir . '/vendor/bin/phpunit',
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
