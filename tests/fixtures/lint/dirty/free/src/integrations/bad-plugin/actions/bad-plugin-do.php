<?php
namespace Uncanny_Automator\Integrations\Bad_Plugin;

use Uncanny_Automator\Recipe\Action;

/**
 * @property Bad_Plugin_Helpers $item_helpers
 */
class Bad_Plugin_Do extends Action {

	protected function setup_action() {
		$this->set_integration( 'BAD_PLUGIN' );
		$this->set_action_code( 'BAD_DO' );
		$this->set_action_meta( 'BAD_ITEM' );
		$this->set_sentence( sprintf( esc_html_x( 'Do {{a thing:%1$s}}', 'Bad Plugin', 'uncanny-automator' ), $this->get_action_meta() ) );
		$this->set_readable_sentence( esc_html_x( 'Do {{a thing}}', 'Bad Plugin', 'uncanny-automator' ) );
		$this->set_action_tokens( array( 'ID' => array( 'name' => 'ID', 'type' => 'int' ) ), $this->get_action_code() );
	}

	public function options() {
		return array(
			array(
				'option_code' => $this->get_action_meta(),
				'label'       => esc_html_x( 'Thing', 'Bad Plugin', 'uncanny-automator' ),
				'input_type'  => 'select',
				'options'     => array( array( 'text' => 'Any', 'value' => '-1' ) ),
				'remote_data' => $this->item_helpers->remote_data_load_config( 'items' ),
				'ajax'        => array( 'endpoint' => 'bad_plugin_items', 'event' => 'on_load' ),
			),
		);
	}

	protected function process_action( $user_id, $action_data, $recipe_id, $args, $parsed ) {
		$this->add_log_error( 'Failed' );
		return false;
	}
}
