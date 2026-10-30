<?php

namespace Uncanny_Owl\Wp_Test_Kernel\Mutate;

use DOMElement;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use Uncanny_Owl\Wp_Test_Kernel\Tools\Covers_Check;
use Uncanny_Owl\Wp_Test_Kernel\Tools\Source_Index;

/**
 * The source files a run's tests name in their coverage attributes.
 *
 * PHPUnit 13 checks every test's CoversX and UsesX targets against the coverage source while it
 * collects coverage. A target outside it is "not a valid target for code coverage": a PHPUnit warning
 * that fails the run under failOnWarning, and PHPUnit drops that test's coverage. A run mutates a few
 * files, but its tests may name other classes too (a neighbour in the mirror folder, an also_tested_by
 * folder, the class whose parent is being mutated). Those targets are valid in the plugin's own
 * <source>, so the run's coverage source holds them as well; only the run's files are mutated.
 */
final class Coverage_Targets {

	const ATTRIBUTES = 'PHPUnit\\Framework\\Attributes\\';

	/** @var Source_Index */
	private $index;

	/** @var array<string, string> real path => path as listed, for every file in the plugin's <source> */
	private $source = array();

	/**
	 * @param Source_Index $index        Built from $source_files.
	 * @param string[]     $source_files Absolute paths of the files in the plugin's <source>.
	 */
	public function __construct( Source_Index $index, array $source_files ) {
		$this->index = $index;
		foreach ( $source_files as $file ) {
			$real = realpath( $file );

			$this->source[ false === $real ? $file : $real ] = $file;
		}
	}

	/**
	 * @param DOMElement $source   The plugin's PHPUnit <source> element.
	 * @param string     $base_dir The folder of the plugin's PHPUnit config: relative paths start there.
	 *
	 * @return self
	 */
	public static function from_source( DOMElement $source, $base_dir ) {
		$files = self::source_files( $source, $base_dir );

		return new self( Source_Index::build( $files ), $files );
	}

	/**
	 * The files a PHPUnit <source> element holds: its <include> minus its <exclude>.
	 *
	 * @param DOMElement $source
	 * @param string     $base_dir
	 *
	 * @return string[] Absolute, sorted.
	 */
	public static function source_files( DOMElement $source, $base_dir ) {
		$included = array();
		$excluded = array();
		foreach ( $source->childNodes as $list ) {
			if ( ! $list instanceof DOMElement || ! in_array( $list->tagName, array( 'include', 'exclude' ), true ) ) {
				continue;
			}
			foreach ( $list->childNodes as $entry ) {
				if ( ! $entry instanceof DOMElement ) {
					continue;
				}
				$files = self::entry_files( $entry, $base_dir );
				if ( 'include' === $list->tagName ) {
					$included = array_merge( $included, $files );
				} else {
					$excluded = array_merge( $excluded, $files );
				}
			}
		}
		$files = array_values( array_diff( array_unique( $included ), $excluded ) );
		sort( $files );

		return $files;
	}

	/**
	 * Every file of the plugin's <source> that the tests' coverage attributes name, with the files
	 * PHPUnit reads for each target (a class's parents and traits).
	 *
	 * @param string[] $test_files Absolute.
	 *
	 * @return string[] Absolute, sorted.
	 */
	public function files_for( array $test_files ) {
		$parser = ( new ParserFactory() )->createForNewestSupportedVersion();
		$found  = array();

		foreach ( $test_files as $test_file ) {
			try {
				$ast = $parser->parse( (string) file_get_contents( $test_file ) );
			} catch ( \Throwable $e ) {
				continue; // PHPUnit reports a test file it cannot load; it names no target here.
			}
			$walk = new NodeTraverser();
			$walk->addVisitor( new NameResolver() );
			$ast = $walk->traverse( (array) $ast );

			foreach ( ( new NodeFinder() )->findInstanceOf( $ast, Class_::class ) as $class ) {
				foreach ( $class->attrGroups as $group ) {
					foreach ( $group->attrs as $attr ) {
						$name = $attr->name->toString();
						if ( 0 !== strpos( $name, self::ATTRIBUTES ) ) {
							continue;
						}
						$arg   = isset( $attr->args[0] ) ? $attr->args[0]->value : null;
						$found = array_merge( $found, $this->files_of( substr( $name, strlen( self::ATTRIBUTES ) ), $arg, dirname( $test_file ) ) );
					}
				}
			}
		}

		$files = array();
		foreach ( $found as $file ) {
			$real = realpath( $file );
			$key  = false === $real ? $file : $real;
			if ( isset( $this->source[ $key ] ) ) {
				$files[ $this->source[ $key ] ] = true;
			}
		}
		$files = array_keys( $files );
		sort( $files );

		return $files;
	}

	/**
	 * @param string                          $kind Attribute name after PHPUnit\Framework\Attributes\.
	 * @param \PhpParser\Node\Expr|null       $arg  Its first argument.
	 * @param string                          $dir  The test file's folder (for __DIR__).
	 *
	 * @return string[]
	 */
	private function files_of( $kind, $arg, $dir ) {
		if ( ! preg_match( '/^(Covers|Uses)(.+)$/', $kind, $match ) || 'Nothing' === $match[2] ) {
			return array();
		}

		switch ( $match[2] ) {
			case 'Class':
			case 'Trait':
			case 'Method':
				return $this->index->files_of_class( Covers_Check::class_name( $arg ) );
			case 'ClassesThatExtendClass':
				return $this->index->files_extending( Covers_Check::class_name( $arg ) );
			case 'ClassesThatImplementInterface':
				return $this->index->files_implementing( Covers_Check::class_name( $arg ) );
			case 'Function':
				return $arg instanceof String_ ? $this->index->files_of_function( $arg->value ) : array();
			case 'Namespace':
				return $arg instanceof String_ ? $this->index->files_in_namespace( $arg->value ) : array();
			case 'File':
				$path = Covers_Check::path( $arg, $dir );
				return null === $path ? array() : array( $path );
			case 'Directory':
			case 'DirectoryRecursively':
				return $this->files_under( Covers_Check::path( $arg, $dir ), 'Directory' === $match[2] );
			default:
				return array();
		}
	}

	/**
	 * Source files in a folder (only directly in it, or at any depth).
	 *
	 * @param string|null $dir
	 * @param bool        $direct_only
	 *
	 * @return string[]
	 */
	private function files_under( $dir, $direct_only ) {
		$real = null === $dir ? false : realpath( $dir );
		if ( false === $real ) {
			return array();
		}
		$files = array();
		foreach ( array_keys( $this->source ) as $file ) {
			if ( $direct_only ? dirname( $file ) === $real : 0 === strpos( $file, $real . '/' ) ) {
				$files[] = $file;
			}
		}

		return $files;
	}

	/**
	 * The files one <directory> or <file> entry of <source> names, as PHPUnit's file collector reads it.
	 *
	 * @param DOMElement $entry
	 * @param string     $base_dir
	 *
	 * @return string[]
	 */
	private static function entry_files( DOMElement $entry, $base_dir ) {
		$path = self::absolute( trim( $entry->textContent ), $base_dir );

		if ( 'file' === $entry->tagName ) {
			return is_file( $path ) ? array( (string) realpath( $path ) ) : array();
		}
		if ( 'directory' !== $entry->tagName || ! is_dir( $path ) ) {
			return array();
		}

		$prefix = $entry->getAttribute( 'prefix' );
		$suffix = $entry->hasAttribute( 'suffix' ) ? $entry->getAttribute( 'suffix' ) : '.php';
		$files  = array();
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			$name = $file->getFilename();
			if ( $file->isFile() && 0 === strpos( $name, $prefix ) && ( '' === $suffix || substr( $name, -strlen( $suffix ) ) === $suffix ) ) {
				$real    = realpath( $file->getPathname() );
				$files[] = false === $real ? $file->getPathname() : $real;
			}
		}

		return $files;
	}

	/**
	 * @param string $path
	 * @param string $base_dir
	 *
	 * @return string
	 */
	public static function absolute( $path, $base_dir ) {
		return '/' === substr( $path, 0, 1 ) ? rtrim( $path, '/' ) : rtrim( rtrim( $base_dir, '/' ) . '/' . $path, '/' );
	}
}
