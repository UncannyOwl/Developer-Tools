<?php
namespace Uncanny_Automator\Integrations\Bad_Plugin;

use Uncanny_Automator\Integration;

class Bad_Plugin_Integration extends Integration {

	protected function setup() {
		$this->helpers = new Bad_Plugin_Helpers();
		$this->set_integration( 'BAD_PLUGIN' );
		$this->set_name( 'Bad Plugin' );
		$this->set_plugin_file_path( 'bad-plugin/bad-plugin.php' );
		$this->set_integration_type( 'plugin' );
		$this->set_distribution_type( 'open_source' );
		add_action( 'init', array( $this, 'boot' ) );
	}

	public function boot() {}

	public function load() {
		new BAD_PLUGIN_THING( $this->helpers );
	}

	public function plugin_active() {
		return is_plugin_active( 'bad-plugin/bad-plugin.php' );
	}
}
