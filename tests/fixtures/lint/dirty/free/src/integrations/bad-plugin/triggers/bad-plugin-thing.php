<?php
namespace Uncanny_Automator\Integrations\Bad_Plugin;

use Uncanny_Automator\Recipe\Trigger;

class BAD_PLUGIN_THING extends Trigger {

	private static $seen = array();

	protected function setup_trigger() {
		$this->set_integration( 'BAD_PLUGIN' );
		$this->set_trigger_code( 'BAD_THING' );
		$this->set_trigger_meta( 'BAD_ITEM' );
		$this->set_is_login_required( true );
		$this->add_action( 'bad_plugin_thing', 10, 2 );
		$this->set_sentence( sprintf( esc_html__( 'A user does {{a thing:%1$s}}', 'uncanny-automator' ), $this->get_trigger_meta() ) );
		$this->set_readable_sentence( esc_html__( 'A user does {{a thing}}', 'uncanny-automator' ) );
	}

	public function options() {
		return array( $this->get_item_helpers()->get_item_options( true ) );
	}

	public function validate( $trigger, $hook_args ) {
		list( $item_id, $email ) = $hook_args;
		$user = get_user_by( 'email', $email );
		$raw  = $_POST['bad_field'];
		if ( isset( self::$seen[ $item_id ] ) ) {
			return false;
		}
		self::$seen[ $item_id ] = true;
		return $user && $raw;
	}
}
