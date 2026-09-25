<?php
// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput, WordPress.PHP.DiscouragedPHPFunctions, WordPress.PHP.NoSilencedErrors -- CLI tool that runs outside WordPress.
/**
 * Output: text for a terminal, json for tools, github for workflow annotations.
 */

/**
 * @param Lint_Context   $ctx
 * @param Lint_Finding[] $findings
 * @param int            $fixed
 *
 * @return array
 */
function lint_report_array( Lint_Context $ctx, array $findings, $fixed = 0 ) {
	$summary = array(
		'P0'          => 0,
		'P1'          => 0,
		'P2'          => 0,
		'report'      => 0,
		'preexisting' => 0,
	);
	$rows    = array();
	foreach ( $findings as $f ) {
		if ( $f->preexisting ) {
			++$summary['preexisting'];
		} else {
			++$summary[ $f->severity ];
		}
		$rows[] = $f->to_array( $ctx );
	}
	return array(
		'slug'     => $ctx->slug,
		'free'     => $ctx->free,
		'pro'      => $ctx->pro,
		'findings' => $rows,
		'summary'  => $summary,
		'fixed'    => $fixed,
		'exit'     => ( $summary['P0'] + $summary['P1'] ) > 0 ? 1 : 0,
	);
}

/**
 * @param array $reports One report array per slug.
 * @param string $format text | json | github
 *
 * @return string
 */
function lint_render( array $reports, $format ) {
	if ( 'json' === $format ) {
		$payload = 1 === count( $reports ) ? $reports[0] : array(
			'reports' => $reports,
			'exit'    => max( array_column( $reports, 'exit' ) ),
		);
		return json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
	}
	$out = '';
	foreach ( $reports as $r ) {
		$tail = sprintf( '%d P0 · %d P1 · %d P2 · %d reports', $r['summary']['P0'], $r['summary']['P1'], $r['summary']['P2'], $r['summary']['report'] )
			. ( $r['summary']['preexisting'] ? " · {$r['summary']['preexisting']} pre-existing (not gated)" : '' )
			. ( $r['fixed'] ? " · {$r['fixed']} fixed" : '' );
		if ( 'github' === $format ) {
			foreach ( $r['findings'] as $f ) {
				$level = ( $f['preexisting'] || 'report' === $f['severity'] ) ? 'notice' : ( 'P2' === $f['severity'] ? 'warning' : 'error' );
				$out  .= sprintf(
					"::%s file=%s,line=%d,title=%s %s::[%s%s] %s\n",
					$level,
					$f['path'],
					max( 1, $f['line'] ),
					$f['rule'],
					$f['check'],
					$f['severity'],
					$f['preexisting'] ? ', pre-existing' : '',
					str_replace( array( "\r", "\n" ), ' ', $f['message'] )
				);
			}
			$out .= "{$r['slug']}: $tail\n";
			continue;
		}
		$out .= "== lint {$r['slug']} ==\nFree: {$r['free']}\nPro:  " . ( $r['pro'] ?? 'none' ) . "\n\n";
		foreach ( $r['findings'] as $f ) {
			$where = $f['path'] . ( $f['line'] ? ':' . $f['line'] : '' );
			$kind  = $f['preexisting'] ? 'OLD' : ( 'report' === $f['severity'] ? 'REPORT' : 'FLAG' );
			$out  .= sprintf( "%-7s %-2s [%s %s] %s — %s\n", $kind, 'report' === $f['severity'] ? '' : $f['severity'], $f['rule'], $f['check'], $where, $f['message'] );
		}
		$out .= "\n$tail\n\n";
	}
	return $out;
}

/**
 * Sort findings: P0 first, then P1, P2, report; then by file and line.
 *
 * @param Lint_Finding[] $findings
 *
 * @return Lint_Finding[]
 */
function lint_sort_findings( array $findings ) {
	$rank = array(
		'P0'     => 0,
		'P1'     => 1,
		'P2'     => 2,
		'report' => 3,
	);
	usort(
		$findings,
		function ( Lint_Finding $a, Lint_Finding $b ) use ( $rank ) {
			$d = $rank[ $a->severity ] - $rank[ $b->severity ];
			if ( 0 !== $d ) {
				return $d;
			}
			$d = strcmp( $a->file, $b->file );
			return 0 !== $d ? $d : $a->line - $b->line;
		}
	);
	return $findings;
}
