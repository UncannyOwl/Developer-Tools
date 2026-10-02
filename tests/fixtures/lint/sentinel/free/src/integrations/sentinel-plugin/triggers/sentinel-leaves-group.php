<?php
namespace Uncanny_Automator\Integrations\Sentinel_Plugin;

use Uncanny_Automator\Recipe\Trigger;

/**
 * A missing key read as "Any" (R15 allows it).
 */
class Sentinel_Leaves_Group extends Trigger {

	public function validate( $trigger, $hook_args ) {
		$selected = $trigger['meta']['SENTINEL_GROUP'] ?? '-1';
		return '-1' === (string) $selected;
	}
}
