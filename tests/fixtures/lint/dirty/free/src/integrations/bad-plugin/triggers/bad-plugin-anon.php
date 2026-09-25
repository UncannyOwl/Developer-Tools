<?php
namespace Uncanny_Automator\Integrations\Bad_Plugin;

use Uncanny_Automator\Recipe\Trigger;

/**
 * @property Bad_Plugin_Helpers $item_helpers
 */
class Bad_Plugin_Anon extends Trigger {

	public static function definition() {
		return self::new_definition( 'BAD_ANON', 'BAD_PLUGIN' )
			->trigger_meta( 'BAD_ITEM' )
			->trigger_type( 'anonymous' )
			->hook( Bad_Dispatcher::HOOK, 10, 1 );
	}

	protected function setup_trigger() {
		$this->set_is_login_required( false );
		$this->set_sentence( sprintf( esc_html_x( 'A user does {{a thing:%1$s}} anonymously', 'Bad Plugin', 'uncanny-automator' ), $this->get_trigger_meta() ) );
		$this->set_readable_sentence( esc_html_x( 'A user does {{a thing}} anonymously', 'Bad Plugin', 'uncanny-automator' ) );
	}

	public function validate( $trigger, $hook_args ) {
		$selected = absint( $trigger['meta'][ $this->get_trigger_meta() ] );
		return $selected === absint( $hook_args[0] );
	}
}
