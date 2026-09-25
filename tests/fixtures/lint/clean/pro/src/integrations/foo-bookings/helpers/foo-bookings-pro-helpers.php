<?php
namespace Uncanny_Automator_Pro\Integrations\Foo_Bookings;

use Uncanny_Automator\Integrations\Foo_Bookings\Foo_Bookings_Helpers;
use Uncanny_Automator_Pro\Recipe\Abstract_Pro_Helpers;

/**
 * Class Foo_Bookings_Pro_Helpers — the full decomposition (R3).
 *
 * One wrapper per public Free method and per Free segment, whether or not anything
 * calls it today. Pro-only methods and segments live here and only here. No guards.
 *
 * @property Foo_Bookings_Helpers $base
 */
class Foo_Bookings_Pro_Helpers extends Abstract_Pro_Helpers {

	const ANY_VALUE = '-1';

	public function __construct() {
		$this->base = new Foo_Bookings_Helpers();
	}

	/**
	 * The framework sets the remote-data id on this helper only; pass it down so a
	 * Free field builder reached through a wrapper emits the same id.
	 *
	 * @param string $remote_data_id
	 *
	 * @return void
	 */
	public function set_remote_data_id( string $remote_data_id ) {
		parent::set_remote_data_id( $remote_data_id );
		$this->base->set_remote_data_id( $remote_data_id );
	}

	// ----- One wrapper per public Free method, Free's exact signature, no logic. -----

	public function get_service_options( $include_any ) {
		return $this->base->get_service_options( $include_any );
	}

	public function get_status_options() {
		return $this->base->get_status_options();
	}

	public function get_service_name( $service_id ) {
		return $this->base->get_service_name( $service_id );
	}

	// ----- One wrapper per Free remote-data segment. -----

	protected function remote_data_get_services( $request ): array {
		return $this->base->process_remote_data_request( 'services', $request );
	}

	protected function remote_data_get_services_strict( $request ): array {
		return $this->base->process_remote_data_request( 'services_strict', $request );
	}

	// ----- Pro-only segments and methods. -----

	/**
	 * @param mixed $request
	 *
	 * @return array
	 */
	protected function remote_data_get_bookings_strict( $request ): array {
		return $this->remote_data_success( $this->get_booking_options() );
	}

	/**
	 * @return array[]
	 */
	public function get_booking_options() {

		$options = array();

		foreach ( foo_bookings_get_bookings( array( 'status' => 'any' ) ) as $booking ) {
			$options[] = array(
				'text'  => sprintf( '#%1$d — %2$s', $booking->id, $this->get_service_name( $booking->service_id ) ),
				'value' => (string) $booking->id,
			);
		}

		return $options;
	}
}
