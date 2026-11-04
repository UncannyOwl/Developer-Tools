<?php

namespace Uncanny_Owl\Wp_Test_Kernel\Tools;

use DOMDocument;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use RuntimeException;
use Uncanny_Owl\Wp_Test_Kernel\Mutate\Coverage_Targets;

/**
 * The test files a change reaches, so a pull request can run those instead of the whole suite.
 *
 * A test file is selected when it changed itself, when its coverage attributes (Covers* and Uses*)
 * name a changed source file, or when its code names a class or function a changed file declares.
 * A named class brings its parents and traits, as a coverage target does: a test of a subclass is
 * selected when its parent changes. The code names catch the tests that build a changed class while
 * they declare coverage of another one (a registry test that builds every block).
 *
 * A change can still break a test that reaches the changed code only at run time (through a hook, a
 * registry, a container): that test is not selected. The whole suite is the check for those.
 */
final class Affected_Tests {

	/** @var Source_Index */
	private $index;

	/** @var Coverage_Targets */
	private $targets;

	/** @var array<string, string> real path => path as listed, for every file in the plugin's <source> */
	private $source = array();

	/** @var \PhpParser\Parser */
	private $parser;

	/** @var string[] The changed source files no selected test names, as listed. Set by select(). */
	public $unreached = array();

	/**
	 * @param Source_Index $index        Built from $source_files.
	 * @param string[]     $source_files Absolute paths of the files in the plugin's <source>.
	 */
	public function __construct( Source_Index $index, array $source_files ) {
		$this->index   = $index;
		$this->targets = new Coverage_Targets( $index, $source_files );
		$this->parser  = ( new ParserFactory() )->createForNewestSupportedVersion();
		foreach ( $source_files as $file ) {
			$this->source[ self::real( $file ) ] = $file;
		}
	}

	/**
	 * @param string $config A PHPUnit config: its <source> is the code a change can touch.
	 *
	 * @return self
	 */
	public static function from_config( $config ) {
		$document = new DOMDocument();
		if ( ! is_readable( $config ) || ! @$document->load( $config ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an unreadable config is reported below.
			throw new RuntimeException( "Cannot read the PHPUnit config $config." );
		}
		$source = $document->getElementsByTagName( 'source' )->item( 0 );
		if ( null === $source ) {
			throw new RuntimeException( "$config has no <source>: there is no code to match a change against." );
		}
		$files = Coverage_Targets::source_files( $source, dirname( (string) realpath( $config ) ) );

		return new self( Source_Index::build( $files ), $files );
	}

	/**
	 * @param string[] $test_files    The test files to choose from.
	 * @param string[] $changed_files The files the change touches (added, modified or renamed to).
	 *
	 * @return string[] The selected test files, as given, sorted.
	 */
	public function select( array $test_files, array $changed_files ) {
		$changed = array();
		foreach ( $changed_files as $file ) {
			if ( is_file( $file ) ) {
				$changed[ self::real( $file ) ] = true;
			}
		}

		$selected = array();
		$reached  = array();
		foreach ( $test_files as $test_file ) {
			$real = self::real( $test_file );
			if ( isset( $changed[ $real ] ) ) {
				$selected[] = $test_file;
				continue;
			}
			$hits = array_intersect_key( $changed, $this->files_named_by( $test_file ) );
			if ( ! empty( $hits ) ) {
				$selected[] = $test_file;
				$reached   += $hits;
			}
		}

		$this->unreached = array();
		foreach ( array_keys( array_diff_key( $changed, $reached ) ) as $file ) {
			if ( isset( $this->source[ $file ] ) ) {
				$this->unreached[] = $this->source[ $file ];
			}
		}
		sort( $this->unreached );
		sort( $selected );

		return $selected;
	}

	/**
	 * The source files one test file names: through its coverage attributes, and through the classes
	 * and functions its code names.
	 *
	 * @param string $test_file
	 *
	 * @return array<string, true> Real paths.
	 */
	private function files_named_by( $test_file ) {
		$files = array();
		foreach ( $this->targets->files_for( array( $test_file ) ) as $file ) {
			$files[ self::real( $file ) ] = true;
		}

		try {
			$ast = $this->parser->parse( (string) file_get_contents( $test_file ) );
		} catch ( \Throwable $e ) {
			return $files; // PHPUnit reports a test file it cannot load.
		}
		$walk = new NodeTraverser();
		$walk->addVisitor( new NameResolver() );
		$ast = $walk->traverse( (array) $ast );

		$finder = new NodeFinder();
		foreach ( $finder->findInstanceOf( $ast, Name::class ) as $name ) {
			foreach ( $this->files_of_class( $name->toString() ) as $file ) {
				$files[ self::real( $file ) ] = true;
			}
		}
		foreach ( $finder->findInstanceOf( $ast, FuncCall::class ) as $call ) {
			if ( ! $call->name instanceof Name ) {
				continue;
			}
			// An unqualified call in a namespace falls back to the global function at run time.
			$namespaced = $call->name->getAttribute( 'namespacedName' );
			foreach ( array( $call->name->toString(), $namespaced instanceof Name ? $namespaced->toString() : '' ) as $function ) {
				foreach ( '' === $function ? array() : $this->index->files_of_function( $function ) as $file ) {
					$files[ self::real( $file ) ] = true;
				}
			}
		}

		return $files;
	}

	/**
	 * @param string $class A name as the code wrote it, resolved; it may be a class_alias().
	 *
	 * @return string[]
	 */
	private function files_of_class( $class ) {
		$real = $this->index->alias_target( $class );

		return $this->index->files_of_class( null === $real ? $class : $real );
	}

	/**
	 * @param string $file
	 *
	 * @return string
	 */
	private static function real( $file ) {
		$real = realpath( $file );

		return false === $real ? $file : $real;
	}
}
