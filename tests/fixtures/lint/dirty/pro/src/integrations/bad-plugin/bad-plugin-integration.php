<?php
namespace Uncanny_Automator_Pro\Integrations\Bad_Plugin;

use Uncanny_Automator\Integration;
use Uncanny_Automator\Integrations\Bad_Plugin\Bad_Plugin_Helpers;

class Bad_Plugin_Integration extends Integration {

	protected function setup() {
		$this->helpers = new Bad_Plugin_Helpers();
		$this->set_integration( 'BAD_PLUGIN' );
		$this->set_name( 'Bad Plugin' );
		$this->set_plugin_file_path( 'bad-plugin/bad-plugin.php' );
		$this->set_developer_name( 'Someone Else' );
		$this->set_integration_type( 'plugin' );
		$this->set_distribution_type( 'wp_org' );
	}

	public function load() {
		new Bad_Plugin_Cond();
	}

	public function plugin_active() {
		return defined( 'BAD_PLUGIN_VERSION' );
	}
}
