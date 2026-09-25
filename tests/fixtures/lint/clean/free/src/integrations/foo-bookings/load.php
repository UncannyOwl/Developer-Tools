<?php
/**
 * load.php — Free. Pro imports from Uncanny_Automator_Pro\Integrations\Foo_Bookings instead.
 */

use Uncanny_Automator\Integrations\Foo_Bookings\Foo_Bookings_Integration;

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

if ( ! class_exists( '\\Uncanny_Automator\\Integrations\\Foo_Bookings\\Foo_Bookings_Integration' ) ) {
	return;
}

new Foo_Bookings_Integration();
