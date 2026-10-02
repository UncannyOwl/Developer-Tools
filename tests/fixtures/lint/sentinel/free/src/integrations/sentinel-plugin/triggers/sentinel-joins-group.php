<?php
namespace Uncanny_Automator\Integrations\Sentinel_Plugin;

use Uncanny_Automator\Recipe\Trigger;

/**
 * An empty selection turned into "Any" (R15).
 */
class Sentinel_Joins_Group extends Trigger {

	public function validate( $trigger, $hook_args ) {
		$selected = $trigger['meta']['SENTINEL_GROUP'] ?: '-1';
		return '-1' === (string) $selected;
	}
}
