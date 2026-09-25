<?php
namespace Uncanny_Automator_Pro\Integrations\Foo_Bookings\Loop_Filters;

use Uncanny_Automator\Automator_Status;
use Uncanny_Automator_Pro\Integrations\Foo_Bookings\Foo_Bookings_Pro_Helpers;
use Uncanny_Automator_Pro\Loops\Filter\Base\Loop_Filter;
use Uncanny_Automator_Pro\Loops\Loop\Exception\Loops_Exception;

/**
 * Class Foo_Bookings_Have_Status (R16)
 *
 * Bookings are a post type here, so this narrows a `posts` loop. Built in load() as
 * new Loop_Filters\Foo_Bookings_Have_Status( null, array(), null, $this->helpers ).
 * Fields use 'type' (not 'input_type') and are keyed by the meta. One query for the set.
 *
 * @property Foo_Bookings_Pro_Helpers $item_helpers
 */
final class Foo_Bookings_Have_Status extends Loop_Filter {

	/**
	 * @return void
	 */
	public function setup() {
		$this->set_integration( 'FOO_BOOKINGS' );
		$this->set_meta( 'FOO_BOOKINGS_BOOKINGS_HAVE_STATUS' );
		// Loop filters reverse the trigger wiring: plain braces here, coded braces in set_sentence_readable().
		$this->set_sentence( esc_html_x( 'A booking has {{a status}}', 'Foo Bookings', 'uncanny-automator-pro' ) );
		$this->set_sentence_readable(
			sprintf(
				/* translators: %1$s: Status */
				esc_html_x( 'A booking has {{a status:%1$s}}', 'Foo Bookings', 'uncanny-automator-pro' ),
				'STATUS'
			)
		);
		$this->set_loop_type( 'posts' );
		$this->set_fields( array( $this, 'load_options' ) );
		$this->set_entities( array( $this, 'retrieve_bookings' ) );
	}

	/**
	 * @return array
	 */
	public function load_options() {
		return array(
			$this->get_meta() => array(
				array(
					'option_code'           => 'STATUS',
					'type'                  => 'select',
					'label'                 => esc_html_x( 'Status', 'Foo Bookings', 'uncanny-automator-pro' ),
					'required'              => true,
					'options'               => $this->item_helpers->get_status_options(),
					'supports_custom_value' => false,
				),
			),
		);
	}

	/**
	 * The whole set in one query; never a query per entity.
	 *
	 * @param array $fields
	 *
	 * @return int[]
	 */
	public function retrieve_bookings( $fields ) {

		$status = isset( $fields['STATUS'] ) ? sanitize_key( $fields['STATUS'] ) : '';

		if ( '' === $status ) {
			throw new Loops_Exception(
				esc_html_x( 'The loop filter needs a status.', 'Foo Bookings', 'uncanny-automator-pro' ),
				Automator_Status::COMPLETED_WITH_ERRORS
			);
		}

		// Through the plugin's own query API where it has one (R13); a prepared query on its post type otherwise.
		return array_map( 'absint', foo_bookings_get_booking_ids( array( 'status' => $status ) ) );
	}
}
