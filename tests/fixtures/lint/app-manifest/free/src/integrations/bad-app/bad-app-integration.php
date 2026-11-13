<?php
namespace Uncanny_Automator\Integrations\Bad_App;

use Uncanny_Automator\App_Integrations\App_Integration;

/**
 * Class Bad_App_Integration: an app integration that also leaves out its developer name.
 */
class Bad_App_Integration extends App_Integration {

	/**
	 * @return void
	 */
	protected function setup() {
		$this->set_integration_type( 'app' );
		$this->set_distribution_type( 'saas' );
	}
}
