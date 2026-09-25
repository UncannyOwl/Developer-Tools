<?php
namespace Uncanny_Automator_Pro\Integrations\Foo_Bookings\Conditions;

use Uncanny_Automator_Pro\Action_Condition;
use Uncanny_Automator_Pro\Integrations\Foo_Bookings\Foo_Bookings_Pro_Helpers;

/**
 * Class Foo_Booking_Has_Status (R16)
 *
 * Built in the integration's load() with the helper; never self-instantiated.
 *
 * @property Foo_Bookings_Pro_Helpers $item_helpers
 */
class Foo_Booking_Has_Status extends Action_Condition {

	/**
	 * @return void
	 */
	public function define_condition() {
		$this->integration   = 'FOO_BOOKINGS';
		$this->name          = esc_html_x( '{{A booking}} {{is/is not}} {{a status}}', 'Foo Bookings', 'uncanny-automator-pro' );
		$this->code          = 'FOO_BOOKINGS_BOOKING_HAS_STATUS';
		$this->dynamic_name  = sprintf(
			/* translators: 1: Booking, 2: Criteria, 3: Status */
			esc_html_x( '{{A booking:%1$s}} {{is:%2$s}} {{a status:%3$s}}', 'Foo Bookings', 'uncanny-automator-pro' ),
			'BOOKING',
			'CRITERIA',
			'STATUS'
		);
		$this->is_pro        = true;
		$this->requires_user = false;
	}

	/**
	 * @return array
	 */
	public function fields() {
		return array(
			$this->field->text(
				array(
					'option_code' => 'BOOKING',
					'label'       => esc_html_x( 'Booking ID', 'Foo Bookings', 'uncanny-automator-pro' ),
					'required'    => true,
				)
			),
			$this->field->select_field_args(
				array(
					'option_code'           => 'CRITERIA',
					'label'                 => esc_html_x( 'Criteria', 'Foo Bookings', 'uncanny-automator-pro' ),
					'required'              => true,
					'options'               => array(
						array(
							'value' => 'is',
							'text'  => esc_html_x( 'is', 'Foo Bookings', 'uncanny-automator-pro' ),
						),
						array(
							'value' => 'is-not',
							'text'  => esc_html_x( 'is not', 'Foo Bookings', 'uncanny-automator-pro' ),
						),
					),
					'supports_custom_value' => false,
				)
			),
			$this->field->select_field_args(
				array(
					'option_code' => 'STATUS',
					'label'       => esc_html_x( 'Status', 'Foo Bookings', 'uncanny-automator-pro' ),
					'required'    => true,
					'options'     => $this->item_helpers->get_status_options(),
				)
			),
		);
	}

	/**
	 * condition_failed() only on a mismatch; doing nothing means pass.
	 *
	 * @return void
	 */
	public function evaluate_condition() {

		$booking_id = absint( $this->get_parsed_option( 'BOOKING' ) );
		$matches    = $this->get_parsed_option( 'STATUS' ) === foo_bookings_get_booking_status( $booking_id );
		$wanted     = 'is' === $this->get_parsed_option( 'CRITERIA' );

		if ( $wanted !== $matches ) {
			$this->condition_failed(
				sprintf(
					/* translators: %d: Booking ID */
					esc_html_x( 'Booking %d does not match the selected status.', 'Foo Bookings', 'uncanny-automator-pro' ),
					$booking_id
				)
			);
		}
	}

	/**
	 * @return bool
	 */
	protected function is_dependency_active() {
		return defined( 'FOO_BOOKINGS_VERSION' );
	}
}
