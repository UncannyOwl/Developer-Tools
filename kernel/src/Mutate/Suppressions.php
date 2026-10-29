<?php

namespace Uncanny_Owl\Wp_Test_Kernel\Mutate;

/**
 * Every mutation suppression carries its reason.
 *
 * Infection's `@infection-ignore-all` silences a class, method or statement. As on the platform
 * (RB27, the same anti-pattern as an unexplained `@phpstan-ignore`), a suppression is allowed only for an
 * equivalent mutant or an unreachable defensive branch, and the comment lines directly above it must say
 * which: a line starting `Equivalent:` or `Reason:`.
 */
final class Suppressions {

	const TAG = '@infection-ignore-all';

	/**
	 * @param string   $root
	 * @param string[] $files Plugin-relative.
	 *
	 * @return array<string, int[]> File => lines of suppressions without a reason.
	 */
	public static function unexplained( $root, array $files ) {
		$found = array();

		foreach ( $files as $file ) {
			$lines = file( rtrim( $root, '/' ) . '/' . $file, FILE_IGNORE_NEW_LINES );
			if ( false === $lines ) {
				continue;
			}
			foreach ( $lines as $index => $line ) {
				if ( false === strpos( $line, self::TAG ) ) {
					continue;
				}
				if ( ! self::has_reason( $lines, $index ) ) {
					$found[ $file ][] = $index + 1;
				}
			}
		}

		return $found;
	}

	/**
	 * Looks up through the contiguous comment lines above (and the tag's own docblock) for a reason.
	 *
	 * @param string[] $lines
	 * @param int      $index
	 *
	 * @return bool
	 */
	private static function has_reason( array $lines, $index ) {
		for ( $i = $index; $i >= 0 && $i >= $index - 6; $i-- ) {
			$text = trim( $lines[ $i ] );
			if ( $i < $index && ! preg_match( '#^(//|\*|/\*)#', $text ) ) {
				return false;
			}
			if ( preg_match( '#^(?://|\*|/\*\*?)\s*(Equivalent|Reason):\s*\S#', $text ) ) {
				return true;
			}
		}

		return false;
	}
}
