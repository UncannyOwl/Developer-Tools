<?php
namespace Uncanny_Automator_Pro\Integrations\Bad_Plugin;

use Uncanny_Automator_Pro\Loops\Filter\Base\Loop_Filter;

class Bad_Lf extends Loop_Filter {

	public function setup() {
		$this->set_integration( 'BAD_PLUGIN' );
		$this->set_meta( 'BAD_LF' );
		$this->set_sentence( 'A thing is done' );
		$this->set_fields( array( $this, 'load_options' ) );
		$this->set_entities( array( $this, 'retrieve' ) );
	}

	public function load_options() {
		return array();
	}

	public function retrieve( $fields ) {
		return array();
	}
}
