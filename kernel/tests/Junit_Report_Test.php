<?php
/**
 * Junit_Report must give the same id and outcome for one test, whichever runner wrote the report.
 */

use PHPUnit\Framework\TestCase;
use Uncanny_Owl\Wp_Test_Kernel\Tools\Junit_Report;

class Junit_Report_Test extends TestCase {

	/**
	 * @param string $xml
	 *
	 * @return array<string, array{outcome: string, file: string, message: string}>
	 */
	private function read( $xml ) {
		$file = tempnam( sys_get_temp_dir(), 'junit' );
		file_put_contents( $file, $xml );
		$tests = Junit_Report::read( $file )['tests'];
		unlink( $file );

		return $tests;
	}

	public function test_codeception_phpunit_and_pest_names_normalise_to_one_id() {
		$codeception = $this->read( '<testsuites><testsuite><testcase name="test_rows_are_refused | #0" class="Ns\Foo_Test" file="/a/Foo_Test.php"/></testsuite></testsuites>' );
		$phpunit     = $this->read( '<testsuites><testsuite><testcase name="test_rows_are_refused with data set #0" class="Ns\Foo_Test" file="/a/Foo_Test.php"/></testsuite></testsuites>' );
		$pest        = $this->read( '<testsuites><testsuite><testcase name="Rows are refused with data set #0" class="Foo_Test" file="/a/Foo_Test.php"/></testsuite></testsuites>' );

		$this->assertSame( array( 'Foo_Test::rows are refused [0]' ), array_keys( $codeception ) );
		$this->assertSame( array_keys( $codeception ), array_keys( $phpunit ) );
		$this->assertSame( array_keys( $codeception ), array_keys( $pest ) );
	}

	public function test_named_data_sets_keep_their_name() {
		$tests = $this->read( '<testsuites><testsuite><testcase name="test_x with data set &quot;not JSON&quot;" class="Foo_Test"/></testsuite></testsuites>' );

		$this->assertSame( array( 'Foo_Test::x [not JSON]' ), array_keys( $tests ) );
	}

	public function test_outcomes_are_classified_the_same_for_both_runners() {
		$tests = $this->read(
			'<testsuites><testsuite>'
			. '<testcase name="test_pass" class="T"/>'
			. '<testcase name="test_fail" class="T"><failure type="PHPUnit\Framework\ExpectationFailedException">T::test_fail' . "\n" . 'Failed asserting that false is true.</failure></testcase>'
			. '<testcase name="test_error" class="T"><error type="Error">boom</error></testcase>'
			. '<testcase name="test_skip" class="T"><skipped/></testcase>'
			. '<testcase name="test_risky_v9" class="T"><error type="PHPUnit\Framework\RiskyTestError">risky</error></testcase>'
			. '<testcase name="test_incomplete_v9" class="T"><error type="PHPUnit\Framework\IncompleteTestError">todo</error></testcase>'
			. '</testsuite></testsuites>'
		);

		$this->assertSame( Junit_Report::PASS, $tests['T::pass']['outcome'] );
		$this->assertSame( Junit_Report::FAIL, $tests['T::fail']['outcome'] );
		$this->assertSame( 'Failed asserting that false is true.', $tests['T::fail']['message'] );
		$this->assertSame( Junit_Report::FAIL, $tests['T::error']['outcome'] );
		$this->assertSame( Junit_Report::SKIP, $tests['T::skip']['outcome'] );
		$this->assertSame( Junit_Report::PASS, $tests['T::risky v9']['outcome'] );
		$this->assertSame( Junit_Report::SKIP, $tests['T::incomplete v9']['outcome'] );
	}

	public function test_files_are_listed_in_run_order() {
		$file = tempnam( sys_get_temp_dir(), 'junit' );
		file_put_contents( $file, '<testsuites><testsuite><testcase name="a" class="B" file="/b.php"/><testcase name="a" class="A" file="/a.php"/><testcase name="b" class="B" file="/b.php"/></testsuite></testsuites>' );

		$this->assertSame( array( '/b.php', '/a.php' ), Junit_Report::read( $file )['files'] );
		unlink( $file );
	}
}
