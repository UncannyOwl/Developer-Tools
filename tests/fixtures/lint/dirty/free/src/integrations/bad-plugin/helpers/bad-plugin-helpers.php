<?php
namespace Uncanny_Automator\Integrations\Bad_Plugin;

use Uncanny_Automator\Recipe\Abstract_Helpers;

class Bad_Plugin_Helpers extends Abstract_Helpers {

	/**
	 * Mirrors bad-plugin/includes/class-items.php:88 in 2.1.0.
	 */
	public function remote_data_get_items( $request ): array {
		return $this->remote_data_success( $this->get_item_options( true ) );
	}

	public function get_item_options( $include_any ) {
		global $wpdb;
		$rows    = $wpdb->get_results( "SELECT id, name FROM {$wpdb->prefix}bad_items" );
		$options = array();
		foreach ( $rows as $row ) {
			$meta      = maybe_unserialize( $row->name );
			$options[] = array( 'text' => (string) $meta, 'value' => (string) $row->id );
		}
		return $options;
	}
}
