<?php
/**
 * Junit_Report must give the same id and outcome for one test, whichever runner wrote the report, a
 * different id to every other test, and `wp-test-kernel parity` must compare runs by those ids.
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Uncanny_Owl\Wp_Test_Kernel\Tools\Junit_Report;

class Junit_Report_Test extends TestCase {

	/**
	 * @var string[]
	 */
	private $files = array();

	protected function tearDown(): void {
		foreach ( $this->files as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		$this->files = array();
	}

	/**
	 * @param string $contents
	 *
	 * @return string
	 */
	private function file( $contents ) {
		$file = (string) tempnam( sys_get_temp_dir(), 'junit' );
		file_put_contents( $file, $contents );
		$this->files[] = $file;

		return $file;
	}

	/**
	 * @param string                $xml
	 * @param array<string, string> $renames
	 *
	 * @return array<string, array{outcome: string, file: string, message: string}>
	 */
	private function read( $xml, array $renames = array() ) {
		return Junit_Report::read( $this->file( $xml ), $renames )['tests'];
	}

	/**
	 * @param string $old
	 * @param string $new
	 *
	 * @return array{0: int, 1: string}
	 */
	private function parity( $old, $new ) {
		$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( dirname( __DIR__, 2 ) . '/bin/wp-test-kernel' )
			. ' parity --old=' . escapeshellarg( $this->file( $old ) ) . ' --new=' . escapeshellarg( $this->file( $new ) ) . ' 2>&1';
		exec( $command, $output, $code );

		return array( $code, implode( "\n", $output ) );
	}

	/**
	 * Two test classes in different namespaces with the same short name and method, `First` listed first.
	 *
	 * @param string $first  The first test case's child elements.
	 * @param string $second The second test case's child elements.
	 *
	 * @return string
	 */
	private function two_namespaces( $first, $second ) {
		return '<testsuites><testsuite>'
			. '<testcase name="test_get_license_caches_result" class="First\License_Service_Test" file="/p/tests/first/License_Service_Test.php">' . $first . '</testcase>'
			. '<testcase name="test_get_license_caches_result" class="Second\License_Service_Test" file="/p/tests/second/License_Service_Test.php">' . $second . '</testcase>'
			. '</testsuite></testsuites>';
	}

	public function test_codeception_phpunit_and_pest_names_normalise_to_one_id() {
		$codeception = $this->read( '<testsuites><testsuite><testcase name="test_rows_are_refused | #0" class="Ns\Foo_Test" file="/a/Foo_Test.php"/></testsuite></testsuites>' );
		$phpunit     = $this->read( '<testsuites><testsuite><testcase name="test_rows_are_refused with data set #0" class="Ns\Foo_Test" classname="Ns.Foo_Test" file="/a/Foo_Test.php"/></testsuite></testsuites>' );
		// Pest 5 writes the class as it is, and its "file" is not a path.
		$pest = $this->read( '<testsuites><testsuite><testcase name="Rows are refused with data set #0" class="Ns\Foo_Test" classname="Ns.Foo_Test" file="Foo_ (Ns\Foo_)::Rows are refused with data set #0"/></testsuite></testsuites>' );

		$this->assertSame( array( 'Ns\Foo_Test::rows are refused [0]' ), array_keys( $codeception ) );
		$this->assertSame( array_keys( $codeception ), array_keys( $phpunit ) );
		$this->assertSame( array_keys( $codeception ), array_keys( $pest ) );
	}

	public function test_a_class_pest_cuts_after_a_namespace_ending_in_p_gets_its_suite_name_back() {
		$phpunit = $this->read( '<testsuites><testsuite name="wpunit"><testsuite name="Ns\SliceWP\Helpers\Foo_Test" file="/a/Foo_Test.php"><testcase name="test_x" class="Ns\SliceWP\Helpers\Foo_Test"/></testsuite></testsuite></testsuites>' );
		$pest    = $this->read(
			'<testsuites><testsuite name="wpunit"><testsuite name="Ns\SliceWP\Helpers\Foo_Test" file="Foo_ (Ns\SliceWP\Helpers\Foo_)">'
			. '<testcase name="X" class="Helpers\Foo_Test" classname="Helpers.Foo_Test"/>'
			. '<testsuite name="Y"><testcase name="Y with data set #0" class="Helpers\Foo_Test"/></testsuite>'
			. '</testsuite></testsuite></testsuites>'
		);

		$this->assertSame( array( 'Ns\SliceWP\Helpers\Foo_Test::x' ), array_keys( $phpunit ) );
		$this->assertSame( array( 'Ns\SliceWP\Helpers\Foo_Test::x', 'Ns\SliceWP\Helpers\Foo_Test::y [0]' ), array_keys( $pest ) );
	}

	public function test_a_class_given_only_as_a_dotted_classname_keeps_its_namespace() {
		$tests = $this->read( '<testsuites><testsuite><testcase name="test_x" classname="Ns.Sub.Foo_Test"/></testsuite></testsuites>' );

		$this->assertSame( array( 'Ns\Sub\Foo_Test::x' ), array_keys( $tests ) );
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
		$this->assertSame( Junit_Report::PASS, $tests['T::risky v 9']['outcome'] );
		$this->assertSame( Junit_Report::SKIP, $tests['T::incomplete v 9']['outcome'] );
	}

	public function test_files_are_listed_in_run_order() {
		$file = $this->file( '<testsuites><testsuite><testcase name="a" class="B" file="/b.php"/><testcase name="a" class="A" file="/a.php"/><testcase name="b" class="B" file="/b.php"/></testsuite></testsuites>' );

		$this->assertSame( array( '/b.php', '/a.php' ), Junit_Report::read( $file )['files'] );
	}

	public function test_camel_case_digits_and_a_doubled_prefix_normalise_like_pest() {
		$phpunit = $this->read( '<testsuites><testsuite><testcase name="testNextFridayAt1pm" class="A_Test"/><testcase name="test_test_restore_row" class="A_Test"/><testcase name="test_1pm_tomorrow" class="A_Test"/></testsuite></testsuites>' );
		$pest    = $this->read( '<testsuites><testsuite><testcase name="Next friday at 1 pm" class="A_Test"/><testcase name="Restore row" class="A_Test"/><testcase name="1pm tomorrow" class="A_Test"/></testsuite></testsuites>' );

		$this->assertSame( array( 'A_Test::next friday at 1 pm', 'A_Test::restore row', 'A_Test::1 pm tomorrow' ), array_keys( $phpunit ) );
		$this->assertSame( array_keys( $phpunit ), array_keys( $pest ) );
	}

	public function test_one_short_class_and_method_in_two_namespaces_keep_their_own_outcomes() {
		$tests = $this->read( $this->two_namespaces( '<failure type="PHPUnit\Framework\ExpectationFailedException">Failed asserting that 2 is identical to 1.</failure>', '' ) );

		$this->assertSame(
			array(
				'First\License_Service_Test::get license caches result'  => Junit_Report::FAIL,
				'Second\License_Service_Test::get license caches result' => Junit_Report::PASS,
			),
			array_map(
				static function ( $test ) {
					return $test['outcome'];
				},
				$tests
			)
		);
		$this->assertSame( 'Failed asserting that 2 is identical to 1.', $tests['First\License_Service_Test::get license caches result']['message'] );
	}

	/**
	 * @return array<string, array{0: string, 1: string[]}>
	 */
	public static function reports_with_one_id_twice() {
		return array(
			'short class names from two files' => array(
				'<testsuites><testsuite>'
				. '<testcase name="test_get_license_caches_result" class="License_Service_Test" file="/p/tests/first/License_Service_Test.php"><failure>no</failure></testcase>'
				. '<testcase name="test_get_license_caches_result" class="License_Service_Test" file="/p/tests/second/License_Service_Test.php"/>'
				. '</testsuite></testsuites>',
				array( '/p/tests/first/License_Service_Test.php', '/p/tests/second/License_Service_Test.php' ),
			),
			'two methods that normalise alike' => array(
				'<testsuites><testsuite><testcase name="test_fooBar" class="Ns\Foo_Test"/><testcase name="test_foo_bar" class="Ns\Foo_Test"/></testsuite></testsuites>',
				array( 'Ns\Foo_Test::test_fooBar', 'Ns\Foo_Test::test_foo_bar' ),
			),
		);
	}

	/**
	 * @param string   $xml
	 * @param string[] $named
	 */
	#[DataProvider( 'reports_with_one_id_twice' )]
	public function test_a_report_with_one_id_twice_is_refused_naming_both( $xml, array $named ) {
		try {
			$this->read( $xml );
		} catch ( RuntimeException $e ) {
			foreach ( $named as $part ) {
				$this->assertStringContainsString( $part, $e->getMessage() );
			}
			return;
		}

		$this->fail( 'Two test cases with one id were read without an error.' );
	}

	public function test_renamed_short_names_keep_their_namespace_and_qualified_names_replace_it() {
		$old = $this->read(
			'<testsuites><testsuite><testcase name="test_x" class="Ns\\Usage_Reports_Tests"/><testcase name="test_y" class="Other_Test"/><testcase name="test_z" class="Old\\Structure_Test"/></testsuite></testsuites>',
			array(
				'Usage_Reports_Tests'  => 'Usage_Reports_Test',
				'\\Old\\Structure_Test' => 'New\\Structure_Test',
			)
		);

		$this->assertSame( array( 'Ns\Usage_Reports_Test::x', 'Other_Test::y', 'New\Structure_Test::z' ), array_keys( $old ) );
	}

	public function test_an_old_report_with_short_class_names_maps_onto_namespaced_classes() {
		$old = $this->read(
			'<testsuites><testsuite>'
			. '<testcase name="test_get_license_caches_result" class="License_Service_Test" file="/old/checkout/tests/first/License_Service_Test.php"/>'
			. '<testcase name="test_get_license_caches_result" class="License_Service_Test" file="/old/checkout/tests/second/License_Service_Test.php"/>'
			. '<testcase name="test_x" class="Foo_Test" file="/old/checkout/tests/Foo_Test.php"/>'
			. '<testcase name="test_y" class="Global_Test" file="/old/checkout/tests/Global_Test.php"/>'
			. '</testsuite></testsuites>',
			array(
				'tests/first/License_Service_Test.php'  => 'First\\License_Service_Test',
				'tests/second/License_Service_Test.php' => 'Second\\License_Service_Test',
				'Foo_Test'                              => 'Ns\\Foo_Test',
			)
		);
		$new = $this->read(
			'<testsuites><testsuite>'
			. '<testcase name="test_get_license_caches_result" class="First\License_Service_Test" file="/new/checkout/tests/first/License_Service_Test.php"/>'
			. '<testcase name="test_get_license_caches_result" class="Second\License_Service_Test" file="/new/checkout/tests/second/License_Service_Test.php"/>'
			. '<testcase name="test_x" class="Ns\\Foo_Test" file="/new/checkout/tests/Foo_Test.php"/>'
			. '<testcase name="test_y" class="Global_Test" file="/new/checkout/tests/Global_Test.php"/>'
			. '</testsuite></testsuites>'
		);

		$this->assertSame(
			array( 'First\License_Service_Test::get license caches result', 'Second\License_Service_Test::get license caches result', 'Ns\Foo_Test::x', 'Global_Test::y' ),
			array_keys( $new )
		);

		$this->assertSame( array_keys( $new ), array_keys( $old ) );
	}

	public function test_classes_renamed_onto_one_id_are_refused() {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Old_Test::test_x' );

		$this->read( '<testsuites><testsuite><testcase name="test_x" class="Old_Test"/><testcase name="test_x" class="New_Test"/></testsuite></testsuites>', array( 'Old_Test' => 'New_Test' ) );
	}

	public function test_parity_reports_a_failure_in_either_of_two_classes_with_one_short_name() {
		list( $code, $output ) = $this->parity(
			$this->two_namespaces( '', '' ),
			$this->two_namespaces( '<failure type="PHPUnit\Framework\ExpectationFailedException">Failed asserting that 2 is identical to 1.</failure>', '' )
		);

		$this->assertSame( 1, $code, $output );
		$this->assertStringContainsString( 'UNEXPLAINED First\License_Service_Test::get license caches result: pass → fail', $output );
		$this->assertStringContainsString( '2 tests compared, 1 differences, 1 unexplained', $output );
	}

	public function test_parity_refuses_a_report_with_one_id_twice() {
		list( $code, $output ) = $this->parity(
			'<testsuites><testsuite><testcase name="test_x" class="A_Test" file="/a.php"/><testcase name="testX" class="A_Test" file="/a.php"/></testsuite></testsuites>',
			'<testsuites><testsuite><testcase name="test_x" class="A_Test" file="/a.php"/></testsuite></testsuites>'
		);

		$this->assertSame( 2, $code, $output );
		$this->assertStringContainsString( 'A_Test::test_x', $output );
		$this->assertStringContainsString( 'A_Test::testX', $output );
	}
}
