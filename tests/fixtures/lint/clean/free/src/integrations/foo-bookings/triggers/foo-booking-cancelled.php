<?php
namespace Uncanny_Automator\Integrations\Foo_Bookings;

use Uncanny_Automator\Recipe\Trigger;

/**
 * Class Foo_Booking_Cancelled
 *
 * "A user's booking for {{a service}} is cancelled". The hook can fire with nobody
 * logged in (staff or cron cancels), so the run binds the booking's customer as an
 * approved record owner (R1). The same cancellation can reach the hook twice, so
 * validate() de-dupes on the booking plus the trigger id (R2).
 *
 * @property Foo_Bookings_Helpers $item_helpers
 */
class Foo_Booking_Cancelled extends Trigger {

	/**
	 * Request-scoped de-dupe, keyed "{booking_id}:{trigger ID}".
	 *
	 * @var array<string,bool>
	 */
	private static $fired = array();

	/**
	 * Code, integration, meta, type and hooks as literals (R5, R6).
	 */
	public static function definition() {
		return self::new_definition( 'FOO_BOOKINGS_BOOKING_CANCELLED', 'FOO_BOOKINGS' )
			->trigger_meta( 'FOO_BOOKINGS_SERVICE' )
			->hook( 'foo_booking_cancelled', 10, 3 );
	}

	/**
	 * Sentences and flags only. The hook fires without the customer's session (R9).
	 *
	 * @return void
	 */
	protected function setup_trigger() {
		$this->set_is_login_required( false );

		$this->set_sentence(
			sprintf(
				/* translators: %1$s: Service */
				esc_html_x( "A user's booking for {{a service:%1\$s}} is cancelled", 'Foo Bookings', 'uncanny-automator' ),
				$this->get_trigger_meta()
			)
		);
		$this->set_readable_sentence( esc_html_x( "A user's booking for {{a service}} is cancelled", 'Foo Bookings', 'uncanny-automator' ) );
	}

	/**
	 * @return array[]
	 */
	public function options() {
		return array(
			array(
				'option_code'     => $this->get_trigger_meta(),
				'label'           => esc_html_x( 'Service', 'Foo Bookings', 'uncanny-automator' ),
				'input_type'      => 'select',
				'required'        => true,
				'options'         => array(),
				'relevant_tokens' => array(),
				'remote_data'     => $this->item_helpers->remote_data_load_config( 'services' ),
			),
		);
	}

	/**
	 * Also runs at fire time: no side effects (R18).
	 *
	 * @param array $trigger
	 * @param array $tokens
	 *
	 * @return array
	 */
	public function define_tokens( $trigger, $tokens ) {
		return array(
			array(
				'tokenId'   => 'BOOKING_ID',
				'tokenName' => esc_html_x( 'Booking ID', 'Foo Bookings', 'uncanny-automator' ),
				'tokenType' => 'int',
			),
			array(
				'tokenId'   => 'SERVICE_ID',
				'tokenName' => esc_html_x( 'Service ID', 'Foo Bookings', 'uncanny-automator' ),
				'tokenType' => 'int',
			),
			array(
				'tokenId'   => 'SERVICE_NAME',
				'tokenName' => esc_html_x( 'Service name', 'Foo Bookings', 'uncanny-automator' ),
				'tokenType' => 'text',
			),
			array(
				'tokenId'   => 'CANCELLATION_DATE',
				'tokenName' => esc_html_x( 'Cancellation date', 'Foo Bookings', 'uncanny-automator' ),
				'tokenType' => 'date',
			),
		);
	}

	/**
	 * Runs once per recipe on this object (R2).
	 *
	 * @param array $trigger
	 * @param array $hook_args
	 *
	 * @return bool
	 */
	public function validate( $trigger, $hook_args ) {

		list( $booking_id, $user_id, $service_id ) = array_pad( (array) $hook_args, 3, null );

		// Failure path: the plugin passes a WP_Error where the user id would be (R1 check 4).
		if ( is_wp_error( $user_id ) || ! is_numeric( $booking_id ) || 0 >= (int) $booking_id ) {
			return false;
		}

		// "Any" is the string '-1'; compare before any cast (R15). A required field with no value fails closed.
		$selected = (string) ( $trigger['meta'][ $this->get_trigger_meta() ] ?? '' );

		if ( '' === $selected ) {
			return false;
		}

		if ( '-1' !== $selected && absint( $selected ) !== absint( $service_id ) ) {
			return false;
		}

		// Record owner (R1): the plugin's own link, never a typed email; never another administrator.
		$user = is_numeric( $user_id ) && 0 < (int) $user_id ? get_user_by( 'id', (int) $user_id ) : false;

		if ( false === $user || ! automator_can_bind_user( $user ) ) {
			return false; // A user trigger cannot run without its user; an anonymous trigger would bind 0 and continue.
		}

		$this->set_user_id( $user->ID );

		// De-dupe on the entity plus the trigger id, request-scoped (R2).
		$key = absint( $booking_id ) . ':' . absint( $trigger['ID'] );

		if ( isset( self::$fired[ $key ] ) ) {
			return false;
		}

		self::$fired[ $key ] = true;

		return true;
	}

	/**
	 * Test seam for the de-dupe state.
	 *
	 * @return void
	 */
	public static function reset_fired() {
		self::$fired = array();
	}

	/**
	 * The full keyset every time (R23).
	 *
	 * @param array $trigger
	 * @param array $hook_args
	 *
	 * @return array
	 */
	public function hydrate_tokens( $trigger, $hook_args ) {

		list( $booking_id, , $service_id ) = array_pad( (array) $hook_args, 3, null );

		return array(
			'BOOKING_ID'        => absint( $booking_id ),
			'SERVICE_ID'        => absint( $service_id ),
			'SERVICE_NAME'      => $this->item_helpers->get_service_name( $service_id ),
			'CANCELLATION_DATE' => current_time( 'mysql' ),
		);
	}
}
