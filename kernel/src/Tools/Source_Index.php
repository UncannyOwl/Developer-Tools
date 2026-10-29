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

	/** @var array<string, array{name: string, kind: string, methods: array<string, string>, parent: string, traits: string[]}> lowercase FQCN => class, trait, interface or enum */
	private $classes = array();

	/** @var array<string, string> lowercase alias => the real FQCN (from class_alias() calls) */
	private $aliases = array();

	/** @var array<string, string> lowercase FQ function => name */
	private $functions = array();

	/** @var array<string, string[]> lowercase short class name => FQCNs */
	private $short = array();

	/**
	 * @param string[] $dirs Directories (or files) to index.
	 *
	 * @return self
	 */
	public static function build( array $dirs ) {
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
						'name'    => $name,
						'kind'    => self::kind( $class ),
						'methods' => $methods,
						'parent'  => $parent,
						'traits'  => $traits,
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
					$index->functions[ strtolower( $name ) ] = $name;
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
			$key = array_shift( $queue );
			if ( isset( $seen[ $key ] ) || ! isset( $this->classes[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			if ( isset( $this->classes[ $key ]['methods'][ strtolower( $method ) ] ) ) {
				return true;
			}
			foreach ( $this->classes[ $key ]['traits'] as $trait ) {
				$queue[] = strtolower( $trait );
			}
			if ( '' !== $this->classes[ $key ]['parent'] ) {
				$queue[] = strtolower( $this->classes[ $key ]['parent'] );
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
