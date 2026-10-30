<?php

namespace Uncanny_Owl\Wp_Test_Kernel\TestCase;

use Uncanny_Owl\Wp_Test_Kernel\Boot;

/**
 * Base class for WordPress tests run by the kernel.
 *
 * Boot::finish() aliases it to Codeception\TestCase\WPTestCase, so existing tests keep their
 * `extends` line while they move off Codeception.
 *
 * Keeps wp-browser's stub helpers ($this->make(), makeEmpty(), construct() and
 * Codeception\Stub\Expected) through codeception/stub, a standalone library that registers
 * each stub with PHPUnit so its expectations are verified like any mock's.
 */
abstract class WPTestCase extends \WP_UnitTestCase {

	use \Codeception\Test\Feature\Stub;

	/**
	 * $_SERVER as the test found it; null until set_up() runs (a test may skip before it calls it).
	 *
	 * @var array<string, mixed>|null
	 */
	private $server_before_test = null;

	/**
	 * Keep $_SERVER as the test found it, so tear_down() can put it back.
	 *
	 * WordPress' test case empties $_GET and $_POST before every test but resets $_SERVER only for
	 * core's own suite. A plugin test that sets a request header, or unsets REMOTE_ADDR, would
	 * otherwise hand that request to every test after it in the same process.
	 *
	 * @return void
	 */
	public function set_up() {
		$this->server_before_test = $_SERVER;

		parent::set_up();
	}

	/**
	 * After WordPress' own tear_down (rollback, hooks restored, caches flushed), put $_SERVER back and
	 * run the plugin's after_each_test callables: in-process caches a rollback cannot reach, which
	 * would otherwise make a test depend on what ran before it in the same process.
	 *
	 * @return void
	 */
	public function tear_down() {
		parent::tear_down();

		if ( null !== $this->server_before_test ) {
			$_SERVER                  = $this->server_before_test;
			$this->server_before_test = null;
		}

		foreach ( (array) Boot::config()->get( 'after_each_test' ) as $callback ) {
			if ( is_callable( $callback ) ) {
				call_user_func( $callback );
			}
		}
	}

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
