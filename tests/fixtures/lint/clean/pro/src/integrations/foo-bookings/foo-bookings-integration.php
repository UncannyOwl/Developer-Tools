<?php
namespace Uncanny_Automator_Pro\Integrations\Foo_Bookings;

use Uncanny_Automator\Integration;

/**
 * Class Foo_Bookings_Integration — Pro. Same base class; Pro namespace; composed helper.
 */
class Foo_Bookings_Integration extends Integration {

	/**
	 * @return void
	 */
	protected function setup() {
		$this->helpers = new Foo_Bookings_Pro_Helpers();

		$this->set_integration( 'FOO_BOOKINGS' );
		$this->set_name( 'Foo Bookings' );
		$this->set_icon_url( plugin_dir_url( __FILE__ ) . 'img/foo-bookings-icon.svg' );

		// Identical to Free's declaration: Pro's registration replaces Free's (R19).
		$this->set_plugin_file_path( 'foo-bookings/foo-bookings.php' );
		$this->set_developer_name( 'Foo Labs' );
		$this->set_integration_type( 'plugin' );
		$this->set_distribution_type( 'wp_org' );
	}

	/**
	 * Pro's own run-time listeners (R17). Foo Bookings Pro has none; a Pro dispatcher
	 * would be booted here, exactly as Free boots its own.
	 *
	 * @return void
	 */
	public function load_shared_hooks() {
	}

	/**
	 * @return void
	 */
	public function load() {
		$this->load_shared_hooks();

		new Foo_Booking_Rescheduled( $this->helpers );
		new Foo_Approve_Booking( $this->helpers );

		// Conditions: the helper is the only constructor argument (R16).
		new Conditions\Foo_Booking_Has_Status( $this->helpers );

		// Loop filters: the helper is the 4th positional argument (R16).
		new Loop_Filters\Foo_Bookings_Have_Status( null, array(), null, $this->helpers );
	}

	/**
	 * @return bool
	 */
	public function plugin_active() {
		return defined( 'FOO_BOOKINGS_VERSION' );
	}
}
