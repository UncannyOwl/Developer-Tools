<?php
namespace Uncanny_Automator\Integrations\Fileless_Plugin;

use Uncanny_Automator\Integration;

/**
 * Class Fileless_Plugin_Integration: a plugin integration without its plugin file path.
 */
class Fileless_Plugin_Integration extends Integration {

	/**
	 * @return void
	 */
	protected function setup() {
		$this->set_developer_name( 'Fileless Labs' );
		$this->set_integration_type( 'plugin' );
		$this->set_distribution_type( 'wp_org' );
	}
}
