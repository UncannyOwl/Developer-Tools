<?php

namespace Fixture\Shelf;

final class Label {

	/**
	 * @param string $name
	 *
	 * @return string
	 */
	public static function of( $name ) {
		return strtoupper( $name );
	}
}
