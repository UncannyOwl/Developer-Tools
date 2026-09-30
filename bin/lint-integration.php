#!/usr/bin/env php
<?php
// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput, WordPress.PHP.DiscouragedPHPFunctions, WordPress.PHP.NoSilencedErrors -- CLI tool that runs outside WordPress.
/**
 * Lint an Uncanny Automator plugin integration (Free and Pro) against the house rules.
 *
 * Static analysis only: no WordPress bootstrap, so it runs anywhere PHP runs and in CI.
 * Every finding carries its rule id from .claude/skills/integration-rules in the Automator
 * repo and a check id (L-…). P0 and P1 findings fail the run; P2 and reports do not.
 *
 * Usage:
 *   php bin/lint-integration.php --plugin-path /path/to/free --pro-path /path/to/pro --slug foo-bookings
 *   php bin/lint-integration.php --plugin-path . --pro-path ../uncanny-automator-pro --changed
 *   php bin/lint-integration.php --plugin-path . --pro-path ../uncanny-automator-pro --all
 *
 * Options:
 *   --plugin-path PATH   Free plugin root (default: current directory)
 *   --pro-path PATH      Pro plugin root; skipped when the folder is missing
 *   --slug SLUG          Integration to lint; repeat or comma-separate for several
 *   --changed[=REF]      Integrations changed since the merge base with REF (default origin/pre-release),
 *                        committed or not. A new integration is gated on everything; an existing one only
 *                        on its changed lines, the rest is reported as pre-existing. Pro is never gated here.
 *   --gate all|changed   With --changed: gate every finding (all) or only changed lines (default)
 *   --all                Every folder under src/integrations/
 *   --scope-doc PATH     Scope doc for the L-scope comparison (default: found under scope-docs/)
 *   --format FORMAT      text (default) | json | github
 *   --fix                Apply the mechanical fixes (L-login, L-hookconst) before reporting
 *   --strict             Also fail on P2
 *
 * Exit codes: 0 clean, 1 findings that block, 2 usage error.
 */

require_once __DIR__ . '/lint/class-lint-context.php';
require_once __DIR__ . '/lint/class-lint-finding.php';
require_once __DIR__ . '/lint/checks.php';
require_once __DIR__ . '/lint/fixes.php';
require_once __DIR__ . '/lint/report.php';

/**
 * @param string[] $argv
 *
 * @return array
 */
function lint_parse_args( array $argv ) {
	$opts = array(
		'plugin-path' => getcwd(),
		'pro-path'    => '',
		'slugs'       => array(),
		'changed'     => null,
		'all'         => false,
		'scope-doc'   => '',
		'format'      => 'text',
		'gate'        => 'changed',
		'fix'         => false,
		'strict'      => false,
		'help'        => false,
	);
	$n    = count( $argv );
	for ( $i = 1; $i < $n; $i++ ) {
		$a = $argv[ $i ];
		if ( preg_match( '~^--(plugin-path|pro-path|slug|scope-doc|format|gate)(?:=(.*))?$~', $a, $m ) ) {
			$v = isset( $m[2] ) ? $m[2] : ( isset( $argv[ $i + 1 ] ) ? $argv[ ++$i ] : '' );
			if ( 'slug' === $m[1] ) {
				$opts['slugs'] = array_merge( $opts['slugs'], array_filter( array_map( 'trim', explode( ',', $v ) ) ) );
			} else {
				$opts[ $m[1] ] = $v;
			}
		} elseif ( preg_match( '~^--changed(?:=(.*))?$~', $a, $m ) ) {
			$opts['changed'] = isset( $m[1] ) && '' !== $m[1] ? $m[1] : 'origin/pre-release';
		} elseif ( in_array( $a, array( '--all', '--fix', '--strict', '--help', '-h' ), true ) ) {
			$opts[ ltrim( 'h' === ltrim( $a, '-' ) ? 'help' : $a, '-' ) ] = true;
		} elseif ( '' !== $a && '-' !== $a[0] ) {
			$opts['slugs'][] = $a; // `composer lint:integration foo` appends the slug bare.
		} else {
			fwrite( STDERR, "unknown option $a\n" );
			exit( 2 );
		}
	}
	return $opts;
}

/**
 * @param string $repo
 * @param string $args
 *
 * @return string[] Output lines of a git command in the repo, or an empty array.
 */
function lint_git( $repo, $args ) {
	exec( 'git -C ' . escapeshellarg( $repo ) . ' ' . $args . ' 2>/dev/null', $lines, $code );
	return 0 === $code ? $lines : array();
}

/**
 * The commit a PR is compared against: the merge base of the ref and HEAD, so commits the base
 * gained since the branch was cut are not counted as changes.
 *
 * @param string $repo
 * @param string $ref
 *
 * @return string
 */
function lint_merge_base( $repo, $ref ) {
	$out = lint_git( $repo, 'merge-base ' . escapeshellarg( $ref ) . ' HEAD' );
	return empty( $out ) ? $ref : trim( $out[0] );
}

/**
 * Files changed since the merge base, committed or not, plus untracked files.
 *
 * @param string $repo
 * @param string $base
 *
 * @return string[] Repo-relative paths.
 */
function lint_changed_files( $repo, $base ) {
	$paths     = 'src/integrations tests/wpunit/integrations scope-docs';
	$tracked   = lint_git( $repo, 'diff --name-only ' . escapeshellarg( $base ) . ' -- ' . $paths );
	$untracked = lint_git( $repo, 'ls-files --others --exclude-standard -- ' . $paths );
	return array_unique( array_merge( $tracked, $untracked ) );
}

/**
 * @param string[] $files Repo-relative paths.
 *
 * @return string[] Integration slugs those files belong to.
 */
function lint_slugs_from_files( array $files ) {
	$slugs = array();
	foreach ( $files as $l ) {
		// The integration's code, its tests, or its scope doc: each changes what the lint compares.
		if ( preg_match( '~^src/integrations/([^/]+)/~', $l, $m ) || preg_match( '~^tests/wpunit/integrations/([^/]+)/~', $l, $m ) || preg_match( '~^scope-docs/(?:.+/)?([a-z0-9-]+)-scope\.md$~', $l, $m ) ) {
			$slugs[ $m[1] ] = true;
		}
	}
	return array_keys( $slugs );
}

/**
 * Changed line ranges per file for one integration: hunks of the diff against the merge base,
 * and whole files for untracked ones.
 *
 * @param string $repo
 * @param string $base
 * @param string $slug
 *
 * @return array<string,array<int,array{0:int,1:int}>> Repo-relative path => [start, end] ranges.
 */
function lint_changed_ranges( $repo, $base, $slug ) {
	$ranges = array();
	$file   = '';
	foreach ( lint_git( $repo, 'diff -U0 ' . escapeshellarg( $base ) . ' -- ' . escapeshellarg( 'src/integrations/' . $slug ) ) as $l ) {
		if ( preg_match( '~^\+\+\+ b/(.+)$~', $l, $m ) ) {
			$file = $m[1];
		} elseif ( '' !== $file && preg_match( '~^@@ -\d+(?:,\d+)? \+(\d+)(?:,(\d+))? @@~', $l, $m ) ) {
			$start = (int) $m[1];
			$count = isset( $m[2] ) ? (int) $m[2] : 1;
			if ( $count > 0 ) {
				$ranges[ $file ][] = array( $start, $start + $count - 1 );
			}
		}
	}
	foreach ( lint_git( $repo, 'ls-files --others --exclude-standard -- ' . escapeshellarg( 'src/integrations/' . $slug ) ) as $l ) {
		$ranges[ $l ][] = array( 0, PHP_INT_MAX );
	}
	return $ranges;
}

/**
 * @param string $repo
 * @param string $base
 * @param string $slug
 *
 * @return bool Whether the integration folder is absent from the merge base (a new integration).
 */
/**
 * Every {slug}-scope.md under scope-docs/, at any depth (active/plugins/, assigned/, shipped/ …), sorted.
 *
 * @param string $repo Free repo root.
 * @param string $slug Integration slug.
 *
 * @return string[]
 */
function lint_find_scope_docs( $repo, $slug ) {
	$root = $repo . '/scope-docs';
	if ( ! is_dir( $root ) ) {
		return array();
	}
	$docs = array();
	$it   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $file ) {
		if ( $file->getFilename() === $slug . '-scope.md' ) {
			$docs[] = $file->getPathname();
		}
	}
	sort( $docs );
	return $docs;
}

function lint_is_new_integration( $repo, $base, $slug ) {
	exec( 'git -C ' . escapeshellarg( $repo ) . ' cat-file -e ' . escapeshellarg( $base . ':src/integrations/' . $slug ) . ' 2>/dev/null', $o, $code );
	return 0 !== $code;
}

/**
 * Mark findings outside the changed lines of an existing integration as pre-existing, so a PR is
 * gated on what it touched and a new integration is gated on everything.
 *
 * @param Lint_Finding[] $findings
 * @param Lint_Context   $ctx
 * @param array          $ranges_by_repo Repo root => ranges from lint_changed_ranges(). A repo that is not a git checkout has none, so its findings never gate.
 *
 * @return Lint_Finding[]
 */
function lint_mark_preexisting( array $findings, Lint_Context $ctx, array $ranges_by_repo ) {
	foreach ( $findings as $f ) {
		$ranges = isset( $ranges_by_repo[ $ctx->repo_of( $f->file ) ] ) ? $ranges_by_repo[ $ctx->repo_of( $f->file ) ] : array();
		$hit    = false;
		foreach ( isset( $ranges[ $ctx->rel( $f->file ) ] ) ? $ranges[ $ctx->rel( $f->file ) ] : array() as $r ) {
			// A line finding blocks when its line changed; a file-level finding only when the file is new.
			if ( 0 === $f->line ? 0 === $r[0] : ( $f->line >= $r[0] && $f->line <= $r[1] ) ) {
				$hit = true;
				break;
			}
		}
		$f->preexisting = ! $hit;
	}
	return $findings;
}

$opts = lint_parse_args( $argv );

if ( $opts['help'] ) {
	echo preg_replace( '~^/\*\*|\*/$|^ \* ?~m', '', file_get_contents( __FILE__, false, null, 0, 1800 ) );
	exit( 0 );
}

$free_repo = realpath( $opts['plugin-path'] );
if ( false === $free_repo || ! is_dir( $free_repo . '/src/integrations' ) ) {
	fwrite( STDERR, "no src/integrations under {$opts['plugin-path']}\n" );
	exit( 2 );
}
$pro_repo = '' !== $opts['pro-path'] ? realpath( $opts['pro-path'] ) : false;
$pro_repo = false === $pro_repo ? null : $pro_repo;

$slugs = $opts['slugs'];
$bases = array(); // repo root => merge base, for every repo that is a git checkout
if ( null !== $opts['changed'] ) {
	foreach ( array_filter( array( $free_repo, $pro_repo ) ) as $repo ) {
		if ( empty( lint_git( $repo, 'rev-parse --is-inside-work-tree' ) ) ) {
			continue;
		}
		$bases[ $repo ] = lint_merge_base( $repo, $opts['changed'] );
		$slugs          = array_merge( $slugs, lint_slugs_from_files( lint_changed_files( $repo, $bases[ $repo ] ) ) );
	}
}
if ( $opts['all'] ) {
	foreach ( glob( $free_repo . '/src/integrations/*', GLOB_ONLYDIR ) as $d ) {
		$slugs[] = basename( $d );
	}
}
$slugs = array_values( array_unique( $slugs ) );
if ( empty( $slugs ) ) {
	if ( null !== $opts['changed'] ) {
		echo 'json' === $opts['format'] ? "{\"reports\":[],\"exit\":0}\n" : "no integration changed\n";
		exit( 0 );
	}
	fwrite( STDERR, "give --slug, --changed or --all\n" );
	exit( 2 );
}

$reports = array();
$exit    = 0;
foreach ( $slugs as $slug ) {
	if ( ! is_dir( $free_repo . '/src/integrations/' . $slug ) ) {
		if ( null !== $opts['changed'] ) {
			fwrite( STDERR, "$slug has no Free integration folder; Pro-only integrations are not linted yet\n" );
			continue;
		}
		fwrite( STDERR, "no Free integration folder for $slug\n" );
		$exit = 2;
		continue;
	}
	$doc  = $opts['scope-doc'];
	$docs = array();
	if ( '' === $doc ) {
		$docs = lint_find_scope_docs( $free_repo, $slug );
		$doc  = ! empty( $docs ) ? $docs[0] : '';
	}
	$ctx      = new Lint_Context( $slug, $free_repo, $pro_repo, $doc );
	$findings = array();
	foreach ( lint_all_checks() as $check ) {
		$findings = array_merge( $findings, call_user_func( $check, $ctx ) );
	}
	// The doc's presence is judged here, where the run knows whether the integration is new (R0).
	if ( count( $docs ) > 1 ) {
		$findings[] = new Lint_Finding( 'L-scope', 'R0', 'P1', $docs[0], 0, 'two scope docs for ' . $slug . ' (' . implode( ', ', array_map( 'basename', array_map( 'dirname', $docs ) ) ) . '); the first is used, keep one' );
	}
	if ( '' === $doc ) {
		$is_new     = isset( $bases[ $free_repo ] ) && lint_is_new_integration( $free_repo, $bases[ $free_repo ], $slug );
		$findings[] = new Lint_Finding( 'L-scope', 'R0', $is_new ? 'P1' : 'report', $ctx->free, 0, $is_new ? 'new integration without a scope doc under scope-docs/: the doc is the build contract (R0)' : 'no scope doc under scope-docs/ for ' . $slug );
	}
	$fixed = 0;
	if ( $opts['fix'] ) {
		$fixed = lint_apply_fixes( $ctx, $findings );
		if ( $fixed > 0 ) {
			$findings = array();
			foreach ( lint_all_checks() as $check ) {
				$findings = array_merge( $findings, call_user_func( $check, $ctx ) );
			}
		}
	}
	if ( isset( $bases[ $free_repo ] ) && 'all' !== $opts['gate'] && ! lint_is_new_integration( $free_repo, $bases[ $free_repo ], $slug ) ) {
		$ranges = array();
		foreach ( $bases as $repo => $repo_base ) {
			$ranges[ $repo ] = lint_changed_ranges( $repo, $repo_base, $slug );
		}
		$findings = lint_mark_preexisting( $findings, $ctx, $ranges );
	}
	$findings  = lint_sort_findings( $findings );
	$report    = lint_report_array( $ctx, $findings, $fixed );
	$reports[] = $report;
	if ( $report['exit'] > 0 || ( $opts['strict'] && $report['summary']['P2'] > 0 ) ) {
		$exit = max( $exit, 1 );
	}
}

echo lint_render( $reports, $opts['format'] );
exit( $exit );
