<?php
namespace Uncanny_Automator\Integrations\Foo_Bookings;

use Uncanny_Automator\Recipe\Action;

/**
 * Class Foo_Cancel_Booking (R11, R12)
 *
 * @property Foo_Bookings_Helpers $item_helpers
 */
class Foo_Cancel_Booking extends Action {

	/**
	 * @return void
	 */
	protected function setup_action() {
		$this->set_integration( 'FOO_BOOKINGS' );
		$this->set_action_code( 'FOO_BOOKINGS_CANCEL_BOOKING' );
		$this->set_action_meta( 'FOO_BOOKINGS_BOOKING' );
		$this->set_requires_user( false );

		$this->set_sentence(
			sprintf(
				/* translators: %1$s: Booking */
				esc_html_x( 'Cancel {{a booking:%1$s}}', 'Foo Bookings', 'uncanny-automator' ),
				$this->get_action_meta()
			)
		);
		$this->set_readable_sentence(
			esc_html_x( 'Cancel {{a booking}}', 'Foo Bookings', 'uncanny-automator' )
		);
	}

	/**
	 * Actions never offer "Any": a token or an id (R11).
	 *
	 * @return array[]
	 */
	public function options() {
		return array(
			array(
				'option_code' => $this->get_action_meta(),
				'label'       => esc_html_x( 'Booking ID', 'Foo Bookings', 'uncanny-automator' ),
				'input_type'  => 'text',
				'required'    => true,
			),
		);
	}

	/**
	 * Output tokens (never set_action_tokens()).
	 *
	 * @return array
	 */
	public function define_tokens() {
		return array(
			'BOOKING_ID'     => array(
				'name' => esc_html_x( 'Booking ID', 'Foo Bookings', 'uncanny-automator' ),
				'type' => 'int',
			),
			'BOOKING_STATUS' => array(
				'name' => esc_html_x( 'Booking status', 'Foo Bookings', 'uncanny-automator' ),
				'type' => 'text',
			),
		);
	}

	/**
	 * Guard → validate → write through the plugin's API → check the end state → hydrate → true.
	 *
	 * @param int   $user_id
	 * @param array $action_data
	 * @param int   $recipe_id
	 * @param array $args
	 * @param array $parsed
	 *
	 * @return bool
	 */
	protected function process_action( $user_id, $action_data, $recipe_id, $args, $parsed ) {

		$booking_id = absint( $parsed[ $this->get_action_meta() ] ?? 0 );
		$booking    = foo_bookings_get_booking( $booking_id );

		if ( ! $booking ) {
			$this->add_log_error(
				sprintf(
					/* translators: %d: Booking ID */
					esc_html_x( 'Booking %d does not exist.', 'Foo Bookings', 'uncanny-automator' ),
					$booking_id
				)
			);

			return false;
		}

		foo_bookings_cancel_booking( $booking_id );

		// The end state, not the return value (R12). Bypass the plugin's object cache if it keeps one.
		$status = foo_bookings_get_booking_status( $booking_id );

		if ( 'cancelled' !== $status ) {
			$this->add_log_error( esc_html_x( 'The booking could not be cancelled.', 'Foo Bookings', 'uncanny-automator' ) );

			return false;
		}

		$this->hydrate_tokens(
			array(
				'BOOKING_ID'     => $booking_id,
				'BOOKING_STATUS' => $status,
			)
		);

		return true;
	}
}
