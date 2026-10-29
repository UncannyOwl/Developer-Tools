<?php

namespace Uncanny_Owl\Wp_Test_Kernel\TestCase;

use Uncanny_Owl\Wp_Test_Kernel\Boot;

/**
 * Base class for WordPress tests run by the kernel.
 *
 * Boot::finish() aliases it to Codeception\TestCase\WPTestCase, so existing tests keep their
 * `extends` line while they move off Codeception.
 */
abstract class WPTestCase extends \WP_UnitTestCase {

	/**
	 * Fail on unexpected _doing_it_wrong() / deprecation notices, as WordPress core's test case does.
	 *
	 * wp-browser v3 on Codeception 4 never ran this check (Codeception never calls
	 * _assertPostConditions()). Set WP_TEST_KERNEL_LEGACY_NOTICES=1 to reproduce that for parity runs.
	 *
	 * @return void
	 */
	public function expectedDeprecated() {
		if ( Boot::config()->get( 'legacy_notices' ) ) {
			return;
		}

		parent::expectedDeprecated();
	}
}
