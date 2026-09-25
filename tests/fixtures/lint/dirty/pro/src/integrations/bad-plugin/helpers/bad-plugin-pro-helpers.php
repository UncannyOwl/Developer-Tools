<?php
namespace Uncanny_Automator_Pro\Integrations\Bad_Plugin;

use Uncanny_Automator\Integrations\Bad_Plugin\Bad_Plugin_Helpers;

class Bad_Plugin_Pro_Helpers extends Bad_Plugin_Helpers {

	public function get_pro_options() {
		if ( method_exists( $this, 'get_item_options' ) ) {
			return parent::get_item_options( false );
		}
		return array();
	}
}
