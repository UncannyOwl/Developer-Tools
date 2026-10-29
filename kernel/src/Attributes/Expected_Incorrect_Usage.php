<?php

namespace Uncanny_Owl\Wp_Test_Kernel\Attributes;

use Attribute;

/**
 * The test expects WordPress to report _doing_it_wrong() for this function.
 */
#[Attribute( Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE )]
final class Expected_Incorrect_Usage {

	/** @var string */
	public $name;

	/**
	 * @param string $name
	 */
	public function __construct( $name ) {
		$this->name = $name;
	}
}
