<?php
namespace Uncanny_Automator\Integrations\Foo_Bookings;

use Uncanny_Automator\Integration;

/**
 * Class Foo_Bookings_Integration — Free.
 *
 * setup(): metadata only, on every request (R8, R19).
 * load_shared_hooks(): run-time listeners (R17).
 * load(): builder-mode construction of every part.
 */
class Foo_Bookings_Integration extends Integration {

	/**
	 * @return void
	 */
	protected function setup() {
		$this->helpers = new Foo_Bookings_Helpers();

		$this->set_integration( 'FOO_BOOKINGS' );
		$this->set_name( 'Foo Bookings' );
		$this->set_icon_url( plugin_dir_url( __FILE__ ) . 'img/foo-bookings-icon.svg' );

		// From the scope doc's Integration manifest table. Identical in Pro (R19).
		$this->set_plugin_file_path( 'foo-bookings/foo-bookings.php' );
		$this->set_developer_name( 'Foo Labs' );
		$this->set_integration_type( 'plugin' );
		$this->set_distribution_type( 'wp_org' );
	}

	/**
	 * Run-time listeners. Called first from load(), and alone in targeted mode.
	 *
	 * @return void
	 */
	public function load_shared_hooks() {
		Foo_Booking_Dispatcher::boot();
	}

	/**
	 * @return void
	 */
	public function load() {
		$this->load_shared_hooks();

		new Foo_Booking_Cancelled( $this->helpers );
		new Foo_Booking_Approved( $this->helpers );
		new Foo_Cancel_Booking( $this->helpers );
	}

	/**
	 * Base plugin only; never is_plugin_active(), never a Pro add-on (R20).
	 *
	 * @return bool
	 */
	public function plugin_active() {
		return defined( 'FOO_BOOKINGS_VERSION' );
	}
}
