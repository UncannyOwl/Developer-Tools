<?php

namespace Fixture;

/**
 * Two mutants here change nothing a test asserts on some inputs, only raise a PHP warning there.
 */
final class Picker {

	/**
	 * `count( $items ) > 0` to `>= 0` reads a missing key and returns the same null: only the warning shows it.
	 *
	 * @param array<int, string> $items
	 *
	 * @return string|null
	 */
	public static function first( array $items ) {
		if ( count( $items ) > 0 ) {
			return $items[0];
		}

		return null;
	}

	/**
	 * `$index < count( $items )` to `<=` only warns past the end of a list, and returns the wrong item
	 * for a map whose keys run past its count.
	 *
	 * @param array<int, string> $items
	 * @param int                $index
	 *
	 * @return string
	 */
	public static function at( array $items, $index ) {
		if ( $index < count( $items ) ) {
			return (string) $items[ $index ];
		}

		return '';
	}
}
