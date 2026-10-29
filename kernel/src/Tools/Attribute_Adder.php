<?php

namespace Uncanny_Owl\Wp_Test_Kernel\Tools;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
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

	/**
	 * @param string $file
	 * @param bool   $static_providers
	 *
	 * @return bool Whether the file changed.
	 */
	public function process_file( $file, $static_providers ) {

		$code = (string) file_get_contents( $file );
		$ast  = ( new ParserFactory() )->createForNewestSupportedVersion()->parse( $code );
		if ( null === $ast ) {
			return false;
		}

		$lines      = explode( "\n", $code );
		$insertions = array(); // line index => string[] lines to insert before it.
		$rewrites   = array(); // line index => replacement line.
		$finder     = new NodeFinder();

		/** @var ClassLike[] $classes */
		$classes = $finder->findInstanceOf( $ast, ClassLike::class );

		foreach ( $classes as $class ) {
			$this->collect( $class, $lines, $insertions, $file );

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

		if ( empty( $insertions ) && empty( $rewrites ) ) {
			return false;
		}

		foreach ( $rewrites as $index => $line ) {
			$lines[ $index ] = $line;
		}

		krsort( $insertions );
		foreach ( $insertions as $index => $new_lines ) {
			array_splice( $lines, $index, 0, $new_lines );
		}

		file_put_contents( $file, implode( "\n", $lines ) );

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
			case 'requires':
			case 'testWith':
			case 'ticket':
				$this->notes[] = sprintf( '%s:%d @%s %s — not converted', $file, $node->getStartLine(), $tag, $value );
				return null;
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
