<?php

namespace Uncanny_Owl\Wp_Test_Kernel\Mutate;

/**
 * bin/mutate: mutation testing of a plugin's core, judged per file against targets and floors.
 *
 * The Automator platform's discipline (tools/mutate, RB27), on Infection and the WordPress test kernel:
 *
 * - a file at its target passes (critical path 90; every other core file 75 overall and 80 covered);
 * - under its target, it passes while it holds its floor, and is listed as debt;
 * - it fails below its floor, or when it is new to the baseline and under its target;
 * - a run that stops before it mutates, or a file with no test beside it, fails;
 * - inspection options change which mutants run: the run reports, records nothing and exits 3.
 */
final class Cli {

	const PASS       = 0;
	const FAIL       = 1;
	const INSPECTION = 3;
	const USAGE      = 64;

	const ADVISE_FILES  = 10;
	const ADVISE_SHARDS = 20;

	/** Options that only change which mutants run: allowed, but the run becomes an inspection. */
	const INSPECTION_OPTIONS = array( 'mutators', 'show-mutations', 'ignore-msi-with-no-mutations' );

	const KNOWN_OPTIONS = array( 'plugin-path', 'config', 'project-dir', 'path', 'changed', 'advise', 'plan', 'update-baseline', 'create-baseline', 'shard', 'threads', 'coverage-options', 'format', 'base' );

	/**
	 * @param string[] $argv
	 *
	 * @return int Exit code.
	 */
	public static function main( array $argv ) {

		$options = array();
		foreach ( array_slice( $argv, 1 ) as $arg ) {
			if ( 0 !== strpos( $arg, '--' ) ) {
				fwrite( STDERR, "Unexpected argument: $arg\n" );
				return self::USAGE;
			}
			$parts = explode( '=', substr( $arg, 2 ), 2 );
			if ( ! in_array( $parts[0], self::KNOWN_OPTIONS, true ) && ! in_array( $parts[0], self::INSPECTION_OPTIONS, true ) ) {
				fwrite( STDERR, "Unknown option --{$parts[0]} (see bin/mutate's header).\n" );
				return self::USAGE;
			}
			$options[ $parts[0] ] = $parts[1] ?? true;
		}

		$root   = rtrim( (string) ( $options['plugin-path'] ?? getcwd() ), '/' );
		$scope  = Scope::load( $root, (string) ( $options['config'] ?? 'tests/mutate.php' ) );
		$base   = (string) ( is_string( $options['changed'] ?? null ) ? $options['changed'] : ( $options['base'] ?? ( $scope->get( 'base' ) ?: 'origin/pre-release' ) ) );
		$format = (string) ( $options['format'] ?? 'text' );

		if ( isset( $options['advise'] ) ) {
			echo self::advice( $scope, self::changed_files( $scope, $base ), $base ), PHP_EOL;
			return self::PASS;
		}

		// Which files.
		if ( isset( $options['changed'] ) ) {
			$files = self::changed_files( $scope, $base );
			if ( empty( $files ) ) {
				echo "No in-scope source file changed against $base: nothing to mutate.\n";
				return self::PASS;
			}
		} elseif ( isset( $options['path'] ) ) {
			$paths = array_filter( explode( ',', (string) $options['path'] ) );
			if ( empty( $paths ) ) {
				fwrite( STDERR, "--path needs at least one file or folder.\n" );
				return self::USAGE;
			}
			$files = $scope->select( $paths );
		} else {
			$files = $scope->files();
		}

		$baseline   = new Baseline( $root . '/' . ( $scope->get( 'baseline' ) ?: 'tests/mutate-baseline.php' ) );
		$creating   = isset( $options['create-baseline'] );
		$updating   = isset( $options['update-baseline'] );
		$inspection = array_intersect( array_keys( $options ), self::INSPECTION_OPTIONS );

		if ( $creating && $baseline->exists() ) {
			fwrite( STDERR, "A baseline exists; --create-baseline only makes the first one. Use --update-baseline.\n" );
			return self::USAGE;
		}
		if ( ( $creating || $updating ) && ! empty( $inspection ) ) {
			fwrite( STDERR, "An inspection run records no floor; drop --update-baseline / --create-baseline.\n" );
			return self::USAGE;
		}

		$plan = Plan::build( $scope, $files );
		$runs = $plan['runs'];

		if ( isset( $options['plan'] ) ) {
			$critical = count( array_filter( $files, array( $scope, 'is_critical' ) ) );
			printf( "Plan: %d files in scope (%d on the critical path), %d runs, %d with no test beside them.\n", count( $files ), $critical, count( $runs ), count( $plan['untested'] ) );
			foreach ( $runs as $i => $run ) {
				printf( "  run %d: %d files judged by %d test files (%s)\n", $i + 1, count( $run['files'] ), count( $run['tests'] ), dirname( $run['tests'][0] ) );
			}
			if ( ! empty( $plan['untested'] ) ) {
				echo "No test beside them (fail until a mirror test or also_tested_by covers them):\n";
				foreach ( $plan['untested'] as $file ) {
					echo '  ', $file, $scope->is_critical( $file ) ? '  [critical]' : '', PHP_EOL;
				}
			}
			return self::PASS;
		}
		if ( isset( $options['shard'] ) ) {
			if ( ! preg_match( '#^(\d+)/(\d+)$#', (string) $options['shard'], $m ) || (int) $m[1] < 1 || (int) $m[1] > (int) $m[2] ) {
				fwrite( STDERR, "--shard takes i/N, 1 <= i <= N.\n" );
				return self::USAGE;
			}
			$runs = array_values( array_filter( $runs, static fn( $run, $i ) => ( $i % (int) $m[2] ) === ( (int) $m[1] - 1 ), ARRAY_FILTER_USE_BOTH ) );
		}

		$extra = array();
		foreach ( $inspection as $name ) {
			$extra[] = true === $options[ $name ] ? '--' . $name : '--' . $name . '=' . $options[ $name ];
		}

		$runner = new Infection_Runner(
			$scope,
			(string) ( $options['project-dir'] ?? $root ),
			(int) ( $options['threads'] ?? ( $scope->get( 'threads' ) ?: 4 ) ),
			(string) ( $options['coverage-options'] ?? ( getenv( 'WP_TEST_KERNEL_COVERAGE_OPTIONS' ) ?: (string) $scope->get( 'coverage_options' ) ) )
		);

		printf( "Mutation testing: %d files in %d runs (%d untested)\n", count( $files ), count( $runs ), count( $plan['untested'] ) );

		$verdicts = array();
		foreach ( $runs as $i => $run ) {
			printf( "  run %d/%d: %d files, %d test files ... ", $i + 1, count( $runs ), count( $run['files'] ), count( $run['tests'] ) );
			$result = $runner->run( $i + 1, $run, $extra );
			printf( "%d s%s\n", $result['seconds'], null === $result['stopped'] ? '' : ' — STOPPED' );

			foreach ( $run['files'] as $file ) {
				$verdicts[ $file ] = null === $result['stopped']
					? self::judge( $scope, $baseline, $file, $result['tallies'][ $file ] ?? new Tally(), $creating )
					: array( 'status' => 'stopped', 'pass' => false, 'tally' => null, 'note' => $result['stopped'] );
			}
		}
		foreach ( $plan['untested'] as $file ) {
			$verdicts[ $file ] = array( 'status' => 'untested', 'pass' => false, 'tally' => null, 'note' => 'no test beside it (mirror folder or also_tested_by)' );
		}
		foreach ( Suppressions::unexplained( $root, $files ) as $file => $lines ) {
			$verdicts[ $file ] = array(
				'status' => 'suppression',
				'pass'   => false,
				'tally'  => $verdicts[ $file ]['tally'] ?? null,
				'note'   => 'suppression without a reason at line ' . implode( ', ', $lines ) . ' (the comment above must start "Equivalent:" or "Reason:")',
			);
		}
		ksort( $verdicts );

		$passed = ! in_array( false, array_column( $verdicts, 'pass' ), true );
		self::report( $scope, $baseline, $verdicts, $format );

		if ( ! empty( $inspection ) ) {
			echo "Inspection run (" . implode( ', ', $inspection ) . "): no verdict, no floor recorded.\n";
			return self::INSPECTION;
		}

		if ( $creating || ( $updating && $passed ) ) {
			$changed = 0;
			foreach ( $verdicts as $file => $verdict ) {
				if ( ! $verdict['tally'] instanceof Tally || 0 === $verdict['tally']->total() ) {
					continue;
				}
				$allowed = $creating || null !== $baseline->floor( $file ) || 'target' === $verdict['status'];
				if ( $allowed && $baseline->raise( $file, $verdict['tally'] ) ) {
					++$changed;
				}
			}
			$baseline->prune( $scope );
			$baseline->save();
			printf( "Baseline: %d floors recorded or raised.\n", $changed );
		} elseif ( $updating ) {
			echo "Baseline not updated: the run failed.\n";
		}

		return $passed ? self::PASS : self::FAIL;
	}

	/**
	 * @param Scope    $scope
	 * @param Baseline $baseline
	 * @param string   $file
	 * @param Tally    $tally
	 * @param bool     $creating
	 *
	 * @return array{status: string, pass: bool, tally: Tally, note: string}
	 */
	private static function judge( Scope $scope, Baseline $baseline, $file, Tally $tally, $creating ) {

		if ( 0 === $tally->total() ) {
			return array( 'status' => 'nothing', 'pass' => true, 'tally' => $tally, 'note' => 'nothing to mutate' );
		}
		if ( $scope->meets_target( $file, $tally ) ) {
			return array( 'status' => 'target', 'pass' => true, 'tally' => $tally, 'note' => 'at its target' );
		}

		$floor = $baseline->floor( $file );
		if ( null === $floor ) {
			return $creating
				? array( 'status' => 'debt', 'pass' => true, 'tally' => $tally, 'note' => 'first floor, under its target' )
				: array( 'status' => 'new', 'pass' => false, 'tally' => $tally, 'note' => 'new to the baseline and under its target' );
		}

		return Baseline::holds( $tally, $floor )
			? array( 'status' => 'debt', 'pass' => true, 'tally' => $tally, 'note' => 'under its target, holds its floor' )
			: array( 'status' => 'below-floor', 'pass' => false, 'tally' => $tally, 'note' => sprintf( 'below its floor (%.1f%%, covered %.1f%%)', $floor->score(), $floor->covered_score() ) );
	}

	/**
	 * @param Scope                                  $scope
	 * @param Baseline                               $baseline
	 * @param array<string, array<string, mixed>>    $verdicts
	 * @param string                                 $format
	 *
	 * @return void
	 */
	private static function report( Scope $scope, Baseline $baseline, array $verdicts, $format ) {

		$all      = new Tally();
		$critical = new Tally();
		$rows     = array();
		$debt     = array();

		foreach ( $verdicts as $file => $verdict ) {
			$tally = $verdict['tally'];
			if ( $tally instanceof Tally ) {
				$all->add( $tally );
				if ( $scope->is_critical( $file ) ) {
					$critical->add( $tally );
				}
			}
			$floor  = $baseline->floor( $file );
			$rows[] = array(
				'file'    => $file,
				'score'   => $tally instanceof Tally ? sprintf( '%.1f%%', $tally->score() ) : '—',
				'covered' => $tally instanceof Tally ? sprintf( '%.1f%%', $tally->covered_score() ) : '—',
				'mutants' => $tally instanceof Tally ? (string) $tally->total() : '—',
				'target'  => $scope->target_label( $file ),
				'floor'   => null === $floor ? '—' : sprintf( '%.1f%%', $floor->score() ),
				'verdict' => ( $verdict['pass'] ? 'pass' : 'FAIL' ) . ': ' . $verdict['note'],
			);
			if ( in_array( $verdict['status'], array( 'debt', 'new', 'below-floor' ), true ) && $tally instanceof Tally ) {
				$debt[ ( $scope->is_critical( $file ) ? '0' : '1' ) . $file ] = array( $file, $tally );
			}

			if ( 'github' === $format && ! $verdict['pass'] && $tally instanceof Tally ) {
				foreach ( $tally->escapes as $escape ) {
					printf( "::error file=%s,line=%d,title=Escaped mutant (%s)::%s — %s\n", $file, $escape['line'], $escape['mutator'], $file, $verdict['note'] );
				}
			}
		}
		ksort( $debt );

		$summary = sprintf( 'Whole run: %.1f%% (covered %.1f%%) over %d mutants. Critical path: %.1f%% (covered %.1f%%) over %d mutants.', $all->score(), $all->covered_score(), $all->total(), $critical->score(), $critical->covered_score(), $critical->total() );

		if ( 'markdown' === $format ) {
			echo "### Mutation testing\n\n$summary\n\n| File | Score | Covered | Mutants | Target | Floor | Verdict |\n|---|---|---|---|---|---|---|\n";
			foreach ( $rows as $row ) {
				printf( "| `%s` | %s | %s | %s | %s | %s | %s |\n", $row['file'], $row['score'], $row['covered'], $row['mutants'], $row['target'], $row['floor'], $row['verdict'] );
			}
			return;
		}

		echo PHP_EOL;
		foreach ( $rows as $row ) {
			printf( "%-90s %7s %8s %6s  target %-20s floor %-7s %s\n", $row['file'], $row['score'], $row['covered'], $row['mutants'], $row['target'], $row['floor'], $row['verdict'] );
		}
		echo PHP_EOL, $summary, PHP_EOL;

		if ( ! empty( $debt ) ) {
			echo PHP_EOL, "Debt and failures — the work list, critical path first (escaped mutants by line):\n";
			foreach ( $debt as list( $file, $tally ) ) {
				$lines = array();
				foreach ( array_slice( $tally->escapes, 0, 12 ) as $escape ) {
					$lines[] = $escape['line'] . ' ' . $escape['mutator'];
				}
				printf( "  %s: %s%s\n", $file, implode( ', ', $lines ), count( $tally->escapes ) > 12 ? sprintf( ' … +%d more', count( $tally->escapes ) - 12 ) : '' );
			}
		}
	}

	/**
	 * In-scope files the branch adds or changes against its base, plus uncommitted changes.
	 *
	 * @param Scope  $scope
	 * @param string $base
	 *
	 * @return string[]
	 */
	private static function changed_files( Scope $scope, $base ) {
		$root  = escapeshellarg( $scope->root() );
		$lines = array();
		exec( sprintf( 'git -C %s diff --name-only --diff-filter=AM %s...HEAD 2>/dev/null', $root, escapeshellarg( $base ) ), $lines );
		exec( sprintf( 'git -C %s diff --name-only --diff-filter=AM HEAD 2>/dev/null', $root ), $lines );

		$files = array();
		foreach ( array_unique( $lines ) as $file ) {
			if ( $scope->holds( $file ) && is_file( $scope->root() . '/' . $file ) ) {
				$files[] = $file;
			}
		}
		sort( $files );

		return $files;
	}

	/**
	 * Whether this branch calls for a run, and the exact command. Runs nothing.
	 *
	 * @param Scope    $scope
	 * @param string[] $files
	 * @param string   $base
	 *
	 * @return string
	 */
	private static function advice( Scope $scope, array $files, $base ) {
		if ( empty( $files ) ) {
			return "No run needed: no in-scope source file changed against $base.";
		}

		$key = array_values( array_filter( $files, static fn( $file ) => $scope->is_critical( $file ) || $scope->is_core_abstract( $file ) ) );
		if ( count( $files ) > self::ADVISE_FILES ) {
			return count( $files ) > self::ADVISE_SHARDS
				? sprintf( "Run: %d in-scope files changed — bin/mutate --changed --shard=1/3 (and 2/3, 3/3, each in its own terminal).", count( $files ) )
				: sprintf( 'Run: %d in-scope files changed — bin/mutate --changed', count( $files ) );
		}
		if ( ! empty( $key ) ) {
			return 'Run: the branch changes critical-path or core-abstract files — bin/mutate --path=' . implode( ',', $key );
		}

		return sprintf( 'No run needed: %d in-scope file(s) changed, none on the critical path or a core abstract; the next whole-scope run judges them.', count( $files ) );
	}
}
