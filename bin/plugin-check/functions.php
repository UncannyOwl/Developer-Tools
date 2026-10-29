<?php
// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput, WordPress.PHP.DiscouragedPHPFunctions, WordPress.PHP.NoSilencedErrors -- CLI tool that runs outside WordPress.
/**
 * The Plugin Check gate: normalise results, apply the plugin's ignore list, hold errors against a
 * committed baseline, and render text, json, github or a Markdown summary.
 *
 * Pure functions only. bin/plugin-check.php runs WordPress's Plugin Check and calls these.
 */

/**
 * @param string $file Path as Plugin Check reported it (absolute) or already relative.
 * @param string $root Root of the checked file set, or '' when paths are already relative.
 *
 * @return string Path relative to the root, with forward slashes.
 */
function pc_rel_path( $file, $root ) {
	$file = str_replace( '\\', '/', (string) $file );
	$root = rtrim( str_replace( '\\', '/', (string) $root ), '/' );
	if ( '' !== $root && 0 === strpos( $file, $root . '/' ) ) {
		$file = substr( $file, strlen( $root ) + 1 );
	}
	return ltrim( $file, '/' );
}

/**
 * Turn Plugin Check's strict-json items into rows with relative paths. The root is also stripped
 * from messages, since some checks name the absolute path and a temp folder changes every run.
 *
 * @param array           $items Decoded `wp plugin check --format=strict-json` output.
 * @param string|string[] $roots Root of the checked file set; give its realpath too when it differs.
 *
 * @return array<int,array{path:string,line:int,column:int,type:string,code:string,message:string,docs:string,severity:int}>
 */
function pc_normalize( array $items, $roots ) {
	$roots = array_filter(
		array_map(
			function ( $r ) {
				return rtrim( str_replace( '\\', '/', (string) $r ), '/' );
			},
			(array) $roots
		)
	);
	usort(
		$roots,
		function ( $a, $b ) {
			return strlen( $b ) - strlen( $a ); // Longest first, so /private/tmp/x wins over /tmp/x.
		}
	);
	$rows = array();
	foreach ( $items as $item ) {
		if ( ! is_array( $item ) ) {
			continue;
		}
		$message = isset( $item['message'] ) ? (string) $item['message'] : '';
		$path    = isset( $item['path'] ) ? $item['path'] : ( isset( $item['file'] ) ? $item['file'] : '' );
		foreach ( $roots as $root ) {
			$message = str_replace( array( $root . '/', $root ), '', $message );
			$path    = pc_rel_path( $path, $root );
		}
		$rows[] = array(
			'path'     => pc_rel_path( $path, '' ),
			'line'     => isset( $item['line'] ) ? (int) $item['line'] : 0,
			'column'   => isset( $item['column'] ) ? (int) $item['column'] : 0,
			'type'     => isset( $item['type'] ) && 'ERROR' === strtoupper( (string) $item['type'] ) ? 'ERROR' : 'WARNING',
			'code'     => isset( $item['code'] ) ? (string) $item['code'] : '',
			'message'  => trim( preg_replace( '~\s+~', ' ', $message ) ),
			'docs'     => isset( $item['docs'] ) ? (string) $item['docs'] : '',
			'severity' => isset( $item['severity'] ) ? (int) $item['severity'] : 0,
		);
	}
	usort(
		$rows,
		function ( $a, $b ) {
			return array( $a['path'], $a['line'], $a['column'], $a['code'] ) <=> array( $b['path'], $b['line'], $b['column'], $b['code'] );
		}
	);
	return $rows;
}

/**
 * Split check slugs into $jobs groups. The checks that run their own PHP_CodeSniffer pass over every
 * file take nearly all the time; they are dealt out heaviest first to the lightest group. Every other
 * check goes to the last group, which the caller runs with --exclude-checks: list-checks also names
 * runtime checks, which --checks rejects outside a runtime environment.
 *
 * @param string[] $checks
 * @param int      $jobs
 *
 * @return string[][] $jobs groups at most; the last one holds every check without a known cost.
 */
function pc_partition_checks( array $checks, $jobs ) {
	// Seconds per check on Automator Free (6.5k PHP files, four at a time).
	$weight = array(
		'prefixing'                  => 47,
		'plugin_review_phpcs'        => 29,
		'direct_db'                  => 29,
		'offloading_files'           => 25,
		'enqueued_resources'         => 25,
		'performant_wp_query_params' => 24,
		'i18n_usage'                 => 24,
		'write_file'                 => 24,
		'setting_sanitization'       => 24,
		'localhost'                  => 23,
		'enqueued_scripts_in_footer' => 23,
		'late_escaping'              => 23,
		'safe_redirect'              => 23,
		'direct_db_queries'          => 22,
		'php_error_reporting'        => 21,
		'ai_provider'                => 21,
		'direct_file_access'         => 14,
	);
	$heavy  = array_values( array_intersect( $checks, array_keys( $weight ) ) );
	$light  = array_values( array_diff( $checks, $heavy ) );
	$jobs   = max( 1, min( (int) $jobs, count( $heavy ) + 1 ) );
	usort(
		$heavy,
		function ( $a, $b ) use ( $weight ) {
			return array( $weight[ $b ], $a ) <=> array( $weight[ $a ], $b );
		}
	);
	$groups              = array_fill( 0, $jobs, array() );
	$load                = array_fill( 0, $jobs, 0 );
	$groups[ $jobs - 1 ] = $light;
	$load[ $jobs - 1 ]   = count( $light );
	foreach ( $heavy as $check ) {
		$i              = array_keys( $load, min( $load ), true )[0];
		$groups[ $i ][] = $check;
		$load[ $i ]    += $weight[ $check ];
	}
	return $groups;
}

/**
 * Plugin Check's stdout as result items. With no results it prints a Success line, not `[]`.
 *
 * @param string $out
 *
 * @return array|null Items, or null when the output is neither.
 */
function pc_decode_output( $out ) {
	$out = trim( (string) $out );
	if ( '' === $out || 0 === strpos( $out, 'Success:' ) ) {
		return array();
	}
	$decoded = json_decode( $out, true );
	return is_array( $decoded ) ? $decoded : null;
}

/**
 * Whether a config ignore rule covers a row. `code`, `path` and `message` are shell globs (fnmatch;
 * `*` crosses folders in `path`), or lists of globs of which one must match. An omitted key matches
 * everything, so a rule needs a code or a path.
 *
 * @param array $rule { code?: string|string[], path?: string|string[], message?: string|string[], type?: ERROR|WARNING, reason: string }
 * @param array $row
 *
 * @return bool
 */
function pc_rule_matches( array $rule, array $row ) {
	if ( empty( $rule['code'] ) && empty( $rule['path'] ) ) {
		return false;
	}
	foreach ( array( 'code', 'path', 'message' ) as $key ) {
		if ( empty( $rule[ $key ] ) ) {
			continue;
		}
		$hit = false;
		foreach ( (array) $rule[ $key ] as $glob ) {
			if ( fnmatch( (string) $glob, $row[ $key ] ) ) {
				$hit = true;
				break;
			}
		}
		if ( ! $hit ) {
			return false;
		}
	}
	return empty( $rule['type'] ) || strtoupper( $rule['type'] ) === $row['type'];
}

/**
 * The identity a baseline counts: path, code and message. The line is left out so code that moves
 * keeps its entry; a changed message or a moved file is a new finding.
 *
 * @param array $row
 *
 * @return string[] [path, code, message]
 */
function pc_fingerprint( array $row ) {
	return array( $row['path'], $row['code'], $row['message'] );
}

/**
 * @param array $baseline Decoded baseline file, or array().
 *
 * @return array<string,int> "path\0code\0message" => allowed count.
 */
function pc_baseline_counts( array $baseline ) {
	$counts = array();
	foreach ( isset( $baseline['errors'] ) && is_array( $baseline['errors'] ) ? $baseline['errors'] : array() as $path => $codes ) {
		foreach ( (array) $codes as $code => $messages ) {
			foreach ( (array) $messages as $message => $n ) {
				$counts[ $path . "\0" . $code . "\0" . $message ] = (int) $n;
			}
		}
	}
	return $counts;
}

/**
 * Label every row and decide the exit code.
 *
 * - ignored:   a config ignore rule covers it (never gates; counted).
 * - warning:   a WARNING (never gates; counted and summarised by code).
 * - baselined: an ERROR the baseline allows.
 * - new:       an ERROR beyond what the baseline allows for its fingerprint. When the baseline allows
 *              some but fewer than found, every instance is labelled new and carries `baseline_allows`,
 *              since the run cannot tell which one the PR added; the new count is the excess.
 *
 * @param array $rows     From pc_normalize().
 * @param array $config   { ignore?: array[] }
 * @param array $baseline Decoded baseline, or array().
 *
 * @return array{rows:array,summary:array,by_code:array,stale:array}
 */
function pc_gate( array $rows, array $config, array $baseline ) {
	$rules   = isset( $config['ignore'] ) && is_array( $config['ignore'] ) ? $config['ignore'] : array();
	$allowed = pc_baseline_counts( $baseline );
	$found   = array();
	foreach ( $rows as $i => $row ) {
		$rows[ $i ]['status'] = 'WARNING' === $row['type'] ? 'warning' : 'error';
		foreach ( $rules as $rule ) {
			if ( is_array( $rule ) && pc_rule_matches( $rule, $row ) ) {
				$rows[ $i ]['status'] = 'ignored';
				$rows[ $i ]['reason'] = isset( $rule['reason'] ) ? (string) $rule['reason'] : '';
				break;
			}
		}
		if ( 'error' === $rows[ $i ]['status'] ) {
			$found[ implode( "\0", pc_fingerprint( $row ) ) ][] = $i;
		}
	}

	$summary = array(
		'errors'     => 0,
		'warnings'   => 0,
		'new_errors' => 0,
		'baselined'  => 0,
		'ignored'    => 0,
		'stale'      => 0,
		'baseline'   => array_sum( $allowed ),
	);
	foreach ( $found as $key => $indexes ) {
		$allow = isset( $allowed[ $key ] ) ? $allowed[ $key ] : 0;
		$over  = count( $indexes ) - $allow;
		foreach ( $indexes as $i ) {
			$rows[ $i ]['status'] = $over > 0 ? 'new' : 'baselined';
			if ( $over > 0 && $allow > 0 ) {
				$rows[ $i ]['baseline_allows'] = $allow;
			}
		}
		$summary['new_errors'] += max( 0, $over );
		$summary['baselined']  += min( $allow, count( $indexes ) );
	}

	$stale = array();
	foreach ( $allowed as $key => $allow ) {
		$have = isset( $found[ $key ] ) ? count( $found[ $key ] ) : 0;
		if ( $have < $allow ) {
			list( $path, $code, $message ) = explode( "\0", $key, 3 );
			$stale[]                       = array(
				'path'    => $path,
				'code'    => $code,
				'message' => $message,
				'allowed' => $allow,
				'found'   => $have,
			);
			$summary['stale']             += $allow - $have;
		}
	}

	$by_code = array();
	foreach ( $rows as $row ) {
		if ( ! isset( $by_code[ $row['code'] ] ) ) {
			$by_code[ $row['code'] ] = array(
				'errors'   => 0,
				'warnings' => 0,
				'new'      => 0,
				'ignored'  => 0,
			);
		}
		if ( 'ignored' === $row['status'] ) {
			++$summary['ignored'];
			++$by_code[ $row['code'] ]['ignored'];
		} elseif ( 'warning' === $row['status'] ) {
			++$summary['warnings'];
			++$by_code[ $row['code'] ]['warnings'];
		} else {
			++$summary['errors'];
			++$by_code[ $row['code'] ]['errors'];
			if ( 'new' === $row['status'] ) {
				++$by_code[ $row['code'] ]['new'];
			}
		}
	}
	uasort(
		$by_code,
		function ( $a, $b ) {
			return array( $b['new'], $b['errors'], $b['warnings'], $b['ignored'] ) <=> array( $a['new'], $a['errors'], $a['warnings'], $a['ignored'] );
		}
	);

	return array(
		'rows'    => $rows,
		'summary' => $summary,
		'by_code' => $by_code,
		'stale'   => $stale,
	);
}

/**
 * The baseline for the errors a run found (ignored rows excluded): path => code => message => count,
 * sorted so the file diffs cleanly.
 *
 * @param array  $rows                 Gated rows.
 * @param string $plugin_check_version Version that produced them.
 *
 * @return array
 */
function pc_build_baseline( array $rows, $plugin_check_version ) {
	$errors = array();
	$total  = 0;
	foreach ( $rows as $row ) {
		if ( 'ERROR' !== $row['type'] || ( isset( $row['status'] ) && 'ignored' === $row['status'] ) ) {
			continue;
		}
		list( $path, $code, $message ) = pc_fingerprint( $row );
		if ( ! isset( $errors[ $path ][ $code ][ $message ] ) ) {
			$errors[ $path ][ $code ][ $message ] = 0;
		}
		++$errors[ $path ][ $code ][ $message ];
		++$total;
	}
	ksort( $errors );
	foreach ( $errors as $path => $codes ) {
		ksort( $codes );
		foreach ( $codes as $code => $messages ) {
			ksort( $messages );
			$codes[ $code ] = $messages;
		}
		$errors[ $path ] = $codes;
	}
	return array(
		'_comment'             => 'Plugin Check errors that existed when this file was written. A PR fails on any error beyond these counts. Regenerate with --update-baseline; never hand-edit to hide a new error.',
		'plugin_check_version' => (string) $plugin_check_version,
		'total'                => $total,
		'errors'               => (object) $errors,
	);
}

/**
 * @param array $gate   From pc_gate().
 * @param array $meta   { slug, plugin_check_version, checked, source, baseline, baseline_version, elapsed, strict }
 *
 * @return array The json report.
 */
function pc_report_array( array $gate, array $meta ) {
	$exit = $gate['summary']['new_errors'] > 0 || ( ! empty( $meta['strict'] ) && $gate['summary']['stale'] > 0 ) ? 1 : 0;
	return array(
		'slug'                 => isset( $meta['slug'] ) ? $meta['slug'] : '',
		'plugin_check_version' => isset( $meta['plugin_check_version'] ) ? $meta['plugin_check_version'] : '',
		'baseline_version'     => isset( $meta['baseline_version'] ) ? $meta['baseline_version'] : '',
		'checked'              => isset( $meta['checked'] ) ? $meta['checked'] : '',
		'source'               => isset( $meta['source'] ) ? $meta['source'] : '',
		'baseline'             => isset( $meta['baseline'] ) ? $meta['baseline'] : null,
		'elapsed_seconds'      => isset( $meta['elapsed'] ) ? $meta['elapsed'] : null,
		'summary'              => $gate['summary'],
		'by_code'              => $gate['by_code'],
		'new'                  => array_values(
			array_filter(
				$gate['rows'],
				function ( $r ) {
					return 'new' === $r['status'];
				}
			)
		),
		'stale'                => $gate['stale'],
		'findings'             => $gate['rows'],
		'exit'                 => $exit,
	);
}

/**
 * @param array $report From pc_report_array().
 *
 * @return string One line: the counts a reader needs first.
 */
function pc_summary_line( array $report ) {
	$s = $report['summary'];
	return sprintf(
		'%d new error%s · %d baselined · %d warnings · %d ignored%s',
		$s['new_errors'],
		1 === $s['new_errors'] ? '' : 's',
		$s['baselined'],
		$s['warnings'],
		$s['ignored'],
		$s['stale'] ? " · {$s['stale']} baseline entries no longer occur (run --update-baseline)" : ''
	);
}

/**
 * @param array  $report From pc_report_array().
 * @param string $format text | json | github
 *
 * @return string
 */
function pc_render( array $report, $format ) {
	if ( 'json' === $format ) {
		return json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
	}
	$out = '';
	if ( 'github' === $format ) {
		foreach ( $report['new'] as $r ) {
			$out .= sprintf(
				"::error file=%s,line=%d,col=%d,title=Plugin Check %s::%s%s\n",
				pc_gh_escape_property( $r['path'] ),
				max( 1, $r['line'] ),
				max( 1, $r['column'] ),
				pc_gh_escape_property( $r['code'] ),
				pc_gh_escape_data( $r['message'] ),
				isset( $r['baseline_allows'] ) ? pc_gh_escape_data( ' (the baseline allows ' . $r['baseline_allows'] . ' of these in this file)' ) : ''
			);
		}
		if ( $report['summary']['stale'] > 0 ) {
			$out .= '::notice title=Plugin Check baseline::' . $report['summary']['stale'] . " baselined errors no longer occur; run --update-baseline to shrink the baseline\n";
		}
		$out .= ( $report['exit'] ? '::error' : '::notice' ) . ' title=Plugin Check::' . pc_gh_escape_data( $report['slug'] . ': ' . pc_summary_line( $report ) ) . "\n";
		return $out;
	}

	$out .= "== Plugin Check {$report['slug']} (plugin-check {$report['plugin_check_version']}) ==\n";
	$out .= 'Checked:  ' . $report['checked'] . ( $report['source'] && $report['source'] !== $report['checked'] ? " (from {$report['source']})" : '' ) . "\n";
	$out .= 'Baseline: ' . ( $report['baseline'] ? $report['baseline'] . " ({$report['summary']['baseline']} errors)" : 'none' ) . "\n";
	if ( $report['baseline_version'] && $report['plugin_check_version'] && $report['baseline_version'] !== $report['plugin_check_version'] ) {
		$out .= "Note:     the baseline was written by plugin-check {$report['baseline_version']}; codes may differ\n";
	}
	$out .= "\n";
	foreach ( $report['new'] as $r ) {
		$out .= sprintf(
			"NEW   %s:%d  [%s] %s%s\n",
			$r['path'],
			$r['line'],
			$r['code'],
			$r['message'],
			isset( $r['baseline_allows'] ) ? " (baseline allows {$r['baseline_allows']})" : ''
		);
	}
	if ( ! empty( $report['new'] ) ) {
		$out .= "\n";
	}
	$out .= "By code (errors: new/total · warnings · ignored):\n";
	foreach ( $report['by_code'] as $code => $c ) {
		$out .= sprintf( "  %-70s %4d/%-5d %6d %6d\n", $code, $c['new'], $c['errors'], $c['warnings'], $c['ignored'] );
	}
	$out .= "\n" . pc_summary_line( $report ) . "\n";
	return $out;
}

/**
 * Markdown for $GITHUB_STEP_SUMMARY: the verdict, new errors, and a table of codes.
 *
 * @param array $report From pc_report_array().
 *
 * @return string
 */
function pc_render_markdown( array $report ) {
	$md  = "## Plugin Check\n\n";
	$md .= ( $report['exit'] ? '**Failed.** ' : '**Passed.** ' ) . pc_summary_line( $report ) . "\n\n";
	$md .= "Checked the shipped file set (`{$report['source']}`) with plugin-check {$report['plugin_check_version']}. ";
	$md .= "A new ERROR fails the PR; baselined errors and warnings are listed, not gated.\n\n";
	if ( ! empty( $report['new'] ) ) {
		$md .= "### New errors\n\n| File | Code | Message |\n|---|---|---|\n";
		foreach ( array_slice( $report['new'], 0, 100 ) as $r ) {
			$md .= sprintf( "| `%s:%d` | `%s` | %s |\n", $r['path'], $r['line'], $r['code'], str_replace( '|', '\|', $r['message'] ) );
		}
		$md .= count( $report['new'] ) > 100 ? "\n…and " . ( count( $report['new'] ) - 100 ) . " more.\n" : '';
		$md .= "\n";
	}
	$md .= "### By code\n\n| Code | New | Errors | Warnings | Ignored |\n|---|---:|---:|---:|---:|\n";
	foreach ( $report['by_code'] as $code => $c ) {
		$md .= sprintf( "| `%s` | %d | %d | %d | %d |\n", $code, $c['new'], $c['errors'], $c['warnings'], $c['ignored'] );
	}
	return $md;
}

/**
 * @param string $s
 *
 * @return string Escaped for a workflow command's data part.
 */
function pc_gh_escape_data( $s ) {
	return str_replace( array( '%', "\r", "\n" ), array( '%25', '%0D', '%0A' ), (string) $s );
}

/**
 * @param string $s
 *
 * @return string Escaped for a workflow command's property value.
 */
function pc_gh_escape_property( $s ) {
	return str_replace( array( '%', "\r", "\n", ':', ',' ), array( '%25', '%0D', '%0A', '%3A', '%2C' ), (string) $s );
}
