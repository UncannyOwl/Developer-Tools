<?php

namespace Uncanny_Owl\Wp_Test_Kernel\Tools;

use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/**
 * The classes (with their methods) and functions a plugin declares, so a coverage target written in a
 * test can be checked against what exists instead of being trusted.
 */
final class Source_Index {

	/** @var array<string, array{name: string, kind: string, methods: array<string, string>, parent: string, traits: string[], interfaces: string[], file: string}> lowercase FQCN => class, trait, interface or enum */
	private $classes = array();

	/** @var array<string, array{name: string, kind: string, methods: array<string, string>, parent: string, traits: string[], interfaces: string[], file: string}> lowercase FQCN => a class from the context directories: read for inheritance only, never a target */
	private $context = array();

	/** @var array<string, string> lowercase alias => the real FQCN (from class_alias() calls) */
	private $aliases = array();

	/** @var array<string, string> lowercase FQ function => name */
	private $functions = array();

	/** @var array<string, string> lowercase FQ function => the file declaring it */
	private $function_files = array();

	/** @var array<string, string[]> lowercase short class name => FQCNs */
	private $short = array();

	/**
	 * @param string[] $dirs    Directories (or files) whose classes and functions can be coverage targets.
	 * @param string[] $context Directories whose classes are read only to follow inheritance (an add-on's
	 *                          parent plugin): their methods count, the classes themselves are never targets.
	 *
	 * @return self
	 */
	public static function build( array $dirs, array $context = array() ) {
		$index = new self();
		foreach ( $context as $dir ) {
			$scan = self::scan( array( $dir ) );
			$index->context = array_merge( $index->context, $scan->classes );
		}
		$scan              = self::scan( $dirs );
		$index->classes    = $scan->classes;
		$index->aliases    = $scan->aliases;
		$index->functions  = $scan->functions;
		$index->short      = $scan->short;

		$index->function_files = $scan->function_files;

		return $index;
	}

	/**
	 * @param string[] $dirs
	 *
	 * @return self
	 */
	private static function scan( array $dirs ) {
		$index  = new self();
		$parser = ( new ParserFactory() )->createForNewestSupportedVersion();
		$finder = new NodeFinder();

		foreach ( $dirs as $dir ) {
			$files = is_file( $dir ) ? array( $dir ) : self::php_files( $dir );
			foreach ( $files as $file ) {
				try {
					$ast = $parser->parse( (string) file_get_contents( $file ) );
				} catch ( \Throwable $e ) {
					continue; // A file PHP itself cannot parse declares nothing usable.
				}
				if ( null === $ast ) {
					continue;
				}
				$traverser = new NodeTraverser();
				$traverser->addVisitor( new NameResolver() );
				$ast = $traverser->traverse( $ast );

				foreach ( $finder->findInstanceOf( $ast, ClassLike::class ) as $class ) {
					if ( null === $class->namespacedName ) {
						continue;
					}
					$name    = $class->namespacedName->toString();
					$methods = array();
					foreach ( $class->getMethods() as $method ) {
						$methods[ strtolower( $method->name->toString() ) ] = $method->name->toString();
					}
					$parent = '';
					if ( $class instanceof \PhpParser\Node\Stmt\Class_ && null !== $class->extends ) {
						$parent = $class->extends->toString();
					}
					$traits = array();
					foreach ( $class->getTraitUses() as $use ) {
						foreach ( $use->traits as $trait ) {
							$traits[] = $trait->toString();
						}
					}
					$index->classes[ strtolower( $name ) ] = array(
						'name'       => $name,
						'kind'       => self::kind( $class ),
						'methods'    => $methods,
						'parent'     => $parent,
						'traits'     => $traits,
						'interfaces' => self::interfaces( $class ),
						'file'       => $file,
					);
					$index->short[ strtolower( $class->name->toString() ) ][] = $name;
				}
				foreach ( $finder->findInstanceOf( $ast, FuncCall::class ) as $call ) {
					if ( ! $call->name instanceof Name || 'class_alias' !== strtolower( $call->name->toString() ) || count( $call->args ) < 2 ) {
						continue;
					}
					$original = self::class_argument( $call->args[0]->value );
					$alias    = self::class_argument( $call->args[1]->value );
					if ( '' !== $original && '' !== $alias ) {
						$index->aliases[ strtolower( $alias ) ] = $original;
					}
				}
				foreach ( $finder->findInstanceOf( $ast, Function_::class ) as $function ) {
					$name = null !== $function->namespacedName ? $function->namespacedName->toString() : $function->name->toString();
					$index->functions[ strtolower( $name ) ]      = $name;
					$index->function_files[ strtolower( $name ) ] = $file;
				}
			}
		}

		return $index;
	}

	/**
	 * @param string $class FQCN, with or without the leading backslash.
	 *
	 * @return string|null The declared name, with a leading backslash.
	 */
	public function find_class( $class ) {
		$key = strtolower( ltrim( $class, '\\' ) );

		return isset( $this->classes[ $key ] ) ? '\\' . $this->classes[ $key ]['name'] : null;
	}

	/**
	 * Whether a declared name is a class, trait, interface or enum.
	 *
	 * @param string $class
	 *
	 * @return string|null
	 */
	public function kind_of( $class ) {
		$key = strtolower( ltrim( $class, '\\' ) );

		return $this->classes[ $key ]['kind'] ?? null;
	}

	/**
	 * @param ClassLike $class
	 *
	 * @return string
	 */
	private static function kind( ClassLike $class ) {
		if ( $class instanceof \PhpParser\Node\Stmt\Trait_ ) {
			return 'trait';
		}
		if ( $class instanceof \PhpParser\Node\Stmt\Interface_ ) {
			return 'interface';
		}
		if ( $class instanceof \PhpParser\Node\Stmt\Enum_ ) {
			return 'enum';
		}

		return 'class';
	}

	/**
	 * The interfaces a class or enum implements, or an interface extends, as written.
	 *
	 * @param ClassLike $class
	 *
	 * @return string[]
	 */
	private static function interfaces( ClassLike $class ) {
		$names = array();
		if ( $class instanceof \PhpParser\Node\Stmt\Class_ || $class instanceof \PhpParser\Node\Stmt\Enum_ ) {
			$names = $class->implements;
		} elseif ( $class instanceof \PhpParser\Node\Stmt\Interface_ ) {
			$names = $class->extends;
		}

		return array_map(
			static function ( $name ) {
				return $name->toString();
			},
			$names
		);
	}

	/**
	 * The real class a `class_alias()` name points to.
	 *
	 * @param string $alias
	 *
	 * @return string|null With a leading backslash.
	 */
	public function alias_target( $alias ) {
		$key = strtolower( ltrim( $alias, '\\' ) );

		return isset( $this->aliases[ $key ] ) ? '\\' . ltrim( $this->aliases[ $key ], '\\' ) : null;
	}

	/**
	 * `Foo::class` or a class-name string.
	 *
	 * @param \PhpParser\Node\Expr $expr
	 *
	 * @return string
	 */
	private static function class_argument( $expr ) {
		if ( $expr instanceof ClassConstFetch && $expr->class instanceof Name && $expr->name instanceof \PhpParser\Node\Identifier && 'class' === strtolower( $expr->name->toString() ) ) {
			return $expr->class->toString();
		}
		if ( $expr instanceof String_ ) {
			return ltrim( $expr->value, '\\' );
		}

		return '';
	}

	/**
	 * Whether any class, trait, interface or enum is declared in this namespace or one below it.
	 *
	 * @param string $namespace
	 *
	 * @return bool
	 */
	public function has_namespace( $namespace ) {
		$prefix = strtolower( trim( $namespace, '\\' ) ) . '\\';
		foreach ( array_keys( $this->classes ) as $key ) {
			if ( 0 === strpos( $key, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a name is declared only in the context directories (so it cannot be a target here).
	 *
	 * @param string $class
	 *
	 * @return bool
	 */
	public function in_context_only( $class ) {
		$key = strtolower( ltrim( $class, '\\' ) );

		return ! isset( $this->classes[ $key ] ) && isset( $this->context[ $key ] );
	}

	/**
	 * A class's own method, one from a trait it uses, or one it inherits, as far as the index reaches.
	 *
	 * @param string $class
	 * @param string $method
	 *
	 * @return bool
	 */
	public function has_method( $class, $method ) {
		$queue = array( strtolower( ltrim( $class, '\\' ) ) );
		$seen  = array();
		while ( ! empty( $queue ) ) {
			$key   = array_shift( $queue );
			$found = $this->classes[ $key ] ?? $this->context[ $key ] ?? null;
			if ( isset( $seen[ $key ] ) || null === $found ) {
				continue;
			}
			$seen[ $key ] = true;
			if ( isset( $found['methods'][ strtolower( $method ) ] ) ) {
				return true;
			}
			foreach ( $found['traits'] as $trait ) {
				$queue[] = strtolower( $trait );
			}
			if ( '' !== $found['parent'] ) {
				$queue[] = strtolower( $found['parent'] );
			}
		}

		return false;
	}

	/**
	 * @param string $function
	 *
	 * @return string|null The declared name.
	 */
	public function find_function( $function ) {
		$key = strtolower( ltrim( $function, '\\' ) );

		return $this->functions[ $key ] ?? null;
	}

	/**
	 * The files PHPUnit reads for a class or trait named as a coverage target: its own, its parents'
	 * and those of the traits they use, because PHPUnit credits those lines to the class too.
	 *
	 * @param string $class
	 *
	 * @return string[] Empty when the source does not declare it.
	 */
	public function files_of_class( $class ) {
		$files = array();
		$queue = array( strtolower( ltrim( $class, '\\' ) ) );
		$seen  = array();
		while ( ! empty( $queue ) ) {
			$key = array_shift( $queue );
			if ( isset( $seen[ $key ] ) || ! isset( $this->classes[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$found        = $this->classes[ $key ];
			$files[]      = $found['file'];
			foreach ( $found['traits'] as $trait ) {
				$queue[] = strtolower( $trait );
			}
			if ( '' !== $found['parent'] ) {
				$queue[] = strtolower( $found['parent'] );
			}
		}

		return array_values( array_unique( $files ) );
	}

	/**
	 * @param string $function
	 *
	 * @return string[] The file declaring it, or none.
	 */
	public function files_of_function( $function ) {
		$key = strtolower( ltrim( $function, '\\' ) );

		return isset( $this->function_files[ $key ] ) ? array( $this->function_files[ $key ] ) : array();
	}

	/**
	 * The files declaring anything in this namespace or one below it.
	 *
	 * @param string $namespace
	 *
	 * @return string[]
	 */
	public function files_in_namespace( $namespace ) {
		$prefix = strtolower( trim( $namespace, '\\' ) ) . '\\';
		$files  = array();
		foreach ( $this->classes as $key => $class ) {
			if ( 0 === strpos( $key, $prefix ) ) {
				$files[] = $class['file'];
			}
		}
		foreach ( $this->function_files as $key => $file ) {
			if ( 0 === strpos( $key, $prefix ) ) {
				$files[] = $file;
			}
		}

		return array_values( array_unique( $files ) );
	}

	/**
	 * A class and every class below it (CoversClassesThatExtendClass), with what each one reads.
	 *
	 * @param string $class
	 *
	 * @return string[]
	 */
	public function files_extending( $class ) {
		$target = strtolower( ltrim( $class, '\\' ) );
		$files  = $this->files_of_class( $target );
		foreach ( array_keys( $this->classes ) as $key ) {
			if ( $key !== $target && in_array( $target, $this->ancestors( $key ), true ) ) {
				$files = array_merge( $files, $this->files_of_class( $key ) );
			}
		}

		return array_values( array_unique( $files ) );
	}

	/**
	 * An interface and every class that names it in its own `implements`, which is what PHPUnit maps
	 * CoversClassesThatImplementInterface to, with what each class reads.
	 *
	 * @param string $interface
	 *
	 * @return string[]
	 */
	public function files_implementing( $interface ) {
		$target = strtolower( ltrim( $interface, '\\' ) );
		$files  = isset( $this->classes[ $target ] ) ? array( $this->classes[ $target ]['file'] ) : array();
		foreach ( $this->classes as $key => $class ) {
			if ( 'interface' === $class['kind'] ) {
				continue;
			}
			foreach ( $class['interfaces'] as $name ) {
				if ( strtolower( $name ) === $target ) {
					$files = array_merge( $files, $this->files_of_class( $key ) );
				}
			}
		}

		return array_values( array_unique( $files ) );
	}

	/**
	 * Lowercase names of a class's parents, nearest first, as far as the source declares them.
	 *
	 * @param string $key Lowercase FQCN.
	 *
	 * @return string[]
	 */
	private function ancestors( $key ) {
		$ancestors = array();
		while ( isset( $this->classes[ $key ] ) && '' !== $this->classes[ $key ]['parent'] ) {
			$key = strtolower( $this->classes[ $key ]['parent'] );
			if ( in_array( $key, $ancestors, true ) ) {
				break;
			}
			$ancestors[] = $key;
		}

		return $ancestors;
	}

	/**
	 * Every declared class with this short name.
	 *
	 * @param string $class A name whose last segment is the short name.
	 *
	 * @return string[] Names with a leading backslash.
	 */
	public function classes_named( $class ) {
		$parts = explode( '\\', $class );
		$short = strtolower( (string) end( $parts ) );

		return array_map(
			static function ( $name ) {
				return '\\' . $name;
			},
			$this->short[ $short ] ?? array()
		);
	}

	/**
	 * @param string $dir
	 *
	 * @return string[]
	 */
	private static function php_files( $dir ) {
		$files = array();
		if ( ! is_dir( $dir ) ) {
			return $files;
		}
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( 'php' === $file->getExtension() && false === strpos( $file->getPathname(), '/node_modules/' ) ) {
				$files[] = $file->getPathname();
			}
		}
		sort( $files );

		return $files;
	}
}
