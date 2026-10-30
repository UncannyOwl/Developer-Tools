<?php
/**
 * Snapshot builder: the plugin activation install.php (a single site) and network-activate.php (a
 * network) share.
 */

/**
 * Activate the profile's plugins in order, as wp-admin does.
 *
 * @param string[] $plugins      Plugin files, relative to WP_PLUGIN_DIR.
 * @param bool     $network_wide Network-activate them; WordPress must have booted as the network.
 *
 * @return int Exit status: 0, or 1 once a plugin cannot be activated, after printing why.
 */
function wp_test_kernel_activate_plugins( array $plugins, $network_wide ) {

	foreach ( $plugins as $plugin ) {
		echo ( $network_wide ? 'Network-activating ' : 'Activating ' ) . $plugin . PHP_EOL;

		$result = activate_plugin( $plugin, '', $network_wide, false );

		// WordPress activates a plugin that prints during activation and only reports the output, as
		// wp-admin does; so does the kernel (wp-browser ignored it), showing what was printed.
		if ( is_wp_error( $result ) && 'unexpected_output' === $result->get_error_code() ) {
			fwrite( STDERR, 'Note: ' . $plugin . ' printed ' . strlen( (string) $result->get_error_data() ) . ' characters while activating:' . PHP_EOL . wp_strip_all_tags( (string) $result->get_error_data() ) . PHP_EOL );
			continue;
		}

		if ( is_wp_error( $result ) ) {
			fwrite( STDERR, $result->get_error_message() . PHP_EOL );
			if ( 'plugin_not_found' === $result->get_error_code() ) {
				fwrite( STDERR, sprintf( 'The kernel loads plugins from %1$s, the folder holding the plugin under test. Put %2$s there (a symlink to the copy in your WordPress install works), or set WP_TEST_KERNEL_PLUGINS_DIR.', WP_PLUGIN_DIR, dirname( $plugin ) ) . PHP_EOL );
			}
			// unexpected_output carries what the plugin printed: show it, it is the whole diagnosis.
			$data = $result->get_error_data();
			if ( is_string( $data ) && '' !== $data ) {
				fwrite( STDERR, $data . PHP_EOL );
			}

			return 1;
		}
	}

	return 0;
}
