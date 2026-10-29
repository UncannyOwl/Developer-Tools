<?php
/**
 * Tests for bin/plugin-check.php: the gate around WordPress.org's Plugin Check.
 *
 * Running Plugin Check needs WordPress, so these tests feed saved results (--results) through the
 * gate as a subprocess, the way tests/lint runs the lint, and call the pure functions directly.
 * Fixtures: tests/fixtures/plugin-check/plugin/.plugin-check/{config,baseline}.json and
 * tests/fixtures/plugin-check/results/*.json (raw `wp plugin check --format=strict-json` output).
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../bin/plugin-check/functions.php';

class Plugin_Check_Test extends TestCase {

	const BIN      = __DIR__ . '/../../bin/plugin-check.php';
	const FIXTURES = __DIR__ . '/../fixtures/plugin-check';
	const ROOT     = '/tmp/stage/foo-plugin';

	/**
	 * @param string $results Fixture under results/, without .json.
	 * @param string $extra   Extra CLI arguments.
	 * @param string $plugin  Plugin folder holding .plugin-check/.
	 *
	 * @return array{report:array,exit:int,raw:string}
	 */
	private function gate( $results, $extra = '', $plugin = '' ) {
		$cmd = sprintf(
			'%s %s --plugin-path %s --results %s --dir %s --format=json %s 2>&1',
			escapeshellarg( PHP_BINARY ),
			escapeshellarg( self::BIN ),
			escapeshellarg( '' !== $plugin ? $plugin : self::FIXTURES . '/plugin' ),
			escapeshellarg( self::FIXTURES . "/results/$results.json" ),
			escapeshellarg( self::ROOT ),
			$extra
		);
		exec( $cmd, $lines, $exit );
		$raw    = implode( "\n", $lines );
		$report = json_decode( $raw, true );
		return array(
			'report' => is_array( $report ) ? $report : array(),
			'exit'   => $exit,
			'raw'    => $raw,
		);
	}

	/**
	 * @return string A temp copy of the fixture plugin folder.
	 */
	private function copy_plugin() {
		$dir = sys_get_temp_dir() . '/pc-test-' . bin2hex( random_bytes( 4 ) );
		mkdir( $dir . '/.plugin-check', 0700, true );
		foreach ( array( 'config.json', 'baseline.json' ) as $f ) {
			copy( self::FIXTURES . "/plugin/.plugin-check/$f", "$dir/.plugin-check/$f" );
		}
		return $dir;
	}

	// ---------------------------------------------------------------- the gate

	public function test_baselined_errors_pass() {
		$r = $this->gate( 'clean' );
		$this->assertSame( 0, $r['exit'], $r['raw'] );
		$this->assertSame( 0, $r['report']['summary']['new_errors'] );
		$this->assertSame( 3, $r['report']['summary']['baselined'] );
		$this->assertSame( 1, $r['report']['summary']['warnings'] );
		$this->assertSame( 2, $r['report']['summary']['ignored'] );
	}

	public function test_moved_lines_keep_their_baseline_entry() {
		$r = $this->gate( 'moved' );
		$this->assertSame( 0, $r['exit'], $r['raw'] );
		$this->assertSame( 3, $r['report']['summary']['baselined'] );
	}

	public function test_new_errors_fail_and_are_listed() {
		$r = $this->gate( 'new-errors' );
		$this->assertSame( 1, $r['exit'], $r['raw'] );
		$codes = array_column( $r['report']['new'], 'code' );
		$this->assertContains( 'WordPress.WP.AlternativeFunctions.curl_curl_setopt', $codes );
		$this->assertContains( 'stable_tag_mismatch', $codes );
		$this->assertContains( 'wp_function_not_compatible_with_requires_wp', $codes, 'array_is_list is not polyfilled, so the str_* ignore must not cover it' );
	}

	public function test_an_error_beyond_the_baseline_count_counts_once() {
		$r = $this->gate( 'new-errors' );
		// 2 allowed, 3 found in src/a.php: one new; plus curl, array_is_list and the stable tag.
		$this->assertSame( 4, $r['report']['summary']['new_errors'] );
		$escapes = array_values( array_filter( $r['report']['new'], fn( $f ) => 'src/a.php' === $f['path'] ) );
		$this->assertCount( 3, $escapes, 'every instance of an over-budget fingerprint is shown' );
		$this->assertSame( 2, $escapes[0]['baseline_allows'] );
	}

	public function test_warnings_and_ignored_codes_never_fail() {
		$r = $this->gate( 'clean' );
		$statuses = array_count_values( array_column( $r['report']['findings'], 'status' ) );
		$this->assertSame( 1, $statuses['warning'] );
		$this->assertSame( 2, $statuses['ignored'] );
		$ignored = array_values( array_filter( $r['report']['findings'], fn( $f ) => 'ignored' === $f['status'] ) );
		$this->assertNotEmpty( $ignored[0]['reason'] );
	}

	public function test_fixed_errors_are_reported_stale_and_strict_fails() {
		$r = $this->gate( 'fixed' );
		$this->assertSame( 0, $r['exit'], $r['raw'] );
		$this->assertSame( 1, $r['report']['summary']['stale'] );
		$this->assertSame( 1, $r['report']['stale'][0]['found'] );
		$this->assertSame( 2, $r['report']['stale'][0]['allowed'] );

		$strict = $this->gate( 'fixed', '--strict' );
		$this->assertSame( 1, $strict['exit'] );
	}

	public function test_root_is_stripped_from_paths_and_messages() {
		$r     = $this->gate( 'new-errors' );
		$paths = array_unique( array_column( $r['report']['findings'], 'path' ) );
		$this->assertContains( 'logs/.htaccess', $paths );
		$this->assertContains( 'readme.txt', $paths );
		$tag = array_values( array_filter( $r['report']['new'], fn( $f ) => 'stable_tag_mismatch' === $f['code'] ) );
		$this->assertStringNotContainsString( self::ROOT, $tag[0]['message'] );
		$this->assertStringContainsString( 'Read readme.txt.', $tag[0]['message'] );
	}

	// ---------------------------------------------------------------- formats

	public function test_github_format_annotates_new_errors_only() {
		$cmd = sprintf(
			'%s %s --plugin-path %s --results %s --dir %s --format=github 2>&1',
			escapeshellarg( PHP_BINARY ),
			escapeshellarg( self::BIN ),
			escapeshellarg( self::FIXTURES . '/plugin' ),
			escapeshellarg( self::FIXTURES . '/results/new-errors.json' ),
			escapeshellarg( self::ROOT )
		);
		exec( $cmd, $lines, $exit );
		$out = implode( "\n", $lines );
		$this->assertSame( 1, $exit );
		$this->assertMatchesRegularExpression( '/^::error file=src\/d\.php,line=3,col=1,title=Plugin Check WordPress\.WP\.AlternativeFunctions\.curl_curl_setopt::Using cURL/m', $out );
		$this->assertSame( 6, preg_match_all( '/^::error file=/m', $out ), 'four new fingerprints, one of them three instances' );
		$this->assertMatchesRegularExpression( '/^::error title=Plugin Check::foo-plugin: 4 new errors/m', $out );
	}

	public function test_text_format_lists_new_errors_and_codes() {
		$cmd = sprintf(
			'%s %s --plugin-path %s --results %s --dir %s 2>&1',
			escapeshellarg( PHP_BINARY ),
			escapeshellarg( self::BIN ),
			escapeshellarg( self::FIXTURES . '/plugin' ),
			escapeshellarg( self::FIXTURES . '/results/new-errors.json' ),
			escapeshellarg( self::ROOT )
		);
		exec( $cmd, $lines );
		$out = implode( "\n", $lines );
		$this->assertMatchesRegularExpression( '/^NEW   src\/d\.php:3  \[WordPress\.WP\.AlternativeFunctions\.curl_curl_setopt\]/m', $out );
		$this->assertStringContainsString( '4 new errors · 3 baselined · 1 warnings · 2 ignored', $out );
	}

	public function test_summary_file_gets_markdown() {
		$md = tempnam( sys_get_temp_dir(), 'pc-md' );
		$this->gate( 'new-errors', '--summary-file ' . escapeshellarg( $md ) );
		$body = file_get_contents( $md );
		unlink( $md );
		$this->assertStringContainsString( '## Plugin Check', $body );
		$this->assertStringContainsString( '**Failed.**', $body );
		$this->assertStringContainsString( '| `src/d.php:3` |', $body );
	}

	// ---------------------------------------------------------------- baseline file

	public function test_update_baseline_then_the_same_results_pass() {
		$plugin = $this->copy_plugin();
		$cmd    = sprintf(
			'%s %s --plugin-path %s --results %s --dir %s --update-baseline 2>&1',
			escapeshellarg( PHP_BINARY ),
			escapeshellarg( self::BIN ),
			escapeshellarg( $plugin ),
			escapeshellarg( self::FIXTURES . '/results/new-errors.json' ),
			escapeshellarg( self::ROOT )
		);
		exec( $cmd, $lines, $exit );
		$this->assertSame( 0, $exit, implode( "\n", $lines ) );
		$baseline = json_decode( file_get_contents( "$plugin/.plugin-check/baseline.json" ), true );
		$this->assertSame( 7, $baseline['total'], 'ignored errors stay out of the baseline' );
		$this->assertSame( 3, $baseline['errors']['src/a.php']['WordPress.Security.EscapeOutput.OutputNotEscaped']["All output should be run through an escaping function, found '\$x'."] );
		$this->assertSame( array_keys( $baseline['errors'] ), array_values( array_unique( array_merge( array( 'readme.txt' ), array_keys( $baseline['errors'] ) ) ) ), 'paths are sorted' );

		$r = $this->gate( 'new-errors', '', $plugin );
		exec( 'rm -rf ' . escapeshellarg( $plugin ) );
		$this->assertSame( 0, $r['exit'], $r['raw'] );
		$this->assertSame( 0, $r['report']['summary']['new_errors'] );
	}

	public function test_without_a_baseline_every_error_is_new() {
		$plugin = $this->copy_plugin();
		unlink( "$plugin/.plugin-check/baseline.json" );
		$r = $this->gate( 'clean', '', $plugin );
		exec( 'rm -rf ' . escapeshellarg( $plugin ) );
		$this->assertSame( 1, $r['exit'] );
		$this->assertSame( 3, $r['report']['summary']['new_errors'] );
		$this->assertNull( $r['report']['baseline'] );
	}

	// ---------------------------------------------------------------- usage errors

	public function test_an_ignore_without_a_reason_is_a_usage_error() {
		$plugin = $this->copy_plugin();
		file_put_contents( "$plugin/.plugin-check/config.json", json_encode( array( 'ignore' => array( array( 'code' => 'hidden_files' ) ) ) ) );
		$r = $this->gate( 'clean', '', $plugin );
		exec( 'rm -rf ' . escapeshellarg( $plugin ) );
		$this->assertSame( 2, $r['exit'] );
		$this->assertStringContainsString( 'needs a reason', $r['raw'] );
	}

	public function test_no_shipped_set_is_a_usage_error() {
		exec( sprintf( '%s %s --plugin-path %s 2>&1', escapeshellarg( PHP_BINARY ), escapeshellarg( self::BIN ), escapeshellarg( self::FIXTURES . '/plugin' ) ), $lines, $exit );
		$this->assertSame( 2, $exit );
		$this->assertStringContainsString( 'runs on what ships', implode( "\n", $lines ) );
	}

	// ---------------------------------------------------------------- pure functions

	public function test_rule_needs_a_code_or_a_path() {
		$row = array( 'path' => 'a.php', 'code' => 'x', 'message' => 'm', 'type' => 'ERROR' );
		$this->assertFalse( pc_rule_matches( array( 'message' => '*' ), $row ) );
		$this->assertTrue( pc_rule_matches( array( 'path' => '*.php' ), $row ) );
		$this->assertFalse( pc_rule_matches( array( 'code' => 'x', 'type' => 'WARNING' ), $row ) );
		$this->assertTrue( pc_rule_matches( array( 'code' => 'x', 'message' => array( 'n*', 'm' ) ), $row ), 'a list matches when one glob does' );
		$this->assertFalse( pc_rule_matches( array( 'code' => 'x', 'message' => array( 'n*', 'o' ) ), $row ) );
	}

	public function test_a_run_without_results_prints_success_not_json() {
		$this->assertSame( array(), pc_decode_output( "Success: Checks complete. No errors found.\n" ) );
		$this->assertSame( array(), pc_decode_output( '[]' ) );
		$this->assertNull( pc_decode_output( 'PHP Fatal error: …' ) );
	}

	public function test_partition_spreads_slow_checks_over_jobs() {
		$groups = pc_partition_checks( array( 'file_type', 'late_escaping', 'plugin_review_phpcs', 'prefixing', 'i18n_usage', 'plugin_readme', 'enqueued_scripts_scope' ), 3 );
		$this->assertCount( 3, $groups );
		$this->assertCount( 7, array_merge( ...$groups ) );
		foreach ( $groups as $g ) {
			$this->assertLessThanOrEqual( 1, count( array_intersect( $g, array( 'late_escaping', 'plugin_review_phpcs', 'prefixing' ) ) ), 'two of the slowest checks share a job: ' . json_encode( $groups ) );
		}
		$this->assertEmpty( array_diff( array( 'file_type', 'plugin_readme', 'enqueued_scripts_scope' ), end( $groups ) ), 'checks without a known cost (runtime ones too) go to the last job' );
	}

	public function test_partition_never_makes_more_jobs_than_heavy_checks_plus_one() {
		$this->assertSame( array( array( 'file_type' ) ), pc_partition_checks( array( 'file_type' ), 4 ) );
		$this->assertCount( 2, pc_partition_checks( array( 'file_type', 'prefixing' ), 4 ) );
	}
}
