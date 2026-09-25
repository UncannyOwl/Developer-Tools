<?php
namespace Uncanny_Automator\Integrations\Bad_Plugin;

class Bad_Dispatcher {

	const HOOK = 'automator_bad_thing';

	public static function register() {
		add_action( 'bad_plugin_status', array( __CLASS__, 'on_status' ), 10, 2 );
	}

	public static function on_status( $id, $status ) {
		if ( 'done' !== $status ) {
			return;
		}
		do_action( self::HOOK, absint( $id ) );
	}
}
