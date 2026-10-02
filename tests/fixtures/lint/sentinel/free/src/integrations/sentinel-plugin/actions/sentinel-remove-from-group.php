<?php
namespace Uncanny_Automator\Integrations\Sentinel_Plugin;

use Uncanny_Automator\Recipe\Action;

/**
 * An action's -1 is "All" (R15), loaded from an _all segment (R14).
 *
 * @property Sentinel_Plugin_Helpers $item_helpers
 */
class Sentinel_Remove_From_Group extends Action {

	public function options() {
		return array(
			array(
				'option_code' => 'SENTINEL_GROUP',
				'input_type'  => 'select',
				'options'     => array( array( 'text' => esc_html_x( 'All groups', 'Sentinel Plugin', 'uncanny-automator' ), 'value' => '-1' ) ),
				'remote_data' => $this->item_helpers->remote_data_load_config( 'groups_all' ),
			),
		);
	}

	protected function process_action( $user_id, $action_data, $recipe_id, $args, $parsed ) {
		$group = empty( $parsed['SENTINEL_GROUP'] ) ? '-1' : $parsed['SENTINEL_GROUP'];
		return '-1' === (string) $group;
	}
}
