<?php
namespace Uncanny_Automator\Integrations\Foo_App;

use Uncanny_Automator\App_Integrations\App_Integration;

/**
 * Class Foo_App_Integration: an app (SaaS) integration. It has no plugin file, so it sets no plugin file path (R19).
 */
class Foo_App_Integration extends App_Integration {

	/**
	 * @return void
	 */
	protected function setup() {
		$this->set_developer_name( 'Foo App' );
		$this->set_integration_type( 'app' );
		$this->set_distribution_type( 'saas' );
	}
}
