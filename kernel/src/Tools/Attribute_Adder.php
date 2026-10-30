<?php

namespace Uncanny_Owl\Wp_Test_Kernel\Tools;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Use_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/**
 * Adds PHPUnit attributes next to the docblock tags PHPUnit 12+ no longer reads.
 *
 * The docblocks stay, so a file runs the same under PHPUnit 9 (reads docblocks, ignores attributes)
 * and PHPUnit 13 (reads attributes, ignores docblocks). Optionally makes data providers
 * `public static`, which PHPUnit 9 accepts and PHPUnit 11+ requires.
 *
 * Edits are textual insertions at node positions, so formatting is untouched.
 */
final class Attribute_Adder {

	const ATTRIBUTE_NS = '\\PHPUnit\\Framework\\Attributes\\';

	/** @var array<string, int> */
	public $counts = array();

	/** @var string[] */
	public $notes = array();

	/** @var bool */
	private $converting_covers = false;

	/** @var Source_Index|null */
	private $index = null;

	/**
	 * Check every coverage target against what the plugin declares (see collect_covers()).
	 *
	 * @param Source_Index $index
	 *
	 * @return void
	 */
	public function use_source_index( Source_Index $index ) {
		$this->index = $index;
	}

	/**
	 * @param string $file
	 * @param bool   $static_providers
	 * @param bool   $covers           Also turn @covers, @coversDefaultClass and @coversNothing into
	 *                                 class-level coverage attributes, and remove those docblock lines.
	 *
	 * @return bool Whether the file changed.
	 */
	public function process_file( $file, $static_providers, $covers = false ) {

		$this->converting_covers = (bool) $covers;

		$code = (string) file_get_contents( $file );
		$ast  = ( new ParserFactory() )->createForNewestSupportedVersion()->parse( $code );
		if ( null === $ast ) {
			return false;
		}

		$lines      = explode( "\n", $code );
		$insertions = array(); // line index => string[] lines to insert before it.
		$rewrites   = array(); // line index => replacement line.
		$removals   = array(); // line index => true.
		$finder     = new NodeFinder();
		$context    = $covers ? $this->name_context( $ast, $finder ) : array();

		/** @var ClassLike[] $classes */
		$classes = $finder->findInstanceOf( $ast, ClassLike::class );

		foreach ( $classes as $class ) {
			$this->collect( $class, $lines, $insertions, $file );
			if ( $covers && $class instanceof Class_ ) {
				$tests  = $this->test_classes( $ast, $finder );
				$header = ( 1 === count( $tests ) && $tests[0] === $class ) ? $this->header_nodes( $ast ) : array();
				$this->collect_covers( $class, $header, $lines, $insertions, $removals, $rewrites, $file, $context );
			}

			$providers = array();
			foreach ( $class->getMethods() as $method ) {
				$this->collect( $method, $lines, $insertions, $file );
				foreach ( $this->tags( $method ) as $tag ) {
					if ( 'dataProvider' === $tag[0] && false === strpos( $tag[1], '::' ) ) {
						$providers[ strtolower( $tag[1] ) ] = true;
					}
				}
			}

			if ( $static_providers ) {
				foreach ( $class->getMethods() as $method ) {
					if ( isset( $providers[ strtolower( $method->name->toString() ) ] ) ) {
						$this->make_static( $method, $lines, $rewrites, $file, $finder );
					}
				}
			}
		}

		if ( empty( $insertions ) && empty( $rewrites ) && empty( $removals ) ) {
			return false;
		}

		$out = array();
		foreach ( $lines as $index => $line ) {
			foreach ( $insertions[ $index ] ?? array() as $new_line ) {
				$out[] = $new_line;
			}
			if ( ! isset( $removals[ $index ] ) ) {
				$out[] = $rewrites[ $index ] ?? $line;
			}
		}

		file_put_contents( $file, implode( "\n", $out ) );

		return true;
	}

	/**
	 * @param ClassLike|ClassMethod $node
	 * @param string[]              $lines
	 * @param array<int, string[]>  $insertions
	 * @param string                $file
	 *
	 * @return void
	 */
	private function collect( $node, array $lines, array &$insertions, $file ) {

		$existing = $this->existing_attributes( $node );
		$index    = $node->getStartLine() - 1;
		$indent   = (string) preg_replace( '/\S.*$/', '', $lines[ $index ] ?? '' );
		$adds     = array();

		foreach ( $this->tags( $node ) as $tag ) {
			$attribute = $this->attribute_for( $tag[0], $tag[1], $file, $node );
			if ( null === $attribute ) {
				continue;
			}
			$short = (string) preg_replace( '/\(.*$/s', '', substr( $attribute, strlen( self::ATTRIBUTE_NS ) ) );
			$key   = $short . '|' . ( 'preserveGlobalState' === $tag[0] ? '' : $tag[1] );
			if ( isset( $existing[ $key ] ) || isset( $existing[ $short . '|' ] ) ) {
				continue;
			}
			$existing[ $key ] = true;
			$adds[]           = $indent . '#[' . $attribute . ']';

			$this->counts[ $short ] = ( $this->counts[ $short ] ?? 0 ) + 1;
		}

		if ( ! empty( $adds ) ) {
			$insertions[ $index ] = array_merge( $insertions[ $index ] ?? array(), $adds );
		}
	}

	/**
	 * Docblock tags at the start of a docblock line, as [tag, value] pairs.
	 *
	 * @param Node $node
	 *
	 * @return array<int, array{0: string, 1: string}>
	 */
	private function tags( Node $node ) {
		$doc = $node->getDocComment();
		if ( null === $doc ) {
			return array();
		}

		// Handles both multi-line docblocks and one-liners such as `/** @dataProvider rows */`.
		$text = (string) preg_replace( array( '#^\s*/\*\*#', '#\*/\s*$#' ), '', $doc->getText() );

		$tags = array();
		foreach ( preg_split( '/\R/', $text ) as $line ) {
			$line = (string) preg_replace( '/^\s*\*?\s*/', '', $line );
			if ( preg_match( '/^@([A-Za-z]+)(?:[ \t]+(.*?))?\s*$/', $line, $match ) ) {
				$tags[] = array( $match[1], trim( $match[2] ?? '' ) );
			}
		}

		return $tags;
	}

	/**
	 * @param string $tag
	 * @param string $value
	 * @param string $file
	 * @param Node   $node
	 *
	 * @return string|null Fully-qualified attribute with arguments, or null when not converted.
	 */
	private function attribute_for( $tag, $value, $file, Node $node ) {

		$ns = self::ATTRIBUTE_NS;

		switch ( $tag ) {
			case 'dataProvider':
				if ( false !== strpos( $value, '::' ) ) {
					list( $provider_class, $provider_method ) = explode( '::', $value, 2 );
					return $ns . 'DataProviderExternal( ' . $provider_class . '::class, ' . var_export( $provider_method, true ) . ' )';
				}
				return $ns . 'DataProvider( ' . var_export( $value, true ) . ' )';
			case 'group':
				return '' === $value ? null : $ns . 'Group( ' . var_export( $value, true ) . ' )';
			case 'depends':
				return $ns . 'Depends( ' . var_export( $value, true ) . ' )';
			case 'test':
				return $ns . 'Test';
			case 'after':
				return $ns . 'After';
			case 'before':
				return $ns . 'Before';
			case 'afterClass':
				return $ns . 'AfterClass';
			case 'beforeClass':
				return $ns . 'BeforeClass';
			case 'runInSeparateProcess':
				return $ns . 'RunInSeparateProcess';
			case 'runTestsInSeparateProcesses':
				return $ns . 'RunTestsInSeparateProcesses';
			case 'preserveGlobalState':
				return $ns . 'PreserveGlobalState( ' . ( 'enabled' === $value ? 'true' : 'false' ) . ' )';
			case 'doesNotPerformAssertions':
				return $ns . 'DoesNotPerformAssertions';
			case 'covers':
			case 'coversDefaultClass':
			case 'coversNothing':
				if ( $this->converting_covers ) {
					return null;
				}
				$this->notes[] = sprintf( '%s:%d @%s %s — not converted (pass --covers)', $file, $node->getStartLine(), $tag, $value );
				return null;
			case 'requires':
				$requires = $this->requires_attribute( $value );
				if ( null === $requires ) {
					$this->notes[] = sprintf( '%s:%d @requires %s — not converted', $file, $node->getStartLine(), $value );
				}
				return $requires;
			case 'testWith':
			case 'ticket':
				$this->notes[] = sprintf( '%s:%d @%s %s — not converted', $file, $node->getStartLine(), $tag, $value );
				return null;
		}

		return null;
	}

	/**
	 * The attribute for a PHPUnit 9 `@requires` value. A bare version ("PHP 8.1") meant "at least".
	 *
	 * @param string $value
	 *
	 * @return string|null
	 */
	private function requires_attribute( $value ) {
		$ns    = self::ATTRIBUTE_NS;
		$parts = preg_split( '/\s+/', trim( $value ), 2 );
		$what  = $parts[0] ?? '';
		$rest  = trim( $parts[1] ?? '' );
		if ( '' === $rest ) {
			return null;
		}
		// PHPUnit 13 compares only a full major.minor.patch version, so "8.1" becomes "8.1.0".
		$version = static function ( $requirement ) {
			if ( ! preg_match( '/^([<>=!]*)\s*(\d+(?:\.\d+){0,2})$/', $requirement, $m ) ) {
				return $requirement; // A Composer-style constraint ("^8.1") is passed on as it is.
			}
			$numbers = array_pad( explode( '.', $m[2] ), 3, '0' );

			return ( '' === $m[1] ? '>=' : $m[1] ) . ' ' . implode( '.', $numbers );
		};

		switch ( strtolower( $what ) ) {
			case 'php':
				return $ns . 'RequiresPhp( ' . var_export( $version( $rest ), true ) . ' )';
			case 'phpunit':
				return $ns . 'RequiresPhpunit( ' . var_export( $version( $rest ), true ) . ' )';
			case 'extension':
				$ext = preg_split( '/\s+/', $rest, 2 );
				return $ns . 'RequiresPhpExtension( ' . var_export( $ext[0], true ) . ( isset( $ext[1] ) ? ', ' . var_export( $version( $ext[1] ), true ) : '' ) . ' )';
			case 'function':
				if ( false !== strpos( $rest, '::' ) ) {
					list( $owner, $method ) = explode( '::', $rest, 2 );
					return $ns . 'RequiresMethod( ' . $owner . '::class, ' . var_export( $method, true ) . ' )';
				}
				return $ns . 'RequiresFunction( ' . var_export( $rest, true ) . ' )';
			case 'os':
				return $ns . 'RequiresOperatingSystem( ' . var_export( $rest, true ) . ' )';
			case 'osfamily':
				return $ns . 'RequiresOperatingSystemFamily( ' . var_export( $rest, true ) . ' )';
			case 'setting':
				$setting = preg_split( '/\s+/', $rest, 2 );
				return $ns . 'RequiresSetting( ' . var_export( $setting[0], true ) . ', ' . var_export( $setting[1] ?? '', true ) . ' )';
		}

		return null;
	}

	/**
	 * Attributes already on the node, keyed "ShortName|first string argument" (argument empty when
	 * there is none or it isn't a string), so re-running the tool adds nothing twice.
	 *
	 * @param Node $node
	 *
	 * @return array<string, true>
	 */
	private function existing_attributes( Node $node ) {
		$existing = array();
		if ( ! property_exists( $node, 'attrGroups' ) ) {
			return $existing;
		}

		foreach ( $node->attrGroups as $group ) {
			foreach ( $group->attrs as $attr ) {
				$first = '';
				if ( isset( $attr->args[0] ) && $attr->args[0]->value instanceof Node\Scalar\String_ ) {
					$first = $attr->args[0]->value->value;
				}
				$existing[ $attr->name->getLast() . '|' . $first ] = true;
			}
		}

		return $existing;
	}

	/**
	 * The file's namespace and `use` imports, for resolving class names written in docblocks.
	 *
	 * @param Node[]     $ast
	 * @param NodeFinder $finder
	 *
	 * @return array{namespace: string, uses: array<string, string>}
	 */
	private function name_context( array $ast, NodeFinder $finder ) {
		$namespace = $finder->findFirstInstanceOf( $ast, Namespace_::class );
		$uses      = array();
		foreach ( $finder->findInstanceOf( $ast, Use_::class ) as $use ) {
			if ( Use_::TYPE_NORMAL !== $use->type ) {
				continue;
			}
			foreach ( $use->uses as $item ) {
				$uses[ strtolower( $item->getAlias()->toString() ) ] = $item->name->toString();
			}
		}

		return array(
			'namespace' => ( $namespace instanceof Namespace_ && null !== $namespace->name ) ? $namespace->name->toString() : '',
			'uses'      => $uses,
		);
	}

	/**
	 * Class-level coverage attributes from the coverage tags on a test class and its methods (PHPUnit
	 * 12+ reads coverage targets from the class only, so a method's targets apply to the class), with
	 * the converted docblock lines removed so docblock and attribute can never disagree.
	 *
	 * A method target becomes its class: CoversMethod credits only that method's own lines, so the
	 * private helpers it calls would count as untested, while CoversClass also takes in the class's
	 * traits and parents. The method is still checked, so a stale tag cannot pass unnoticed.
	 *
	 * With a source index every target is checked against what the plugin declares. A target that does
	 * not exist is not converted: its docblock line stays and a note names it, for a fix by hand.
	 *
	 * @param Class_                                                $class
	 * @param Node[]                                                $header     Nodes before the class whose docblock
	 *                                                                          (the file header) carries its tags.
	 * @param string[]                                              $lines
	 * @param array<int, string[]>                                  $insertions
	 * @param array<int, true>                                      $removals
	 * @param array<int, string>                                    $rewrites
	 * @param string                                                $file
	 * @param array{namespace: string, uses: array<string, string>} $context
	 *
	 * @return void
	 */
	private function collect_covers( Class_ $class, array $header, array $lines, array &$insertions, array &$removals, array &$rewrites, $file, array $context ) {

		foreach ( $class->attrGroups as $group ) {
			foreach ( $group->attrs as $attr ) {
				if ( 0 === strpos( $attr->name->getLast(), 'Covers' ) ) {
					return; // Already converted.
				}
			}
		}

		$nodes   = array_merge( $header, array( $class ), $class->getMethods() );
		$found   = array(); // [tag, value, line index, kept text or null, node it applies to]
		$default = '';
		foreach ( $nodes as $node ) {
			$class_level = $node === $class || in_array( $node, $header, true );
			foreach ( $this->coverage_tag_lines( $node, $lines ) as $tag ) {
				$found[] = array( $tag[0], $tag[1], $tag[2], $tag[3], $class_level ? $class : $node );
				if ( 'coversDefaultClass' === $tag[0] && $class_level ) {
					$default = $this->resolve_class( $tag[1], $context );
					if ( null !== $this->index ) {
						$default = (string) $this->checked_class( $default, $file, $tag[2] );
					}
				}
			}
		}
		if ( empty( $found ) ) {
			return;
		}
		// Without @coversDefaultClass, "::name" was a global function to PHPUnit. When no such function
		// exists but the class-level tags name exactly one class that has the method, the tag meant
		// that class's method.
		$header_class = '';
		if ( '' === $default && null !== $this->index ) {
			$named = array();
			foreach ( $found as list( $tag, $value, $index, $keep, $node ) ) {
				if ( 'covers' === $tag && $node === $class && false === strpos( $value, '::' ) ) {
					$resolved = $this->index->find_class( $this->resolve_class( $value, $context ) );
					if ( null !== $resolved ) {
						$named[ $resolved ] = true;
					}
				}
			}
			$header_class = 1 === count( $named ) ? (string) key( $named ) : '';
		}

		$classes   = array();
		$functions = array();
		$nothing   = false;
		$failed    = array(); // line index => true: keep the line as it is.
		foreach ( $found as list( $tag, $value, $index, $keep, $node ) ) {
			if ( 'coversDefaultClass' === $tag ) {
				if ( '' === $default ) {
					$failed[ $index ] = true;
				}
				continue;
			}
			if ( 'coversNothing' === $tag ) {
				if ( $node === $class ) {
					$nothing = true;
				} else {
					// CoversNothing is the one coverage attribute PHPUnit 12+ still takes on a method.
					$at                = $node->getStartLine() - 1;
					$indent            = (string) preg_replace( '/\S.*$/', '', $lines[ $at ] ?? '' );
					$insertions[ $at ] = array_merge( $insertions[ $at ] ?? array(), array( $indent . '#[' . self::ATTRIBUTE_NS . 'CoversNothing]' ) );
					$this->counts['CoversNothing'] = ( $this->counts['CoversNothing'] ?? 0 ) + 1;
				}
				continue;
			}

			$bare   = (string) preg_replace( '/\(\)$/', '', $value );
			$target = $this->coverage_target( $bare, $value, '' !== $default ? $default : $this->header_default( $bare, $header_class, $file, $index ), $context, $file, $index );
			if ( null !== $target && 'function' !== $target[0] && null !== $this->index && 'interface' === $this->index->kind_of( $target[1] ) ) {
				$this->notes[] = sprintf( '%s:%d @covers %s — %s is an interface and holds no code; name the class the test runs — kept', $file, $index + 1, $value, $target[1] );
				$target        = null;
			}
			if ( null === $target ) {
				$failed[ $index ] = true;
				continue;
			}
			if ( 'function' !== $target[0] ) {
				$classes[ $target[1] ] = true;
			} else {
				$functions[ $target[1] ] = true;
			}
		}

		foreach ( $found as list( $tag, $value, $index, $keep ) ) {
			if ( isset( $failed[ $index ] ) ) {
				continue;
			}
			if ( null === $keep ) {
				$removals[ $index ] = true;
			} else {
				$rewrites[ $index ] = $keep;
			}
		}

		$ns    = self::ATTRIBUTE_NS;
		$adds  = array();
		$index = $class->getStartLine() - 1;
		$tab   = (string) preg_replace( '/\S.*$/', '', $lines[ $index ] ?? '' );
		foreach ( array_keys( $classes ) as $name ) {
			// PHPUnit takes a trait only through CoversTrait.
			$attribute = null !== $this->index && 'trait' === $this->index->kind_of( $name ) ? 'CoversTrait' : 'CoversClass';
			$adds[]    = $tab . '#[' . $ns . $attribute . '( ' . $name . '::class )]';
		}
		foreach ( array_keys( $functions ) as $function ) {
			$adds[] = $tab . '#[' . $ns . 'CoversFunction( ' . var_export( $function, true ) . ' )]';
		}
		if ( empty( $adds ) && $nothing ) {
			$adds[] = $tab . '#[' . $ns . 'CoversNothing]';
		}
		foreach ( $adds as $line ) {
			$short                  = (string) preg_replace( '/^\s*#\[\\\\PHPUnit\\\\Framework\\\\Attributes\\\\(\w+).*$/', '$1', $line );
			$this->counts[ $short ] = ( $this->counts[ $short ] ?? 0 ) + 1;
		}
		if ( ! empty( $adds ) ) {
			$insertions[ $index ] = array_merge( $adds, $insertions[ $index ] ?? array() );
		}

		foreach ( $nodes as $node ) {
			$this->remove_emptied_docblock( $node, $lines, $removals );
		}
	}

	/**
	 * The class a "::name" tag belongs to when the test has no @coversDefaultClass: the one class its
	 * class-level tags name, if that class has the method and no function of that name exists.
	 *
	 * @param string $target
	 * @param string $header_class
	 * @param string $file
	 * @param int    $index
	 *
	 * @return string The class, or '' for PHPUnit's reading (a global function).
	 */
	private function header_default( $target, $header_class, $file, $index ) {
		if ( '' === $header_class || 0 !== strpos( $target, '::' ) ) {
			return '';
		}
		$name = substr( $target, 2 );
		if ( null !== $this->index->find_function( $name ) || ! $this->index->has_method( $header_class, $name ) ) {
			return '';
		}
		$this->notes[] = sprintf( '%s:%d @covers %s has no @coversDefaultClass; it is a method of %s, the class the test covers', $file, $index + 1, $target, $header_class );

		return $header_class;
	}

	/**
	 * What a @covers value names: ['class', FQCN], ['method', FQCN, method] or ['function', name].
	 * "::name" is a method of @coversDefaultClass, or without one the global function name() (PHPUnit's
	 * reading); with a source index, whichever of the two exists.
	 *
	 * @param string                                                $target The value without a trailing "()".
	 * @param string                                                $raw
	 * @param string                                                $default
	 * @param array{namespace: string, uses: array<string, string>} $context
	 * @param string                                                $file
	 * @param int                                                   $index
	 *
	 * @return array{0: string, 1: string, 2?: string}|null Null when the target does not exist.
	 */
	private function coverage_target( $target, $raw, $default, array $context, $file, $index ) {
		$where = sprintf( '%s:%d', $file, $index + 1 );
		if ( '' === $target ) {
			$this->notes[] = $where . ' @covers without a target — kept';
			return null;
		}

		if ( 0 === strpos( $target, '::' ) ) {
			$name = substr( $target, 2 );
			if ( '' !== $default && ( null === $this->index || $this->index->has_method( $default, $name ) ) ) {
				return array( 'method', $default, $name );
			}
			if ( null === $this->index || null !== $this->index->find_function( $name ) ) {
				return array( 'function', null === $this->index ? $name : (string) $this->index->find_function( $name ) );
			}
			$this->notes[] = sprintf( '%s @covers %s — no method %s on %s and no function %s() — kept', $where, $target, $name, '' === $default ? '(no @coversDefaultClass)' : $default, $name );
			return null;
		}

		if ( false !== strpos( $target, '::' ) ) {
			list( $owner, $method ) = explode( '::', $target, 2 );
			$owner                    = $this->resolve_class( $owner, $context );
			if ( null === $this->index ) {
				return array( 'method', $owner, $method );
			}
			$found = $this->checked_class( $owner, $file, $index );
			if ( null === $found ) {
				return null;
			}
			if ( ! $this->index->has_method( $found, $method ) ) {
				$this->notes[] = sprintf( '%s @covers %s — %s has no method %s — kept', $where, $target, $found, $method );
				return null;
			}
			return array( 'method', $found, $method );
		}

		$last = substr( (string) strrchr( '\\' . $target, '\\' ), 1 );
		if ( '()' === substr( $raw, -2 ) || ( '' !== $last && ctype_lower( $last[0] ) ) ) {
			if ( null === $this->index || null !== $this->index->find_function( $target ) ) {
				return array( 'function', null === $this->index ? ltrim( $target, '\\' ) : (string) $this->index->find_function( $target ) );
			}
			$this->notes[] = sprintf( '%s @covers %s — no such function — kept', $where, $target );
			return null;
		}

		$class = $this->resolve_class( $target, $context );
		if ( null === $this->index ) {
			return array( 'class', $class );
		}
		$found = $this->checked_class( $class, $file, $index );

		return null === $found ? null : array( 'class', $found );
	}

	/**
	 * A resolved class name checked against the index. A `class_alias()` name becomes its real class;
	 * a name that does not exist but is the short name of exactly one declared class becomes that class
	 * (both noted).
	 *
	 * @param string $class
	 * @param string $file
	 * @param int    $index
	 *
	 * @return string|null
	 */
	private function checked_class( $class, $file, $index ) {
		$found = $this->index->find_class( $class );
		if ( null !== $found ) {
			return $found;
		}
		$real = $this->index->alias_target( $class );
		if ( null !== $real && null !== $this->index->find_class( $real ) ) {
			$this->notes[] = sprintf( '%s:%d %s is a class_alias() of %s — the real class used', $file, $index + 1, $class, $real );
			return (string) $this->index->find_class( $real );
		}
		if ( $this->index->in_context_only( $class ) ) {
			$this->notes[] = sprintf( '%s:%d %s is declared outside the source directories (a parent plugin\'s class), so it cannot be a target of these tests — kept', $file, $index + 1, $class );
			return null;
		}
		$named = $this->index->classes_named( $class );
		if ( 1 === count( $named ) ) {
			$this->notes[] = sprintf( '%s:%d %s does not exist; the one class of that name is %s — used', $file, $index + 1, $class, $named[0] );
			return $named[0];
		}
		$this->notes[] = sprintf( '%s:%d %s does not exist%s — kept', $file, $index + 1, $class, empty( $named ) ? '' : ' (' . count( $named ) . ' classes share its name)' );

		return null;
	}

	/**
	 * Coverage tags in every docblock attached to the node (a node can carry more than one; PHP reads
	 * only the last, which hid tags in the earlier ones).
	 *
	 * @param Node     $node
	 * @param string[] $lines The file's lines: a kept line is rebuilt from it, with its indentation.
	 *
	 * @return array<int, array{0: string, 1: string, 2: int, 3: string|null}> [tag, first word of the
	 *         value, line index, the line without the tag when other text stays on it (else null)]
	 */
	private function coverage_tag_lines( Node $node, array $lines ) {
		$found = array();
		foreach ( $this->doc_comments( $node ) as $doc ) {
			foreach ( preg_split( '/\R/', $doc->getText() ) as $offset => $line ) {
				if ( ! preg_match_all( '/@(covers|coversDefaultClass|coversNothing)\b[ \t]*((?!\*\/)\S*)/', $line, $matches, PREG_SET_ORDER ) ) {
					continue;
				}
				$index = $doc->getStartLine() - 1 + $offset;
				$rest  = (string) preg_replace( '/[ \t]*@(covers|coversDefaultClass|coversNothing)\b[ \t]*(?:(?!\*\/)\S*)/', '', $lines[ $index ] ?? $line );
				$keep  = preg_match( '/^\s*(\/\*\*|\*\/|\*)?\s*(\*\/)?\s*$/', $rest ) ? null : $rest;
				foreach ( $matches as $match ) {
					$found[] = array( $match[1], $match[2], $index, $keep );
				}
			}
		}

		return $found;
	}

	/**
	 * @param Node $node
	 *
	 * @return \PhpParser\Comment\Doc[]
	 */
	private function doc_comments( Node $node ) {
		return array_values(
			array_filter(
				$node->getComments(),
				static function ( $comment ) {
					return $comment instanceof \PhpParser\Comment\Doc;
				}
			)
		);
	}

	/**
	 * The concrete classes named "...Test": a file header's coverage tags belong to the file's one test
	 * class, whatever fixture classes sit beside it.
	 *
	 * @param Node[]     $ast
	 * @param NodeFinder $finder
	 *
	 * @return Class_[]
	 */
	private function test_classes( array $ast, NodeFinder $finder ) {
		return array_values(
			array_filter(
				$finder->findInstanceOf( $ast, Class_::class ),
				static function ( Class_ $class ) {
					return null !== $class->name && ! $class->isAbstract() && 'test' === strtolower( substr( $class->name->toString(), -4 ) );
				}
			)
		);
	}

	/**
	 * Statements before the first class whose docblock is a file header (attached to a namespace, a use
	 * statement or a declare): its coverage tags belong to the file's one test class.
	 *
	 * @param Node[] $ast
	 *
	 * @return Node[]
	 */
	private function header_nodes( array $ast ) {
		$stmts = $ast;
		if ( isset( $ast[0] ) && $ast[0] instanceof Namespace_ ) {
			$stmts = array_merge( array( $ast[0] ), $ast[0]->stmts );
		}
		$header = array();
		foreach ( $stmts as $stmt ) {
			if ( $stmt instanceof ClassLike ) {
				break;
			}
			if ( null !== $stmt->getDocComment() ) {
				$header[] = $stmt;
			}
		}

		return $header;
	}

	/**
	 * A docblock left with nothing but its delimiters (or a one-line docblock that held only a coverage
	 * tag) goes entirely; one that keeps content loses a blank " *" line left just above its close.
	 *
	 * @param Node             $node
	 * @param string[]         $lines
	 * @param array<int, true> $removals
	 *
	 * @return void
	 */
	private function remove_emptied_docblock( Node $node, array $lines, array &$removals ) {
		foreach ( $this->doc_comments( $node ) as $doc ) {
			$first   = $doc->getStartLine() - 1;
			$last    = $doc->getEndLine() - 1;
			$touched = false;
			$empty   = true;
			for ( $i = $first; $i <= $last; $i++ ) {
				if ( isset( $removals[ $i ] ) ) {
					$touched = true;
					continue;
				}
				if ( ! preg_match( '/^\s*(\/\*\*|\*\/|\*)\s*$/', $lines[ $i ] ?? '' ) ) {
					$empty = false;
				}
			}
			if ( ! $touched ) {
				continue;
			}
			if ( $empty ) {
				for ( $i = $first; $i <= $last; $i++ ) {
					$removals[ $i ] = true;
				}
				continue;
			}
			for ( $i = $last - 1; $i > $first; $i-- ) {
				if ( isset( $removals[ $i ] ) ) {
					continue;
				}
				if ( ! preg_match( '/^\s*\*\s*$/', $lines[ $i ] ?? '' ) ) {
					break;
				}
				$removals[ $i ] = true;
			}
		}
	}

	/**
	 * @param string                                                $name
	 * @param array{namespace: string, uses: array<string, string>} $context
	 *
	 * @return string Fully qualified, with a leading backslash.
	 */
	private function resolve_class( $name, array $context ) {
		if ( 0 === strpos( $name, '\\' ) ) {
			return $name;
		}
		$parts = explode( '\\', $name );
		$key   = strtolower( $parts[0] );
		if ( isset( $context['uses'][ $key ] ) ) {
			$parts[0] = $context['uses'][ $key ];
			return '\\' . implode( '\\', $parts );
		}

		return '\\' . ( '' === $context['namespace'] ? '' : $context['namespace'] . '\\' ) . $name;
	}

	/**
	 * @param ClassMethod          $method
	 * @param string[]             $lines
	 * @param array<int, string>   $rewrites
	 * @param string               $file
	 * @param NodeFinder           $finder
	 *
	 * @return void
	 */
	private function make_static( ClassMethod $method, array $lines, array &$rewrites, $file, NodeFinder $finder ) {

		if ( $method->isStatic() && $method->isPublic() ) {
			return;
		}

		$uses_this = $finder->findFirst(
			(array) $method->stmts,
			function ( Node $node ) {
				return $node instanceof Node\Expr\Variable && 'this' === $node->name;
			}
		);

		if ( null !== $uses_this ) {
			$this->notes[] = sprintf( '%s:%d provider %s() uses $this — rewrite by hand', $file, $method->getStartLine(), $method->name->toString() );
			$this->counts['ProviderNeedsRewrite'] = ( $this->counts['ProviderNeedsRewrite'] ?? 0 ) + 1;
			return;
		}

		$index   = $method->getStartLine() - 1;
		$pattern = '/\b(?:(?:public|protected|private|static|final)\s+)*function\s+(' . preg_quote( $method->name->toString(), '/' ) . ')\s*\(/';
		$line    = $rewrites[ $index ] ?? $lines[ $index ];
		$updated = preg_replace( $pattern, 'public static function $1(', $line, 1, $count );

		if ( 1 !== $count ) {
			$this->notes[] = sprintf( '%s:%d provider %s() declaration not on one line — rewrite by hand', $file, $method->getStartLine(), $method->name->toString() );
			return;
		}

		$rewrites[ $index ]              = (string) $updated;
		$this->counts['StaticProvider'] = ( $this->counts['StaticProvider'] ?? 0 ) + 1;
	}
}
