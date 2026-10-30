<?php

namespace Uncanny_Owl\Wp_Test_Kernel\Tools;

use PhpParser\Node;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\MagicConst\Dir;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/**
 * Checks the coverage attributes of test classes against what the plugin declares, without running
 * the tests. PHPUnit itself reports an invalid target only while it collects coverage, so a test lane
 * that runs without a coverage driver would never see one.
 *
 * Problems: a target that does not exist or has the wrong kind (CoversClass on a trait or an
 * interface, CoversTrait on a class), a function, namespace or file the source does not hold,
 * CoversMethod (it credits only that method's lines, not the private helpers it calls), CoversNothing
 * beside other targets, and a test class with no coverage attribute at all.
 */
final class Covers_Check {

	const NS = 'PHPUnit\\Framework\\Attributes\\';

	/** @var Source_Index */
	private $index;

	/** @var string[] Real paths of the source directories and files. */
	private $roots;

	/** @var array<int, array{file: string, line: int, message: string}> */
	public $problems = array();

	/** @var int */
	public $classes = 0;

	/**
	 * @param Source_Index $index
	 * @param string[]     $roots The source directories (and files) the index was built from.
	 */
	public function __construct( Source_Index $index, array $roots ) {
		$this->index = $index;
		$this->roots = array_values( array_filter( array_map( 'realpath', $roots ) ) );
	}

	/**
	 * @param string $file
	 *
	 * @return void
	 */
	public function check_file( $file ) {
		$parser = ( new ParserFactory() )->createForNewestSupportedVersion();
		try {
			$ast = $parser->parse( (string) file_get_contents( $file ) );
		} catch ( \Throwable $e ) {
			$this->problem( $file, 1, 'cannot be parsed: ' . $e->getMessage() );
			return;
		}
		$walk = new NodeTraverser();
		$walk->addVisitor( new NameResolver() );
		$ast = $walk->traverse( (array) $ast );

		foreach ( ( new NodeFinder() )->findInstanceOf( $ast, Class_::class ) as $class ) {
			if ( ! self::is_test_class( $class ) ) {
				continue;
			}
			++$this->classes;
			$this->check_class( $class, $file );
		}
	}

	/**
	 * A concrete class named ...Test that extends another class: what PHPUnit runs as a test case.
	 *
	 * @param Class_ $class
	 *
	 * @return bool
	 */
	public static function is_test_class( Class_ $class ) {
		if ( null === $class->name || $class->isAbstract() || null === $class->extends ) {
			return false;
		}
		if ( 'Test' !== substr( $class->name->toString(), -4 ) ) {
			return false;
		}
		$parent = $class->extends->getLast();

		return (bool) preg_match( '/TestCase$|Test_Case$|Test$|^Unit$/', $parent );
	}

	/**
	 * @param Class_ $class
	 * @param string $file
	 *
	 * @return void
	 */
	private function check_class( Class_ $class, $file ) {
		$targets = 0;
		$nothing = false;
		foreach ( $class->attrGroups as $group ) {
			foreach ( $group->attrs as $attr ) {
				$name = $attr->name->toString();
				if ( 0 !== strpos( $name, self::NS . 'Covers' ) ) {
					continue;
				}
				$kind = substr( $name, strlen( self::NS ) );
				$line = $attr->getStartLine();
				if ( 'CoversNothing' === $kind ) {
					$nothing = true;
					continue;
				}
				++$targets;
				$arg = isset( $attr->args[0] ) ? $attr->args[0]->value : null;
				$this->check_target( $kind, $arg, $file, $line, dirname( $file ) );
			}
		}
		$start = $class->getStartLine();
		if ( $nothing && $targets > 0 ) {
			$this->problem( $file, $start, $class->name . ' has CoversNothing beside other coverage targets' );
		}
		if ( ! $nothing && 0 === $targets ) {
			$this->problem( $file, $start, $class->name . ' declares no coverage target (CoversClass, CoversTrait, CoversFunction, CoversNamespace, CoversFile or CoversNothing)' );
		}
	}

	/**
	 * @param string         $kind
	 * @param Node\Expr|null $arg
	 * @param string         $file
	 * @param int            $line
	 * @param string         $dir
	 *
	 * @return void
	 */
	private function check_target( $kind, $arg, $file, $line, $dir ) {
		switch ( $kind ) {
			case 'CoversClass':
			case 'CoversTrait':
				$class = self::class_name( $arg );
				if ( '' === $class ) {
					$this->problem( $file, $line, $kind . ' needs a Name::class argument' );
					return;
				}
				$found = $this->index->find_class( $class );
				if ( null === $found ) {
					$real = $this->index->alias_target( $class );
					$this->problem( $file, $line, null !== $real ? sprintf( '%s \\%s is a class_alias() of %s; name the real class', $kind, $class, $real ) : sprintf( '%s \\%s is not declared in the source', $kind, $class ) );
					return;
				}
				$is = (string) $this->index->kind_of( $found );
				if ( 'interface' === $is ) {
					$this->problem( $file, $line, sprintf( '%s %s is an interface and holds no code; name the class the test runs', $kind, $found ) );
				} elseif ( 'CoversClass' === $kind && 'trait' === $is ) {
					$this->problem( $file, $line, sprintf( 'CoversClass %s is a trait; PHPUnit needs CoversTrait', $found ) );
				} elseif ( 'CoversTrait' === $kind && 'trait' !== $is ) {
					$this->problem( $file, $line, sprintf( 'CoversTrait %s is a %s; use CoversClass', $found, $is ) );
				}
				return;
			case 'CoversFunction':
				$function = $arg instanceof String_ ? $arg->value : '';
				if ( '' === $function || null === $this->index->find_function( $function ) ) {
					$this->problem( $file, $line, sprintf( "CoversFunction '%s' is not declared in the source", $function ) );
				}
				return;
			case 'CoversNamespace':
				$namespace = $arg instanceof String_ ? $arg->value : '';
				if ( '' === $namespace || ! $this->index->has_namespace( $namespace ) ) {
					$this->problem( $file, $line, sprintf( "CoversNamespace '%s' holds no class in the source", $namespace ) );
				}
				return;
			case 'CoversFile':
				$path = self::path( $arg, $dir );
				$real = null === $path ? false : realpath( $path );
				if ( false === $real ) {
					$this->problem( $file, $line, 'CoversFile names a file that does not exist' . ( null === $path ? ' (use a string or __DIR__ . \'...\')' : ': ' . $path ) );
				} elseif ( ! $this->inside_roots( $real ) ) {
					$this->problem( $file, $line, 'CoversFile names a file outside the source: ' . $real );
				}
				return;
			case 'CoversMethod':
				$this->problem( $file, $line, 'CoversMethod credits only that method\'s own lines, not the private helpers it calls; use CoversClass' );
				return;
			default:
				// CoversClassesThatExtendClass and the like: valid PHPUnit targets this check does not resolve.
				return;
		}
	}

	/**
	 * A coverage attribute's class argument: `Name::class` or a class-name string.
	 *
	 * @param Node\Expr|null $arg
	 *
	 * @return string
	 */
	public static function class_name( $arg ) {
		if ( $arg instanceof ClassConstFetch && $arg->class instanceof Name ) {
			return $arg->class->toString();
		}
		if ( $arg instanceof String_ ) {
			return ltrim( $arg->value, '\\' );
		}

		return '';
	}

	/**
	 * A string, or __DIR__ followed by strings, as PHP would evaluate it in the test file.
	 *
	 * @param Node\Expr|null $arg
	 * @param string         $dir
	 *
	 * @return string|null
	 */
	public static function path( $arg, $dir ) {
		if ( $arg instanceof String_ ) {
			return $arg->value;
		}
		if ( $arg instanceof Dir ) {
			return $dir;
		}
		if ( $arg instanceof Concat ) {
			$left  = self::path( $arg->left, $dir );
			$right = self::path( $arg->right, $dir );

			return null === $left || null === $right ? null : $left . $right;
		}

		return null;
	}

	/**
	 * @param string $real
	 *
	 * @return bool
	 */
	private function inside_roots( $real ) {
		foreach ( $this->roots as $root ) {
			if ( $real === $root || 0 === strpos( $real, rtrim( $root, '/' ) . '/' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param string $file
	 * @param int    $line
	 * @param string $message
	 *
	 * @return void
	 */
	private function problem( $file, $line, $message ) {
		$this->problems[] = array(
			'file'    => $file,
			'line'    => (int) $line,
			'message' => $message,
		);
	}
}
