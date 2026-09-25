<?php
namespace Uncanny_Automator\Integrations\Foo_Bookings;

/**
 * Class Foo_Booking_Dispatcher (R17)
 *
 * Derives "approved" from the plugin's generic status-change hook, at priority 9 so it
 * runs before any plugin listener that redirects. The trigger declares the same literal:
 * ->hook( 'automator_foo_bookings_booking_approved', 10, 1 ). Never a constant (R6).
 */
class Foo_Booking_Dispatcher {

	/**
	 * @var bool
	 */
	private static $booted = false;

	/**
	 * Idempotent: load_shared_hooks() may run more than once per request.
	 *
	 * @return void
	 */
	public static function boot() {

		if ( self::$booted ) {
			return;
		}

		self::$booted = true;

		add_action( 'foo_booking_status_changed', array( __CLASS__, 'on_status_changed' ), 9, 3 );
	}

	/**
	 * Test seam.
	 *
	 * @return void
	 */
	public static function reset_boot() {
		self::$booted = false;
	}

	/**
	 * @param int    $booking_id
	 * @param string $old_status
	 * @param string $new_status
	 *
	 * @return void
	 */
	public static function on_status_changed( $booking_id, $old_status, $new_status ) {

		if ( 'approved' !== $new_status || 'approved' === $old_status ) {
			return;
		}

		do_action( 'automator_foo_bookings_booking_approved', absint( $booking_id ) );
	}
}
