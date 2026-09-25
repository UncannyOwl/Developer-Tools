<?php
namespace Uncanny_Automator\Integrations\Foo_Bookings;

use Uncanny_Automator\Recipe\Abstract_Helpers;

/**
 * Class Foo_Bookings_Helpers — Free.
 *
 * Remote-data segments (R14): the bare segment carries "Any" for triggers, the _strict
 * twin has none, for actions. The framework sets this helper's remote-data id and
 * registers its filter; there is no register_hooks() and no wp_ajax_*.
 */
class Foo_Bookings_Helpers extends Abstract_Helpers {

	/**
	 * Services for trigger pickers ("Any service" first).
	 *
	 * @param mixed $request
	 *
	 * @return array
	 */
	protected function remote_data_get_services( $request ): array {
		return $this->remote_data_success( $this->get_service_options( true ) );
	}

	/**
	 * Services for action pickers (no "Any").
	 *
	 * @param mixed $request
	 *
	 * @return array
	 */
	protected function remote_data_get_services_strict( $request ): array {
		return $this->remote_data_success( $this->get_service_options( false ) );
	}

	/**
	 * @param bool $include_any Prepend the "Any service" option.
	 *
	 * @return array[]
	 */
	public function get_service_options( $include_any ) {

		$options = array();

		if ( $include_any ) {
			$options[] = array(
				'text'  => esc_html_x( 'Any service', 'Foo Bookings', 'uncanny-automator' ),
				'value' => '-1',
			);
		}

		foreach ( foo_bookings_get_services() as $service ) {
			$options[] = array(
				'text'  => $service->name,
				'value' => (string) $service->id,
			);
		}

		return $options;
	}

	/**
	 * A fixed vocabulary: static options, never a remote-data segment (R14).
	 *
	 * @return array[]
	 */
	public function get_status_options() {
		return array(
			array(
				'text'  => esc_html_x( 'Pending', 'Foo Bookings', 'uncanny-automator' ),
				'value' => 'pending',
			),
			array(
				'text'  => esc_html_x( 'Approved', 'Foo Bookings', 'uncanny-automator' ),
				'value' => 'approved',
			),
			array(
				'text'  => esc_html_x( 'Cancelled', 'Foo Bookings', 'uncanny-automator' ),
				'value' => 'cancelled',
			),
		);
	}

	/**
	 * Read through the plugin's own API, never its tables (R13).
	 *
	 * @param int $service_id
	 *
	 * @return string
	 */
	public function get_service_name( $service_id ) {
		$service = foo_bookings_get_service( absint( $service_id ) );

		return $service ? (string) $service->name : '';
	}
}
