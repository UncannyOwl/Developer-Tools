<?php
/**
 * Plugin Name:      Kernel Fixture Dependent
 * Description:      Requires the base fixture, as a paid add-on requires its free plugin (kernel/tests/Snapshot_Test.php).
 * Version:          1.0.0
 * Requires Plugins: wptk-fixture-base
 */

register_activation_hook(
	__FILE__,
	function ( $network_wide ) {
		update_site_option( 'wptk_fixture_dependent_activated', is_multisite() && $network_wide ? 'network' : 'site' );
	}
);
