<?php
/**
 * End-to-end tests for bin/lint-integration.php.
 *
 * Each test runs the script as a subprocess against a fixture tree under
 * tests/fixtures/lint/{case}/{free,pro}/ and reads its JSON report. Check ids
 * and rule ids follow .claude/skills/integration-rules in the Automator repo.
 */

use PHPUnit\Framework\TestCase;

class Lint_Integration_Test extends TestCase {

	const BIN      = __DIR__ . '/../../bin/lint-integration.php';
	const FIXTURES = __DIR__ . '/../fixtures/lint';

	/**
	 * @param string $case  Fixture folder.
	 * @param string $slug  Integration slug.
	 * @param string $extra Extra CLI arguments.
	 *
	 * @return array{report:array,exit:int,raw:string}
	 */
	private function run_lint( $case, $slug, $extra = '' ) {
		static $memo = array();
		$key = "$case|$slug|$extra";
		if ( isset( $memo[ $key ] ) ) {
			return $memo[ $key ]; // read-only runs; the --fix test copies its fixture and calls exec() itself
		}
		$free = self::FIXTURES . "/$case/free";
		$pro  = self::FIXTURES . "/$case/pro";
		$cmd  = sprintf(
			'%s %s --plugin-path %s --pro-path %s --slug %s --format=json %s 2>&1',
			escapeshellarg( PHP_BINARY ),
			escapeshellarg( self::BIN ),
			escapeshellarg( $free ),
			escapeshellarg( $pro ),
			escapeshellarg( $slug ),
			$extra
		);
		exec( $cmd, $lines, $exit );
		$raw    = implode( "\n", $lines );
		$report = json_decode( $raw, true );

		$memo[ $key ] = array( 'report' => is_array( $report ) ? $report : array(), 'exit' => $exit, 'raw' => $raw );
		return $memo[ $key ];
	}

	/**
	 * @param array  $report
	 * @param string $check
	 * @param string $file_suffix
	 *
	 * @return array|null
	 */
	private function finding( $report, $check, $file_suffix, $message = '' ) {
		foreach ( $report['findings'] ?? array() as $f ) {
			if ( $f['check'] === $check && substr( $f['file'], -strlen( $file_suffix ) ) === $file_suffix && ( '' === $message || false !== strpos( $f['message'], $message ) ) ) {
				return $f;
			}
		}
		return null;
	}

	private function assert_flagged( $report, $check, $severity, $file_suffix, $message = '' ) {
		$f = $this->finding( $report, $check, $file_suffix, $message );
		$this->assertNotNull( $f, "$check not raised on $file_suffix" . ( $message ? " with '$message'" : '' ) );
		$this->assertSame( $severity, $f['severity'], "$check on $file_suffix has the wrong severity" );
	}

	// ---------------------------------------------------------------- clean

	public function test_clean_fixture_raises_no_flags_and_exits_zero() {
		$r = $this->run_lint( 'clean', 'foo-bookings' );
		$this->assertSame( 0, $r['exit'], $r['raw'] );
		$flags = array_filter( $r['report']['findings'] ?? array(), fn( $f ) => 'report' !== $f['severity'] );
		$this->assertSame( array(), array_values( $flags ), 'clean fixture must raise no P0/P1/P2: ' . json_encode( array_values( $flags ) ) );
	}

	public function test_report_carries_slug_paths_and_summary() {
		$r = $this->run_lint( 'clean', 'foo-bookings' );
		$this->assertSame( 'foo-bookings', $r['report']['slug'] );
		$this->assertStringEndsWith( 'clean/pro/src/integrations/foo-bookings', $r['report']['pro'] );
		$this->assertArrayHasKey( 'P0', $r['report']['summary'] );
	}

	public function test_missing_pro_path_runs_free_only() {
		$free = self::FIXTURES . '/clean/free';
		exec( sprintf( '%s %s --plugin-path %s --pro-path /nonexistent --slug foo-bookings --format=json 2>&1', escapeshellarg( PHP_BINARY ), escapeshellarg( self::BIN ), escapeshellarg( $free ) ), $lines, $exit );
		$report = json_decode( implode( "\n", $lines ), true );
		$this->assertSame( 0, $exit );
		$this->assertNull( $report['pro'] );
	}

	// ---------------------------------------------------------------- dirty: exit code and formats

	public function test_dirty_fixture_exits_one() {
		$r = $this->run_lint( 'dirty', 'bad-plugin' );
		$this->assertSame( 1, $r['exit'] );
		$this->assertGreaterThan( 0, $r['report']['summary']['P0'] + $r['report']['summary']['P1'] );
	}

	public function test_github_format_emits_workflow_commands() {
		$free = self::FIXTURES . '/dirty/free';
		$pro  = self::FIXTURES . '/dirty/pro';
		exec( sprintf( '%s %s --plugin-path %s --pro-path %s --slug bad-plugin --format=github 2>&1', escapeshellarg( PHP_BINARY ), escapeshellarg( self::BIN ), escapeshellarg( $free ), escapeshellarg( $pro ) ), $lines );
		$out = implode( "\n", $lines );
		$this->assertMatchesRegularExpression( '/^::error file=.+,line=\d+,title=R\d+ L-[a-z]+::/m', $out );
		$this->assertMatchesRegularExpression( '/^::warning file=/m', $out );
	}

	// ---------------------------------------------------------------- dirty: triggers

	public function test_trigger_without_definition_is_flagged() {
		$r = $this->run_lint( 'dirty', 'bad-plugin' );
		$this->assert_flagged( $r['report'], 'L-def', 'P1', 'triggers/bad-plugin-thing.php' );
	}

	public function test_screaming_class_name_is_flagged() {
		$r = $this->run_lint( 'dirty', 'bad-plugin' );
		$this->assert_flagged( $r['report'], 'L-naming', 'P2', 'triggers/bad-plugin-thing.php' );
	}

	public function test_login_required_true_is_flagged() {
		$r = $this->run_lint( 'dirty', 'bad-plugin' );
		$this->assert_flagged( $r['report'], 'L-login', 'P1', 'triggers/bad-plugin-thing.php' );
	}

	public function test_login_false_on_anonymous_trigger_is_flagged_p2() {
		$r = $this->run_lint( 'dirty', 'bad-plugin' );
		$this->assert_flagged( $r['report'], 'L-login', 'P2', 'triggers/bad-plugin-anon.php' );
	}

	public function test_user_sentence_on_anonymous_trigger_is_flagged() {
		$r = $this->run_lint( 'dirty', 'bad-plugin' );
		$this->assert_flagged( $r['report'], 'L-sentence', 'P1', 'triggers/bad-plugin-anon.php' );
	}

	public function test_get_item_helpers_is_flagged() {
		$r = $this->run_lint( 'dirty', 'bad-plugin' );
		$this->assert_flagged( $r['report'], 'L-helper', 'P1', 'triggers/bad-plugin-thing.php' );
	}

	public function test_hook_constant_in_definition_is_flagged() {
		$r = $this->run_lint( 'dirty', 'bad-plugin' );
		$this->assert_flagged( $r['report'], 'L-hookconst', 'P1', 'triggers/bad-plugin-anon.php' );
	}

	public function test_hook_constant_in_dispatcher_do_action_is_flagged() {
		$r = $this->run_lint( 'dirty', 'bad-plugin' );
		$this->assert_flagged( $r['report'], 'L-hookconst', 'P1', 'dispatchers/bad-dispatcher.php' );
	}

	public function test_static_dedupe_without_trigger_id_is_flagged() {
		$r = $this->run_lint( 'dirty', 'bad-plugin' );
		$this->assert_flagged( $r['report'], 'L-dedupe', 'P0', 'triggers/bad-plugin-thing.php' );
	}

	public function test_raw_superglobal_is_flagged() {
		$r = $this->run_lint( 'dirty', 'bad-plugin' );
		$this->assert_flagged( $r['report'], 'L-sec', 'P0', 'triggers/bad-plugin-thing.php' );
	}

	public function test_email_lookup_in_trigger_is_reported_not_flagged() {
		$r = $this->run_lint( 'dirty', 'bad-plugin' );
		$this->assert_flagged( $r['report'], 'L-bind', 'report', 'triggers/bad-plugin-thing.php' );
	}

	public function test_cast_of_selection_is_reported() {
		$r = $this->run_lint( 'dirty', 'bad-plugin' );
		$this->assert_flagged( $r['report'], 'L-any', 'report', 'triggers/bad-plugin-anon.php' );
	}

	// ---------------------------------------------------------------- dirty: actions and helpers

	public function test_action_without_requires_user_is_flagged() {
		$r = $this->run_lint( 'dirty', 'bad-plugin' );
		$this->assert_flagged( $r['report'], 'L-setup', 'P1', 'actions/bad-plugin-do.php' );
	}

	public function test_set_action_tokens_is_flagged() {
		$r = $this->run_lint( 'dirty', 'bad-plugin' );
		$this->assert_flagged( $r['report'], 'L-tokens', 'P1', 'actions/bad-plugin-do.php' );
	}

	public function test_any_option_in_action_is_p0() {
		$r = $this->run_lint( 'dirty', 'bad-plugin' );
		$this->assert_flagged( $r['report'], 'L-remote', 'P0', 'actions/bad-plugin-do.php' );
	}

	public function test_public_remote_data_handler_is_flagged() {
		$r = $this->run_lint( 'dirty', 'bad-plugin' );
		$this->assert_flagged( $r['report'], 'L-remote', 'P1', 'helpers/bad-plugin-helpers.php' );
	}

	public function test_maybe_unserialize_is_p0() {
		$r = $this->run_lint( 'dirty', 'bad-plugin' );
		$this->assert_flagged( $r['report'], 'L-sec', 'P0', 'helpers/bad-plugin-helpers.php' );
	}

	public function test_untranslated_log_error_is_flagged() {
		$r = $this->run_lint( 'dirty', 'bad-plugin' );
		$this->assert_flagged( $r['report'], 'L-i18n', 'P2', 'actions/bad-plugin-do.php' );
	}

	public function test_upstream_line_citation_is_flagged() {
		$r = $this->run_lint( 'dirty', 'bad-plugin' );
		$this->assert_flagged( $r['report'], 'L-hygiene', 'P2', 'helpers/bad-plugin-helpers.php' );
	}

	// ---------------------------------------------------------------- dirty: integration class

	public function test_is_plugin_active_is_flagged() {
		$r = $this->run_lint( 'dirty', 'bad-plugin' );
		$this->assert_flagged( $r['report'], 'L-active', 'P1', 'bad-plugin-integration.php' );
	}

	public function test_missing_manifest_setter_is_flagged() {
		$r = $this->run_lint( 'dirty', 'bad-plugin' );
		$f = $this->finding( $r['report'], 'L-manifest', 'free/src/integrations/bad-plugin' );
		$this->assertNotNull( $f, 'missing set_developer_name() in Free must be flagged' );
		$this->assertSame( 'P1', $f['severity'] );
	}

	public function test_open_source_distribution_is_flagged() {
		$r = $this->run_lint( 'dirty', 'bad-plugin' );
		$this->assert_flagged( $r['report'], 'L-manifest', 'P1', 'free/src/integrations/bad-plugin/bad-plugin-integration.php' );
	}

	public function test_handlers_folder_is_flagged() {
		$r = $this->run_lint( 'dirty', 'bad-plugin' );
		$this->assert_flagged( $r['report'], 'L-dispatch', 'P1', 'bad-plugin/handlers' );
	}

	public function test_dispatcher_without_boot_is_flagged() {
		$r = $this->run_lint( 'dirty', 'bad-plugin' );
		$this->assert_flagged( $r['report'], 'L-dispatch', 'P1', 'dispatchers/bad-dispatcher.php' );
	}

	// ---------------------------------------------------------------- dirty: Pro

	public function test_pro_helper_that_extends_free_is_flagged() {
		$r = $this->run_lint( 'dirty', 'bad-plugin' );
		$this->assert_flagged( $r['report'], 'L-pro', 'P1', 'helpers/bad-plugin-pro-helpers.php', 'extend Abstract_Pro_Helpers' );
		$this->assert_flagged( $r['report'], 'L-pro', 'P1', 'helpers/bad-plugin-pro-helpers.php', 'guard' );
	}

	public function test_rule_named_in_a_comment_is_not_a_violation() {
		$r = $this->run_lint( 'clean', 'foo-bookings' );
		$this->assertNull( $this->finding( $r['report'], 'L-tokens', 'foo-cancel-booking.php' ), 'a docblock that says "never set_action_tokens()" is not a call' );
		$this->assertNull( $this->finding( $r['report'], 'L-active', 'foo-bookings-integration.php' ) );
	}

	public function test_missing_free_segment_wrapper_is_p0() {
		$r = $this->run_lint( 'dirty', 'bad-plugin' );
		$hits = array_filter( $r['report']['findings'], fn( $f ) => 'L-pro' === $f['check'] && 'P0' === $f['severity'] && false !== strpos( $f['message'], 'segment' ) );
		$this->assertNotEmpty( $hits, 'a Free segment without a Pro wrapper must be P0' );
	}

	public function test_condition_outside_conditions_namespace_is_flagged() {
		$r = $this->run_lint( 'dirty', 'bad-plugin' );
		$this->assert_flagged( $r['report'], 'L-ns', 'P1', 'conditions/bad-plugin-cond.php' );
	}

	public function test_self_instantiated_condition_is_flagged() {
		$r = $this->run_lint( 'dirty', 'bad-plugin' );
		$hits = array_filter( $r['report']['findings'], fn( $f ) => 'L-ns' === $f['check'] && false !== strpos( $f['message'], 'self-instantiat' ) );
		$this->assertNotEmpty( $hits );
	}

	public function test_manifest_differs_between_free_and_pro_is_flagged() {
		$r = $this->run_lint( 'dirty', 'bad-plugin' );
		$hits = array_filter( $r['report']['findings'], fn( $f ) => 'L-manifest' === $f['check'] && false !== strpos( $f['message'], 'differs' ) );
		$this->assertNotEmpty( $hits, 'set_developer_name differing between Free and Pro must be flagged' );
	}

	public function test_missing_test_folder_is_flagged() {
		$r = $this->run_lint( 'dirty', 'bad-plugin' );
		$this->assert_flagged( $r['report'], 'L-tests', 'P1', 'tests/wpunit/integrations/bad-plugin' );
	}

	// ---------------------------------------------------------------- --fix

	public function test_fix_removes_login_true_and_inlines_hook_constant() {
		$tmp = sys_get_temp_dir() . '/lint-fix-' . uniqid();
		$this->copy_tree( self::FIXTURES . '/dirty', $tmp );
		exec( sprintf( '%s %s --plugin-path %s --pro-path %s --slug bad-plugin --format=json --fix 2>&1', escapeshellarg( PHP_BINARY ), escapeshellarg( self::BIN ), escapeshellarg( "$tmp/free" ), escapeshellarg( "$tmp/pro" ) ), $lines );
		$thing = file_get_contents( "$tmp/free/src/integrations/bad-plugin/triggers/bad-plugin-thing.php" );
		$anon  = file_get_contents( "$tmp/free/src/integrations/bad-plugin/triggers/bad-plugin-anon.php" );
		$disp  = file_get_contents( "$tmp/free/src/integrations/bad-plugin/dispatchers/bad-dispatcher.php" );
		$this->assertStringNotContainsString( 'set_is_login_required( true )', $thing );
		$this->assertStringNotContainsString( 'set_is_login_required( false )', $anon, 'inert flag removed from the anonymous trigger' );
		$this->assertStringContainsString( "->hook( 'automator_bad_thing', 10, 1 )", $anon );
		$this->assertStringContainsString( "do_action( 'automator_bad_thing'", $disp );
		$this->remove_tree( $tmp );
	}

	// ---------------------------------------------------------------- --changed gating

	public function test_changed_mode_blocks_only_findings_on_changed_lines_of_an_existing_integration() {
		$tmp = sys_get_temp_dir() . '/lint-changed-' . uniqid();
		$this->copy_tree( self::FIXTURES . '/dirty', $tmp );
		$repo = "$tmp/free";
		exec( "cd " . escapeshellarg( $repo ) . " && git init -q && git add -A && git -c user.email=t@t -c user.name=t commit -q -m base" );
		// Edit one line of an existing, already-dirty file: add a second raw superglobal read.
		$file = "$repo/src/integrations/bad-plugin/actions/bad-plugin-do.php";
		file_put_contents( $file, str_replace( "\$this->add_log_error( 'Failed' );", "\$x = \$_GET['y'];\n\t\t\$this->add_log_error( 'Failed' );", file_get_contents( $file ) ) );
		exec( sprintf( '%s %s --plugin-path %s --pro-path %s --changed=HEAD --format=json 2>&1', escapeshellarg( PHP_BINARY ), escapeshellarg( self::BIN ), escapeshellarg( $repo ), escapeshellarg( "$tmp/pro" ) ), $lines, $exit );
		$report = json_decode( implode( "\n", $lines ), true );
		$this->assertSame( 1, $exit, 'the new $_GET read on a changed line must block' );
		$blocking = array_values( array_filter( $report['findings'], fn( $f ) => empty( $f['preexisting'] ) && in_array( $f['severity'], array( 'P0', 'P1' ), true ) ) );
		$this->assertCount( 1, $blocking, 'only the changed line blocks: ' . json_encode( $blocking ) );
		$this->assertSame( 'L-sec', $blocking[0]['check'] );
		$this->assertGreaterThan( 10, $report['summary']['preexisting'], 'the rest of the dirty fixture is reported as pre-existing' );
		$this->remove_tree( $tmp );
	}

	public function test_changed_mode_gates_a_new_integration_fully() {
		$tmp = sys_get_temp_dir() . '/lint-new-' . uniqid();
		$this->copy_tree( self::FIXTURES . '/dirty', $tmp );
		$repo = "$tmp/free";
		// Base commit without the integration; then add the whole folder.
		exec( "cd " . escapeshellarg( $repo ) . " && git init -q && mkdir -p src/integrations/keep && touch src/integrations/keep/.gitkeep && git add src/integrations/keep && git -c user.email=t@t -c user.name=t commit -q -m base" );
		exec( sprintf( '%s %s --plugin-path %s --pro-path %s --changed=HEAD --format=json 2>&1', escapeshellarg( PHP_BINARY ), escapeshellarg( self::BIN ), escapeshellarg( $repo ), escapeshellarg( "$tmp/pro" ) ), $lines, $exit );
		$report = json_decode( implode( "\n", $lines ), true );
		$this->assertSame( 1, $exit );
		$this->assertSame( 0, $report['summary']['preexisting'], 'a new integration has nothing pre-existing' );
		$this->assertGreaterThan( 5, $report['summary']['P0'] + $report['summary']['P1'] );
		$this->remove_tree( $tmp );
	}

	private function copy_tree( $src, $dst ) {
		mkdir( $dst, 0777, true );
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $src, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST ) as $item ) {
			$target = $dst . '/' . substr( $item->getPathname(), strlen( $src ) + 1 );
			$item->isDir() ? mkdir( $target, 0777, true ) : copy( $item->getPathname(), $target );
		}
	}

	private function remove_tree( $dir ) {
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
		rmdir( $dir );
	}
}
