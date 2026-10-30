<?php

namespace Fixture\Shelf;

final class Shelf {

	/**
	 * @param string[] $names
	 *
	 * @return int
	 */
	public function count_named( array $names ) {
		$count = 0;
		foreach ( $names as $name ) {
			if ( '' !== trim( $name ) ) {
				++$count;
			}
		}

		return $count;
	}
}
