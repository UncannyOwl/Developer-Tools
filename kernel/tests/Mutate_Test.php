<?php
/**
 * bin/mutate's rules: scores, the floor ratchet, the targets, the mirror mapping and suppressions.
 */

use PHPUnit\Framework\TestCase;
use Uncanny_Owl\Wp_Test_Kernel\Mutate\Baseline;
use Uncanny_Owl\Wp_Test_Kernel\Mutate\Plan;
use Uncanny_Owl\Wp_Test_Kernel\Mutate\Scope;
use Uncanny_Owl\Wp_Test_Kernel\Mutate\Suppressions;
use Uncanny_Owl\Wp_Test_Kernel\Mutate\Tally;

class Mutate_Test extends TestCase {

	/** @var string */
	private $root;

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/mutate-test-' . uniqid();
		foreach ( array( 'src/app/Transports/Model_Context_Protocol/OAuth', 'src/app/Transports/Model_Context_Protocol/Tools/Rest', 'tests/wpunit/app/transports/model-context-protocol/oauth', 'tests/wpunit/app/transports/model-context-protocol/tools' ) as $dir ) {
			mkdir( $this->root . '/' . $dir, 0777, true );
		}
		touch( $this->root . '/src/app/Transports/Model_Context_Protocol/OAuth/Token_Manager.php' );
		touch( $this->root . '/src/app/Transports/Model_Context_Protocol/Tools/Rest/Rest_Request_Scope.php' );
		touch( $this->root . '/tests/wpunit/app/transports/model-context-protocol/oauth/Token_Manager_Test.php' );
		touch( $this->root . '/tests/wpunit/app/transports/model-context-protocol/tools/Tools_Test.php' );
	}

	protected function tearDown(): void {
		exec( 'rm -rf ' . escapeshellarg( $this->root ) );
	}

	/**
	 * @return Scope
	 */
	private function scope() {
		return new Scope(
			$this->root,
			array(
				'paths'    => array( 'src/app/Transports/Model_Context_Protocol' ),
				'critical' => array( 'src/app/Transports/Model_Context_Protocol/OAuth' ),
				'mirrors'  => array( 'src/app' => 'tests/wpunit/app' ),
			)
		);
	}

	public function test_scores_count_uncovered_and_covered_scores_leave_it_out() {
		$tally = Tally::of( 75, 15, 10 );

		$this->assertSame( 75.0, $tally->score() );
		$this->assertSame( 83.3, $tally->covered_score() );
		$this->assertSame( 100.0, ( new Tally() )->score(), 'A file with nothing to mutate is not a failure.' );
	}

	public function test_critical_files_need_90_and_others_need_75_and_80_covered() {
		$scope = $this->scope();

		$this->assertFalse( $scope->meets_target( 'src/app/Transports/Model_Context_Protocol/OAuth/Token_Manager.php', Tally::of( 89, 11, 0 ) ) );
		$this->assertTrue( $scope->meets_target( 'src/app/Transports/Model_Context_Protocol/OAuth/Token_Manager.php', Tally::of( 90, 10, 0 ) ) );
		$this->assertTrue( $scope->meets_target( 'src/app/Transports/Model_Context_Protocol/Tools/Rest/Rest_Request_Scope.php', Tally::of( 75, 15, 10 ) ) );
		$this->assertFalse( $scope->meets_target( 'src/app/Transports/Model_Context_Protocol/Tools/Rest/Rest_Request_Scope.php', Tally::of( 76, 24, 0 ) ), '76% overall but covered under 80.' );
	}

	public function test_floors_only_rise_and_a_run_under_either_score_does_not_hold() {
		$baseline = new Baseline( $this->root . '/baseline.php' );
		$file     = 'src/app/Transports/Model_Context_Protocol/Tools/Rest/Rest_Request_Scope.php';

		$this->assertTrue( $baseline->raise( $file, Tally::of( 60, 30, 10 ) ) );
		$this->assertFalse( $baseline->raise( $file, Tally::of( 50, 40, 10 ) ), 'A lower run never lowers a floor.' );
		$this->assertTrue( $baseline->raise( $file, Tally::of( 70, 20, 10 ) ) );

		$floor = $baseline->floor( $file );
		$this->assertTrue( Baseline::holds( Tally::of( 70, 20, 10 ), $floor ) );
		$this->assertFalse( Baseline::holds( Tally::of( 69, 21, 10 ), $floor ) );

		$baseline->save();
		$this->assertSame( 70, ( new Baseline( $this->root . '/baseline.php' ) )->floor( $file )->caught );
	}

	public function test_a_file_is_judged_by_its_mirror_or_the_nearest_parent_with_tests() {
		$scope = $this->scope();

		$this->assertSame(
			array( 'tests/wpunit/app/transports/model-context-protocol/oauth/Token_Manager_Test.php' ),
			Plan::tests_for( $scope, 'src/app/Transports/Model_Context_Protocol/OAuth/Token_Manager.php' )
		);
		$this->assertSame(
			array( 'tests/wpunit/app/transports/model-context-protocol/tools/Tools_Test.php' ),
			Plan::tests_for( $scope, 'src/app/Transports/Model_Context_Protocol/Tools/Rest/Rest_Request_Scope.php' ),
			'tools/rest has no tests, so tools/ judges it.'
		);
	}

	public function test_suppressions_need_a_reason_directly_above() {
		$file = 'src/app/Transports/Model_Context_Protocol/OAuth/Token_Manager.php';
		file_put_contents(
			$this->root . '/' . $file,
			"<?php\n// Equivalent: the header is read once before this write.\n// @infection-ignore-all\n\$a = 1;\n\n// @infection-ignore-all\n\$b = 2;\n"
		);

		$this->assertSame( array( $file => array( 6 ) ), Suppressions::unexplained( $this->root, array( $file ) ) );
	}
}
