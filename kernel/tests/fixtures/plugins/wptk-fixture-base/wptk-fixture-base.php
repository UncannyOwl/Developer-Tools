<?php
/**
 * Plugin Name: Kernel Fixture Base
 * Description: A plugin another fixture requires (kernel/tests/Snapshot_Test.php).
 * Version:     1.0.0
 */

register_activation_hook(
	__FILE__,
	function ( $network_wide ) {
		update_site_option( 'wptk_fixture_base_activated', is_multisite() && $network_wide ? 'network' : 'site' );
	}
);
