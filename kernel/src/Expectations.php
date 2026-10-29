<?php

namespace Uncanny_Owl\Wp_Test_Kernel;

use ReflectionClass;
use ReflectionMethod;
use Uncanny_Owl\Wp_Test_Kernel\Attributes\Expected_Deprecated;
use Uncanny_Owl\Wp_Test_Kernel\Attributes\Expected_Incorrect_Usage;

/**
 * Reads WordPress' deprecation expectations for a test.
 *
 * PHPUnit 12+ no longer parses docblocks, so core's expectDeprecated() calls this instead. It accepts
 * the kernel attributes and the legacy @expectedDeprecated / @expectedIncorrectUsage docblock tags.
 */
final class Expectations {

	/**
	 * @param string $class_name
	 * @param string $method_name
	 *
	 * @return array{class: array<string, string[]>, method: array<string, string[]>}
	 */
	public static function for_test( $class_name, $method_name ) {

		$class  = new ReflectionClass( $class_name );
		$method = new ReflectionMethod( $class_name, $method_name );

		return array(
			'class'  => self::read( $class->getAttributes(), (string) $class->getDocComment() ),
			'method' => self::read( $method->getAttributes(), (string) $method->getDocComment() ),
		);
	}

	/**
	 * @param \ReflectionAttribute<object>[] $attributes
	 * @param string                         $doc_comment
	 *
	 * @return array<string, string[]>
	 */
	private static function read( array $attributes, $doc_comment ) {

		$found = array(
			'expectedDeprecated'     => array(),
			'expectedIncorrectUsage' => array(),
		);

		foreach ( $attributes as $attribute ) {
			if ( Expected_Deprecated::class === $attribute->getName() ) {
				$found['expectedDeprecated'][] = $attribute->newInstance()->name;
			}
			if ( Expected_Incorrect_Usage::class === $attribute->getName() ) {
				$found['expectedIncorrectUsage'][] = $attribute->newInstance()->name;
			}
		}

		if ( '' !== $doc_comment && preg_match_all( '/@(expectedDeprecated|expectedIncorrectUsage)\s+(\S+)/', $doc_comment, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$found[ $match[1] ][] = $match[2];
			}
		}

		return array_filter( $found );
	}
}
