<?php
namespace Uncanny_Automator_Pro\Integrations\Bad_Plugin;

use Uncanny_Automator_Pro\Action_Condition;

class Bad_Plugin_Cond extends Action_Condition {

	public function define_condition() {
		$this->integration   = 'BAD_PLUGIN';
		$this->name          = esc_html_x( '{{A thing}} is done', 'Bad Plugin', 'uncanny-automator-pro' );
		$this->code          = 'BAD_THING_DONE';
		$this->dynamic_name  = esc_html_x( '{{A thing:%1$s}} is done', 'Bad Plugin', 'uncanny-automator-pro' );
		$this->is_pro        = true;
		$this->requires_user = false;
	}

	public function fields() {
		return array();
	}

	public function evaluate_condition() {
		$this->condition_failed( 'nope' );
	}

	protected function is_dependency_active() {
		return true;
	}
}

new Bad_Plugin_Cond();
