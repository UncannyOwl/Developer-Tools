<?php
/**
 * PHPUnit adapter layer.
 *
 * uncanny-kernel: replaces the Yoast PHPUnit Polyfills base (whose 4.x line stops at PHPUnit 12).
 * Maps PHPUnit's fixture methods onto WordPress' snake_case ones, the way the polyfills do.
 * Nothing here is final, because Automator tests override setUp()/tearDown() directly.
 */
abstract class PHPUnit_Adapter_TestCase extends \PHPUnit\Framework\TestCase {

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		static::set_up_before_class();
	}

	public static function tearDownAfterClass(): void {
		static::tear_down_after_class();
		parent::tearDownAfterClass();
	}

	protected function setUp(): void {
		parent::setUp();
		$this->set_up();
	}

	protected function tearDown(): void {
		$this->tear_down();
		parent::tearDown();
	}

	protected function assertPreConditions(): void {
		parent::assertPreConditions();
		$this->assert_pre_conditions();
	}

	protected function assertPostConditions(): void {
		parent::assertPostConditions();
		$this->assert_post_conditions();
	}

	public static function set_up_before_class() {
	}

	public static function tear_down_after_class() {
	}

	protected function set_up() {
	}

	protected function tear_down() {
	}

	protected function assert_pre_conditions() {
	}

	protected function assert_post_conditions() {
	}
}
