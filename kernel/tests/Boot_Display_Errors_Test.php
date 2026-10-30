<?php
/**
 * Where the kernel shows PHP errors: stderr in a test process, nowhere when a run asks for none.
 */

use PHPUnit\Framework\TestCase;
use Uncanny_Owl\Wp_Test_Kernel\Boot;

class Boot_Display_Errors_Test extends TestCase {

	protected function tearDown(): void {
		putenv( 'WP_TEST_KERNEL_DISPLAY_ERRORS' );
	}

	public function test_a_test_process_shows_errors_on_stderr(): void {
		$this->assertSame( 'stderr', Boot::display_errors() );
	}

	public function test_a_run_that_asks_for_none_shows_none(): void {
		putenv( 'WP_TEST_KERNEL_DISPLAY_ERRORS=0' );

		$this->assertSame( '0', Boot::display_errors() );
	}

	public function test_no_other_value_moves_them_to_stdout(): void {
		putenv( 'WP_TEST_KERNEL_DISPLAY_ERRORS=stdout' );

		$this->assertSame( 'stderr', Boot::display_errors(), 'Output on stdout makes every later header() in a test warn "headers already sent".' );
	}
}
