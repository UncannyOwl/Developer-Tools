#!/usr/bin/env php
<?php
/**
 * Hook Documentation Scanner for Uncanny Automator.
 *
 * Scans plugin codebases for do_action() and apply_filters() calls, extracts
 * docblocks, parameters, source context, related hooks, and internal usage.
 * Outputs one Markdown file per hook with YAML frontmatter and {{PLACEHOLDERS}}
 * for AI enrichment.
 *
 * Requires: ripgrep (rg) installed on the system.
 *
 * Usage:
 *   php bin/scan-hooks.php --plugin-path /path/to/uncanny-automator [--pro-path ...] [--output hook-docs]
 *
 * @package Automator_Dev_Tools
 */

$file_cache = [];

/**
 * Entry point.
 *
 * @param array $argv CLI arguments.
 *
 * @return int Exit code.
 */
function main( array $argv ): int {
	$args = parse_cli_args();

	if ( empty( $args['plugin-path'] ) ) {
		fwrite( STDERR, "Error: --plugin-path is required.\n" );
		fwrite( STDERR, "Usage: php scan-hooks.php --plugin-path /path/to/plugin [--pro-path ...] [--output hook-docs]\n" );
		return 1;
	}

	// Build scan paths from plugin roots.
	$scan_paths = [];
	$plugin_path = realpath( rtrim( $args['plugin-path'], DIRECTORY_SEPARATOR ) );

	if ( false === $plugin_path || ! is_dir( $plugin_path . '/src' ) ) {
		fwrite( STDERR, "Error: Plugin path not found or missing src/: {$args['plugin-path']}\n" );
		return 1;
	}
	// Primary plugin: relative prefix is '' (files become src/...).
	$scan_paths[] = $plugin_path . '/src';
	$primary_slug = basename( $plugin_path );
	register_plugin_slug( '', $primary_slug );

	if ( ! empty( $args['pro-path'] ) ) {
		$pro_path = realpath( rtrim( $args['pro-path'], DIRECTORY_SEPARATOR ) );
		if ( false !== $pro_path && is_dir( $pro_path . '/src' ) ) {
			$scan_paths[] = $pro_path . '/src';
			$pro_slug     = basename( $pro_path );
			register_plugin_slug( $pro_slug . '/', $pro_slug );
		} else {
			fwrite( STDERR, "Warning: Pro path not found, skipping: {$args['pro-path']}\n" );
		}
	}

	// Handle multiple addon paths.
	$addon_raw = $args['addon-path'] ?? null;
	if ( null !== $addon_raw ) {
		$addon_list = is_array( $addon_raw ) ? $addon_raw : array( $addon_raw );
		foreach ( $addon_list as $addon_arg ) {
			$addon_real = realpath( rtrim( $addon_arg, DIRECTORY_SEPARATOR ) );
			if ( false !== $addon_real && is_dir( $addon_real . '/src' ) ) {
				$scan_paths[] = $addon_real . '/src';
				$addon_slug   = basename( $addon_real );
				register_plugin_slug( $addon_slug . '/', $addon_slug );
			} else {
				fwrite( STDERR, "Warning: Addon path not found, skipping: {$addon_arg}\n" );
			}
		}
	}

	$rg_bin = find_ripgrep();
	if ( null === $rg_bin ) {
		fwrite( STDERR, "Error: ripgrep (rg) is required but not installed.\n" );
		return 1;
	}

	$output_dir    = $args['output'] ?? 'hook-docs';
	$context_lines = (int) ( $args['context-lines'] ?? 7 );
	$dry_run       = isset( $args['dry-run'] );
	$fail_undoc    = isset( $args['fail-on-undocumented'] );

	fwrite( STDERR, "Scanning for hooks…\n" );

	// Build path stripping map: absolute prefix → relative prefix.
	$path_strip_map = [];
	$path_strip_map[ $plugin_path . '/' ] = '';
	if ( ! empty( $pro_path ) ) {
		// Use the directory name as the relative prefix for pro.
		$path_strip_map[ $pro_path . '/' ] = basename( $pro_path ) . '/';
	}

	// --- Phase 1: Ripgrep scan for hook definitions ---
	$all_matches = [];
	foreach ( $scan_paths as $path ) {
		$matches     = run_ripgrep( $path );
		$all_matches = array_merge( $all_matches, $matches );
		fwrite( STDERR, sprintf( "  %s — %d raw matches\n", $path, count( $matches ) ) );
	}

	// Store relative paths alongside absolute for output, but keep absolute for file reads.
	foreach ( $all_matches as &$match_ref ) {
		$abs = $match_ref['path']['text'];
		$rel = $abs;
		foreach ( $path_strip_map as $abs_prefix => $rel_prefix ) {
			if ( str_starts_with( $abs, $abs_prefix ) ) {
				$rel = $rel_prefix . substr( $abs, strlen( $abs_prefix ) );
				break;
			}
		}
		$match_ref['path']['absolute'] = $abs;
		$match_ref['path']['text']     = $rel;
	}
	unset( $match_ref );

	fwrite( STDERR, sprintf( "Processing %d matches…\n", count( $all_matches ) ) );

	// --- Phase 2: Process matches into hook data ---
	$hooks = [];
	foreach ( $all_matches as $match ) {
		$hook = process_match( $match, $context_lines );
		if ( null === $hook ) {
			continue;
		}

		$name = $hook['name'];
		if ( isset( $hooks[ $name ] ) ) {
			$hooks[ $name ]['locations'][] = $hook['locations'][0];
			// Prefer the version that has documentation.
			if ( false === $hook['undocumented'] && true === $hooks[ $name ]['undocumented'] ) {
				$locations                  = $hooks[ $name ]['locations'];
				$hooks[ $name ]             = $hook;
				$hooks[ $name ]['locations'] = $locations;
			}
		} else {
			$hooks[ $name ] = $hook;
		}
	}

	ksort( $hooks );

	// --- Phase 3: Post-processing — related hooks ---
	$hooks = detect_related_hooks( $hooks );

	// --- Phase 4: Internal usage scan ---
	fwrite( STDERR, "Scanning for internal hook usage…\n" );
	$usage_map = scan_internal_usage( $scan_paths, $path_strip_map );
	foreach ( $hooks as $name => &$hook ) {
		if ( isset( $usage_map[ $name ] ) ) {
			$hook['usage'] = $usage_map[ $name ];
		}
	}
	unset( $hook );

	// --- Stats ---
	$hooks_list       = array_values( $hooks );
	$total            = count( $hooks_list );
	$action_count     = count( array_filter( $hooks_list, fn( $h ) => 'action' === $h['type'] ) );
	$filter_count     = count( array_filter( $hooks_list, fn( $h ) => 'filter' === $h['type'] ) );
	$undocumented     = count( array_filter( $hooks_list, fn( $h ) => $h['undocumented'] ) );
	$dynamic_count    = count( array_filter( $hooks_list, fn( $h ) => $h['dynamic'] ) );

	// --- Dry run ---
	if ( $dry_run ) {
		fwrite( STDERR, "\n=== Dry Run ===\n" );
		fwrite( STDERR, sprintf( "Total unique hooks: %d\n", $total ) );
		fwrite( STDERR, sprintf( "Actions:            %d\n", $action_count ) );
		fwrite( STDERR, sprintf( "Filters:            %d\n", $filter_count ) );
		fwrite( STDERR, sprintf( "Documented:         %d\n", $total - $undocumented ) );
		fwrite( STDERR, sprintf( "Undocumented:       %d\n", $undocumented ) );
		fwrite( STDERR, sprintf( "Dynamic:            %d\n", $dynamic_count ) );

		$by_segment = [];
		foreach ( $hooks_list as $h ) {
			$seg = infer_segment( $h['locations'][0]['file'] ?? '' );
			$by_segment[ $seg ] = ( $by_segment[ $seg ] ?? 0 ) + 1;
		}
		arsort( $by_segment );
		fwrite( STDERR, "\nBy segment:\n" );
		foreach ( $by_segment as $seg => $cnt ) {
			fwrite( STDERR, sprintf( "  %-40s %d\n", $seg, $cnt ) );
		}

		if ( $fail_undoc && $undocumented > 0 ) {
			return 1;
		}

		return 0;
	}

	// --- Phase 5: Write Markdown files — split by plugin repo ---
	// Build map: plugin_slug → plugin_root_path from scan_paths.
	$slug_to_root = [];
	$slug_to_root[ $primary_slug ] = $plugin_path;
	if ( ! empty( $pro_path ) ) {
		$slug_to_root[ basename( $pro_path ) ] = $pro_path;
	}
	if ( null !== $addon_raw ) {
		$addon_list_final = is_array( $addon_raw ) ? $addon_raw : array( $addon_raw );
		foreach ( $addon_list_final as $aa ) {
			$ar = realpath( rtrim( $aa, DIRECTORY_SEPARATOR ) );
			if ( false !== $ar ) {
				$slug_to_root[ basename( $ar ) ] = $ar;
			}
		}
	}

	// Track per-plugin stats.
	$per_plugin_index = [];
	$per_plugin_count = [];
	foreach ( $slug_to_root as $slug => $root ) {
		$per_plugin_index[ $slug ] = [];
		$per_plugin_count[ $slug ] = 0;
	}

	foreach ( $hooks_list as $hook ) {
		$slug     = $hook['plugin'];
		$segment  = infer_segment( $hook['locations'][0]['file'] ?? '' );
		$filename = hook_to_filename( $hook['name'] );

		// Determine output directory — fall back to primary plugin if slug not in map.
		$root = $slug_to_root[ $slug ] ?? $plugin_path;

		$docs_dir = $root . '/hook-docs/docs';
		$seg_dir  = $docs_dir . '/' . $segment;

		if ( ! is_dir( $seg_dir ) ) {
			mkdir( $seg_dir, 0755, true );
		}

		$md_path = $seg_dir . '/' . $filename;
		$md      = generate_hook_markdown( $hook );
		file_put_contents( $md_path, $md );

		if ( isset( $per_plugin_index[ $slug ] ) ) {
			$per_plugin_index[ $slug ][ $segment . '/' . $filename ] = array(
				'hook_id' => $hook['name'],
				'type'    => $hook['type'],
			);
			$per_plugin_count[ $slug ]++;
		}
	}

	// Write index.php per plugin.
	foreach ( $slug_to_root as $slug => $root ) {
		write_index_file(
			$root . '/hook-docs/index.php',
			$per_plugin_index[ $slug ] ?? [],
			$hooks_list,
			$slug
		);
	}

	// Report.
	fwrite( STDERR, "\n" );
	foreach ( $per_plugin_count as $slug => $cnt ) {
		$root = $slug_to_root[ $slug ] ?? '';
		fwrite( STDERR, sprintf( "Wrote %d hooks to %s/hook-docs/docs/\n", $cnt, $root ) );
	}
	fwrite( STDERR, sprintf(
		"Total: %d hooks (%d actions, %d filters, %d undocumented)\n",
		$total, $action_count, $filter_count, $undocumented
	) );

	if ( $fail_undoc && $undocumented > 0 ) {
		fwrite( STDERR, sprintf( "FAIL: %d undocumented hooks found.\n", $undocumented ) );
		return 1;
	}

	return 0;
}

// ---------------------------------------------------------------------------
// CLI
// ---------------------------------------------------------------------------

/**
 * Parse CLI arguments using getopt.
 *
 * @return array Parsed arguments.
 */
function parse_cli_args(): array {
	$options = getopt( 'h', array(
		'plugin-path:',
		'pro-path:',
		'addon-path:',
		'output:',
		'context-lines:',
		'dry-run',
		'fail-on-undocumented',
		'help',
	) );

	if ( isset( $options['h'] ) || isset( $options['help'] ) ) {
		fwrite( STDOUT, "Usage: php scan-hooks.php [OPTIONS]\n\n" );
		fwrite( STDOUT, "Options:\n" );
		fwrite( STDOUT, "  --plugin-path <path>      Path to free plugin root (required)\n" );
		fwrite( STDOUT, "  --pro-path <path>         Path to pro plugin root\n" );
		fwrite( STDOUT, "  --addon-path <path>       Path to addon plugin root (repeatable)\n" );
		fwrite( STDOUT, "  --output <dir>            Output directory (default: hook-docs)\n" );
		fwrite( STDOUT, "  --context-lines <n>       Lines of source context (default: 7)\n" );
		fwrite( STDOUT, "  --dry-run                 Print stats without writing files\n" );
		fwrite( STDOUT, "  --fail-on-undocumented    Exit code 1 if undocumented hooks exist\n" );
		fwrite( STDOUT, "  --help                    Show this help\n" );
		exit( 0 );
	}

	return $options;
}

// ---------------------------------------------------------------------------
// Ripgrep
// ---------------------------------------------------------------------------

/**
 * Execute ripgrep and return structured match data.
 *
 * @param string $path Directory to scan.
 *
 * @return array List of ripgrep match data arrays.
 */
function run_ripgrep( string $path ): array {
	$rg  = find_ripgrep();
	$cmd = sprintf(
		'%s --json -n "\\b(do_action|apply_filters)\\s*\\(" --glob "*.php" %s 2>/dev/null',
		escapeshellarg( $rg ),
		escapeshellarg( $path )
	);

	$raw_output = [];
	exec( $cmd, $raw_output );

	$matches = [];
	foreach ( $raw_output as $line ) {
		$data = json_decode( $line, true );
		if ( null !== $data && 'match' === ( $data['type'] ?? '' ) ) {
			$matches[] = $data['data'];
		}
	}

	return $matches;
}

// ---------------------------------------------------------------------------
// Match processing
// ---------------------------------------------------------------------------

/**
 * Process a single ripgrep match into a hook data structure.
 *
 * @param array $match_data    Ripgrep match data.
 * @param int   $context_lines Lines of context to extract.
 *
 * @return array|null Hook data or null if skipped.
 */
function process_match( array $match_data, int $context_lines = 7 ): ?array {
	$file      = $match_data['path']['absolute'] ?? $match_data['path']['text'];
	$file_rel  = $match_data['path']['text'];
	$line_text = $match_data['lines']['text'];
	$line_num  = (int) $match_data['line_number'];

	// FIX: Determine hook type via str_contains on the line, not submatch text.
	$hook_type = str_contains( $line_text, 'apply_filters' ) ? 'filter' : 'action';

	// Skip comments.
	$trimmed = ltrim( $line_text );
	if ( str_starts_with( $trimmed, '//' ) || str_starts_with( $trimmed, '*' ) || str_starts_with( $trimmed, '#' ) ) {
		return null;
	}

	// Skip deprecated and ref_array variants.
	if ( str_contains( $line_text, 'do_action_deprecated' )
		|| str_contains( $line_text, 'apply_filters_deprecated' )
		|| str_contains( $line_text, 'do_action_ref_array' ) ) {
		return null;
	}

	$full_call = extract_full_call( $file, $line_num, $hook_type );
	if ( null === $full_call ) {
		return null;
	}

	[ $hook_name, $is_dynamic ] = parse_hook_name( $full_call, $hook_type );
	if ( null === $hook_name || '' === $hook_name ) {
		return null;
	}

	$params_from_call = parse_call_params( $full_call, $hook_type );

	$docblock = find_docblock( $file, $line_num );
	$doc_info = null !== $docblock
		? parse_docblock( $docblock )
		: array( 'description' => '', 'since' => '', 'params' => [], 'return_type' => '', 'return_description' => '', 'examples' => [] );

	$params = merge_params( $doc_info['params'], $params_from_call );

	// Source context.
	$source_context = extract_source_context( $file, $line_num, $context_lines );

	return array(
		'name'               => $hook_name,
		'type'               => $hook_type,
		'plugin'             => infer_plugin( $file_rel ),
		'integration'        => infer_integration( $file_rel ),
		'since'              => $doc_info['since'],
		'description'        => $doc_info['description'],
		'params'             => $params,
		'return_type'        => $doc_info['return_type'],
		'return_description' => $doc_info['return_description'],
		'dynamic'            => $is_dynamic,
		'undocumented'       => null === $docblock,
		'examples'           => $doc_info['examples'],
		'source_context'     => $source_context,
		'related_hooks'      => [],
		'usage'              => [],
		'locations'          => array(
			array( 'file' => $file_rel, 'line' => $line_num ),
		),
	);
}

// ---------------------------------------------------------------------------
// Call extraction & parsing (ported from prototype)
// ---------------------------------------------------------------------------

/**
 * Read the full function call from the file, handling multi-line calls.
 *
 * @param string $file      File path.
 * @param int    $line_num  1-based line number.
 * @param string $hook_type 'action' or 'filter'.
 *
 * @return string|null The full call text or null.
 */
function extract_full_call( string $file, int $line_num, string $hook_type ): ?string {
	$lines   = get_file_lines( $file );
	$start   = $line_num - 1;
	$call    = '';
	$depth   = 0;
	$started = false;

	for ( $i = $start, $end = min( $start + 30, count( $lines ) ); $i < $end; $i++ ) {
		$line = $lines[ $i ];
		$call .= $line . "\n";
		$len  = strlen( $line );

		for ( $j = 0; $j < $len; $j++ ) {
			$char = $line[ $j ];

			if ( "'" === $char || '"' === $char ) {
				$quote = $char;
				$j++;
				while ( $j < $len ) {
					if ( '\\' === $line[ $j ] ) {
						$j++;
					} elseif ( $line[ $j ] === $quote ) {
						break;
					}
					$j++;
				}
				continue;
			}

			if ( '(' === $char ) {
				if ( ! $started ) {
					$started = true;
				}
				$depth++;
			} elseif ( ')' === $char ) {
				$depth--;
				if ( $started && 0 === $depth ) {
					return $call;
				}
			}
		}
	}

	return $started ? $call : null;
}

/**
 * Extract the hook name from the full call text.
 *
 * @param string $call_text Full function call.
 * @param string $hook_type 'action' or 'filter'.
 *
 * @return array [ hook_name|null, is_dynamic ].
 */
function parse_hook_name( string $call_text, string $hook_type ): array {
	$func = 'filter' === $hook_type ? 'apply_filters' : 'do_action';

	$pos = strpos( $call_text, $func );
	if ( false === $pos ) {
		return array( null, false );
	}

	$paren_pos = strpos( $call_text, '(', $pos );
	if ( false === $paren_pos ) {
		return array( null, false );
	}

	$inner = extract_inner_parens( $call_text, $paren_pos );
	if ( null === $inner ) {
		return array( null, false );
	}

	$arguments = split_top_level( $inner, ',' );
	$first_arg = trim( $arguments[0] ?? '' );

	if ( '' === $first_arg ) {
		return array( null, false );
	}

	// Simple string literal.
	if ( preg_match( '/^[\'"]([^\'"]+)[\'"]$/', $first_arg, $m ) ) {
		return array( $m[1], false );
	}

	// Dynamic hook.
	return array( resolve_dynamic_name( $first_arg ), true );
}

/**
 * Resolve a dynamic hook name expression.
 *
 * @param string $expr Raw PHP expression.
 *
 * @return string Hook name with {dynamic} placeholders.
 */
function resolve_dynamic_name( string $expr ): string {
	$parts  = preg_split( '/\s*\.\s*/', $expr );
	$result = '';

	foreach ( $parts as $part ) {
		$part = trim( $part );
		if ( preg_match( '/^[\'"](.+)[\'"]$/', $part, $m ) ) {
			$result .= $m[1];
		} else {
			$result .= '{dynamic}';
		}
	}

	return $result;
}

/**
 * Extract content inside matched parentheses.
 *
 * @param string $text  Text.
 * @param int    $start Position of opening paren.
 *
 * @return string|null Inner content or null.
 */
function extract_inner_parens( string $text, int $start ): ?string {
	$depth = 0;
	$len   = strlen( $text );
	$begin = null;

	for ( $i = $start; $i < $len; $i++ ) {
		$ch = $text[ $i ];

		if ( "'" === $ch || '"' === $ch ) {
			$q = $ch;
			$i++;
			while ( $i < $len ) {
				if ( '\\' === $text[ $i ] ) {
					$i++;
				} elseif ( $text[ $i ] === $q ) {
					break;
				}
				$i++;
			}
			continue;
		}

		if ( '(' === $ch ) {
			$depth++;
			if ( 1 === $depth ) {
				$begin = $i + 1;
			}
		} elseif ( ')' === $ch ) {
			$depth--;
			if ( 0 === $depth && null !== $begin ) {
				return substr( $text, $begin, $i - $begin );
			}
		}
	}

	return null;
}

/**
 * Split string by delimiter at top nesting level.
 *
 * @param string $text      Text to split.
 * @param string $delimiter Single character delimiter.
 *
 * @return array Parts.
 */
function split_top_level( string $text, string $delimiter ): array {
	$parts   = [];
	$current = '';
	$depth   = 0;
	$len     = strlen( $text );

	for ( $i = 0; $i < $len; $i++ ) {
		$ch = $text[ $i ];

		if ( "'" === $ch || '"' === $ch ) {
			$q        = $ch;
			$current .= $ch;
			$i++;
			while ( $i < $len ) {
				$current .= $text[ $i ];
				if ( '\\' === $text[ $i ] ) {
					$i++;
					if ( $i < $len ) {
						$current .= $text[ $i ];
					}
				} elseif ( $text[ $i ] === $q ) {
					break;
				}
				$i++;
			}
			continue;
		}

		if ( '(' === $ch || '[' === $ch || '{' === $ch ) {
			$depth++;
		} elseif ( ')' === $ch || ']' === $ch || '}' === $ch ) {
			$depth--;
		}

		if ( $ch === $delimiter && 0 === $depth ) {
			$parts[] = $current;
			$current = '';
			continue;
		}

		$current .= $ch;
	}

	if ( '' !== $current ) {
		$parts[] = $current;
	}

	return $parts;
}

/**
 * Extract parameters from a hook call (after the hook name).
 *
 * @param string $call_text Full function call.
 * @param string $hook_type 'action' or 'filter'.
 *
 * @return array Raw parameter expressions.
 */
function parse_call_params( string $call_text, string $hook_type ): array {
	$func      = 'filter' === $hook_type ? 'apply_filters' : 'do_action';
	$pos       = strpos( $call_text, $func );
	$paren_pos = strpos( $call_text, '(', $pos );
	$inner     = extract_inner_parens( $call_text, $paren_pos );

	if ( null === $inner ) {
		return [];
	}

	$arguments = split_top_level( $inner, ',' );
	array_shift( $arguments ); // Remove hook name.

	$params = [];
	foreach ( $arguments as $arg ) {
		$arg = trim( $arg );
		if ( '' !== $arg ) {
			$params[] = $arg;
		}
	}

	return $params;
}

// ---------------------------------------------------------------------------
// Docblock parsing (enhanced)
// ---------------------------------------------------------------------------

/**
 * Find the docblock immediately above a hook call.
 *
 * @param string $file     File path.
 * @param int    $line_num 1-based line number.
 *
 * @return string|null Raw docblock text or null.
 */
function find_docblock( string $file, int $line_num ): ?string {
	$lines  = get_file_lines( $file );
	$cursor = $line_num - 2;

	while ( $cursor >= 0 && '' === trim( $lines[ $cursor ] ) ) {
		$cursor--;
	}

	if ( $cursor < 0 ) {
		return null;
	}

	if ( ! str_contains( rtrim( $lines[ $cursor ] ), '*/' ) ) {
		return null;
	}

	$end_line   = $cursor;
	$max_search = 60;

	while ( $cursor >= 0 && $max_search > 0 ) {
		if ( str_contains( $lines[ $cursor ], '/**' ) ) {
			return implode( "\n", array_slice( $lines, $cursor, $end_line - $cursor + 1 ) );
		}
		$cursor--;
		$max_search--;
	}

	return null;
}

/**
 * Parse a docblock into structured data.
 *
 * Enhanced: extracts @example blocks, @type sub-keys, @return type+description.
 *
 * @param string $docblock Raw docblock text.
 *
 * @return array Structured docblock data.
 */
function parse_docblock( string $docblock ): array {
	$result = array(
		'description'        => '',
		'since'              => '',
		'params'             => [],
		'return_type'        => '',
		'return_description' => '',
		'examples'           => [],
	);

	$clean = preg_replace( '#^\s*/?\*+/?\s?#m', '', $docblock );
	$lines = array_map( 'trim', explode( "\n", $clean ) );
	$lines = array_filter( $lines, fn( $l ) => '' !== $l );
	$lines = array_values( $lines );

	// Description = lines before the first @tag.
	$desc_lines = [];
	$tag_start  = count( $lines );
	foreach ( $lines as $idx => $line ) {
		if ( str_starts_with( $line, '@' ) ) {
			$tag_start = $idx;
			break;
		}
		$desc_lines[] = $line;
	}
	$result['description'] = trim( implode( ' ', $desc_lines ) );

	// Parse tags.
	for ( $i = $tag_start, $count = count( $lines ); $i < $count; $i++ ) {
		$line = $lines[ $i ];

		if ( preg_match( '/@since\s+(.+)/', $line, $m ) ) {
			$result['since'] = trim( $m[1] );

		} elseif ( preg_match( '/@return\s+(\S+)\s*(.*)/', $line, $m ) ) {
			$result['return_type']        = trim( $m[1] );
			$result['return_description'] = trim( $m[2] );
			// Gather continuation lines for return description.
			while ( $i + 1 < $count && ! str_starts_with( $lines[ $i + 1 ], '@' ) ) {
				$i++;
				$result['return_description'] .= ' ' . trim( $lines[ $i ] );
			}
			$result['return_description'] = trim( $result['return_description'] );

		} elseif ( preg_match( '/@param\s+(\S+)\s+(\$\S+)\s*(.*)/', $line, $m ) ) {
			$param = array(
				'name'        => $m[2],
				'type'        => $m[1],
				'description' => trim( $m[3] ),
				'sub_keys'    => null,
			);

			// Gather continuation lines + detect @type sub-keys.
			$sub_keys = [];
			while ( $i + 1 < $count && ! str_starts_with( $lines[ $i + 1 ], '@param' ) && ! str_starts_with( $lines[ $i + 1 ], '@since' ) && ! str_starts_with( $lines[ $i + 1 ], '@return' ) && ! str_starts_with( $lines[ $i + 1 ], '@example' ) ) {
				$i++;
				$next_line = $lines[ $i ];

				// WordPress-style sub-key: @type int $key Description
				if ( preg_match( '/@type\s+(\S+)\s+(\$?\S+)\s*(.*)/', $next_line, $sk ) ) {
					$sub_keys[] = array(
						'name'        => ltrim( $sk[2], '$' ),
						'type'        => $sk[1],
						'description' => trim( $sk[3] ),
					);
				} elseif ( '{' !== trim( $next_line ) && '}' !== trim( $next_line ) ) {
					$param['description'] .= ' ' . trim( $next_line );
				}
			}

			$param['description'] = trim( $param['description'] );
			if ( ! empty( $sub_keys ) ) {
				$param['sub_keys'] = $sub_keys;
			}

			$result['params'][] = $param;

		} elseif ( str_starts_with( $line, '@example' ) ) {
			// Collect @example block until next @tag or end.
			$example_lines = [];
			$in_fence      = false;
			while ( $i + 1 < $count && ! str_starts_with( $lines[ $i + 1 ], '@' ) ) {
				$i++;
				$ex_line = $lines[ $i ];
				if ( str_starts_with( trim( $ex_line ), '```' ) ) {
					$in_fence = ! $in_fence;
					continue;
				}
				$example_lines[] = $ex_line;
			}
			$example_text = trim( implode( "\n", $example_lines ) );
			if ( '' !== $example_text ) {
				$result['examples'][] = $example_text;
			}
		}
	}

	return $result;
}

/**
 * Merge docblock params with call-extracted params.
 *
 * @param array $doc_params  Docblock params.
 * @param array $call_params Call params.
 *
 * @return array Merged params.
 */
function merge_params( array $doc_params, array $call_params ): array {
	if ( ! empty( $doc_params ) ) {
		return $doc_params;
	}

	$params = [];
	foreach ( $call_params as $expr ) {
		$name = $expr;
		if ( preg_match( '/(\$[\w]+)/', $expr, $m ) ) {
			$name = $m[1];
		}
		$params[] = array(
			'name'        => $name,
			'type'        => 'mixed',
			'description' => '',
			'sub_keys'    => null,
		);
	}

	return $params;
}

// ---------------------------------------------------------------------------
// Source context
// ---------------------------------------------------------------------------

/**
 * Extract N lines of source context around a hook call.
 *
 * @param string $file      File path.
 * @param int    $line_num  1-based line number.
 * @param int    $context   Lines above and below.
 *
 * @return string The source context code.
 */
function extract_source_context( string $file, int $line_num, int $context = 7 ): string {
	$lines = get_file_lines( $file );
	$start = max( 0, $line_num - 1 - $context );
	$end   = min( count( $lines ) - 1, $line_num - 1 + $context );

	$snippet = array_slice( $lines, $start, $end - $start + 1 );

	return implode( "\n", $snippet );
}

// ---------------------------------------------------------------------------
// Related hooks detection
// ---------------------------------------------------------------------------

/**
 * Detect related hooks via proximity and name patterns.
 *
 * @param array $hooks All hooks keyed by name.
 *
 * @return array Hooks with related_hooks populated.
 */
function detect_related_hooks( array $hooks ): array {
	// Build file+line index for proximity checks.
	$location_index = [];
	foreach ( $hooks as $name => $hook ) {
		foreach ( $hook['locations'] as $loc ) {
			$key = $loc['file'];
			$location_index[ $key ][] = array(
				'name' => $name,
				'line' => $loc['line'],
			);
		}
	}

	// Proximity: hooks in the same file within 30 lines.
	foreach ( $location_index as $file => $entries ) {
		$entry_count = count( $entries );
		for ( $i = 0; $i < $entry_count; $i++ ) {
			for ( $j = $i + 1; $j < $entry_count; $j++ ) {
				$distance = abs( $entries[ $i ]['line'] - $entries[ $j ]['line'] );
				if ( $distance <= 30 && $entries[ $i ]['name'] !== $entries[ $j ]['name'] ) {
					$hooks[ $entries[ $i ]['name'] ]['related_hooks'][] = array(
						'name'         => $entries[ $j ]['name'],
						'relationship' => 'fires_nearby',
					);
					$hooks[ $entries[ $j ]['name'] ]['related_hooks'][] = array(
						'name'         => $entries[ $i ]['name'],
						'relationship' => 'fires_nearby',
					);
				}
			}
		}
	}

	// Name patterns: before_X / after_X, pre_X / post_X.
	$hook_names = array_keys( $hooks );
	foreach ( $hook_names as $name ) {
		$pairs = array(
			array( '_before_', '_after_' ),
			array( '_pre_', '_post_' ),
		);

		foreach ( $pairs as list( $prefix, $suffix ) ) {
			if ( str_contains( $name, $prefix ) ) {
				$partner = str_replace( $prefix, $suffix, $name );
				if ( isset( $hooks[ $partner ] ) ) {
					$hooks[ $name ]['related_hooks'][]    = array(
						'name'         => $partner,
						'relationship' => 'paired_after',
					);
					$hooks[ $partner ]['related_hooks'][] = array(
						'name'         => $name,
						'relationship' => 'paired_before',
					);
				}
			}
		}
	}

	// Deduplicate related hooks.
	foreach ( $hooks as $name => &$hook ) {
		$seen = [];
		$unique = [];
		foreach ( $hook['related_hooks'] as $rel ) {
			$key = $rel['name'] . '|' . $rel['relationship'];
			if ( ! isset( $seen[ $key ] ) ) {
				$seen[ $key ] = true;
				$unique[]     = $rel;
			}
		}
		$hook['related_hooks'] = $unique;
	}
	unset( $hook );

	return $hooks;
}

// ---------------------------------------------------------------------------
// Internal usage scan
// ---------------------------------------------------------------------------

/**
 * Scan for add_action/add_filter calls on automator_ hooks.
 *
 * @param array $paths Paths to scan.
 *
 * @return array Map of hook_name => usage entries.
 */
function scan_internal_usage( array $paths, array $path_strip_map = [] ): array {
	$rg    = find_ripgrep();
	$usage = [];

	foreach ( $paths as $path ) {
		$pattern = '(add_action|add_filter)\s*\(\s*[' . "'" . '"](automator_[^' . "'" . '"]+)';
		$cmd     = sprintf(
			'%s --json -n %s --glob "*.php" %s 2>/dev/null',
			escapeshellarg( $rg ),
			escapeshellarg( $pattern ),
			escapeshellarg( $path )
		);

		$output = [];
		exec( $cmd, $output );

		foreach ( $output as $line ) {
			$data = json_decode( $line, true );
			if ( null === $data || 'match' !== ( $data['type'] ?? '' ) ) {
				continue;
			}

			$match     = $data['data'];
			$file      = $match['path']['text'];
			// Make path relative.
			foreach ( $path_strip_map as $abs_prefix => $rel_prefix ) {
				if ( str_starts_with( $file, $abs_prefix ) ) {
					$file = $rel_prefix . substr( $file, strlen( $abs_prefix ) );
					break;
				}
			}
			$line_num  = (int) $match['line_number'];
			$line_text = trim( $match['lines']['text'] );

			// Extract hook name from the line.
			if ( preg_match( "/(add_action|add_filter)\s*\(\s*['\"]([^'\"]+)/", $line_text, $m ) ) {
				$hook_name = $m[2];

				// Extract callback name.
				$callback = '';
				if ( preg_match( "/,\s*['\"](\w+)['\"]/", $line_text, $cb ) ) {
					$callback = $cb[1];
				} elseif ( preg_match( "/,\s*array\s*\(\s*\\$\w+\s*,\s*['\"](\w+)['\"]/", $line_text, $cb ) ) {
					$callback = $cb[1];
				}

				$usage[ $hook_name ][] = array(
					'file'     => $file,
					'line'     => $line_num,
					'callback' => $callback,
					'code'     => $line_text,
				);
			}
		}
	}

	return $usage;
}

// ---------------------------------------------------------------------------
// Markdown generation
// ---------------------------------------------------------------------------

/**
 * Generate a Markdown document for a single hook with YAML frontmatter and placeholders.
 *
 * @param array $hook Hook data.
 *
 * @return string Markdown content.
 */
function generate_hook_markdown( array $hook ): string {
	$name          = $hook['name'];
	$type          = $hook['type'];
	$plugin        = $hook['plugin'];
	$integration   = $hook['integration'];
	$since         = $hook['since'] ?: '*Unknown*';
	$description   = $hook['description'];
	$params        = $hook['params'];
	$return_type   = $hook['return_type'] ?? '';
	$return_desc   = $hook['return_description'] ?? '';
	$dynamic       = $hook['dynamic'];
	$undocumented  = $hook['undocumented'];
	$examples      = $hook['examples'] ?? [];
	$source_ctx    = $hook['source_context'] ?? '';
	$related       = $hook['related_hooks'] ?? [];
	$usage_arr     = $hook['usage'] ?? [];
	$locations     = $hook['locations'] ?? [];

	$wp_func    = 'filter' === $type ? 'add_filter' : 'add_action';
	$type_label = ucfirst( $type );
	$plugin_label = 'uncanny-automator-pro' === $plugin ? 'Uncanny Automator Pro' : 'Uncanny Automator';
	$integ_label  = ucwords( str_replace( array( '-', '_' ), ' ', $integration ) );
	$param_count  = count( $params );
	$accepted     = max( $param_count, 1 );

	$md = '';

	// --- YAML frontmatter ---
	$md .= "---\n";
	$md .= "hook_id: {$name}\n";
	$md .= "hook_type: {$type}\n";
	$md .= "plugin: {$plugin}\n";
	$md .= "integration: {$integration}\n";
	$md .= 'since: "' . addcslashes( $hook['since'] ?: '', '"' ) . "\"\n";
	$md .= 'dynamic: ' . ( $dynamic ? 'true' : 'false' ) . "\n";
	$md .= 'undocumented: ' . ( $undocumented ? 'true' : 'false' ) . "\n";
	$md .= "---\n\n";

	// --- Title + meta ---
	$md .= "# `{$name}`\n\n";
	$md .= "**Type:** {$type_label}  \n";
	$md .= "**Plugin:** {$plugin_label}  \n";
	$md .= "**Integration:** {$integ_label}  \n";
	$md .= "**Since:** {$since}\n\n";

	if ( $dynamic ) {
		$md .= "> **Note:** This is a dynamic hook. The actual hook name is constructed at runtime.\n\n";
	}

	// --- Readable description ---
	if ( $description ) {
		$md .= "{$description}\n\n";
		$md .= "{{READABLE_DESCRIPTION}}\n\n";
	} else {
		$md .= "{{READABLE_DESCRIPTION}}\n\n";
	}

	$md .= "---\n\n";

	// --- Technical description ---
	$md .= "## Description\n\n";
	$md .= "{{TECHNICAL_DESCRIPTION}}\n\n";
	$md .= "---\n\n";

	// --- Usage ---
	$md .= "## Usage\n\n";
	$md .= "```php\n";
	$md .= "{$wp_func}( '{$name}', 'your_function_name', 10, {$accepted} );\n";
	$md .= "```\n\n";
	$md .= "---\n\n";

	// --- Parameters ---
	if ( ! empty( $params ) ) {
		$md .= "## Parameters\n\n";
		foreach ( $params as $idx => $param ) {
			$p_name = $param['name'] ?? '';
			$p_type = $param['type'] ?? 'mixed';
			$p_desc = $param['description'] ?? '';

			if ( '' === $p_desc && $undocumented ) {
				$p_desc = "{{PARAM_DESCRIPTION_{$idx}}}";
			}

			$md .= "- **{$p_name}** `{$p_type}`  \n";
			$md .= "  {$p_desc}\n\n";

			// Sub-keys table.
			if ( ! empty( $param['sub_keys'] ) ) {
				$md .= "  | Key | Type | Description |\n";
				$md .= "  |-----|------|-------------|\n";
				foreach ( $param['sub_keys'] as $sk ) {
					$md .= sprintf(
						"  | `%s` | `%s` | %s |\n",
						$sk['name'] ?? '',
						$sk['type'] ?? 'mixed',
						$sk['description'] ?? ''
					);
				}
				$md .= "\n";
			}
		}
		$md .= "---\n\n";
	}

	// --- Return value (filters only) ---
	if ( 'filter' === $type ) {
		$md .= "## Return Value\n\n";
		if ( $return_type ) {
			$md .= "`{$return_type}` {$return_desc}\n\n";
		} elseif ( $undocumented ) {
			$md .= "{{RETURN_DESCRIPTION}}\n\n";
		} else {
			$md .= "The filtered value.\n\n";
		}
		$md .= "---\n\n";
	}

	// --- Examples ---
	$md .= "## Examples\n\n";
	if ( ! empty( $examples ) ) {
		foreach ( $examples as $ex ) {
			$md .= "```php\n{$ex}\n```\n\n";
		}
	}
	$md .= "{{USAGE_EXAMPLE}}\n\n";
	$md .= "---\n\n";

	// --- Placement ---
	$md .= "## Placement\n\n";
	$md .= "This code should be placed in the `functions.php` file of your active theme, a custom plugin, or using a code snippets plugin.\n\n";
	$md .= "---\n\n";

	// --- Source Code ---
	if ( $source_ctx ) {
		$md .= "## Source Code\n\n";
		if ( ! empty( $locations ) ) {
			foreach ( $locations as $loc ) {
				$md .= sprintf( "`%s:%d`\n\n", $loc['file'] ?? '', $loc['line'] ?? 0 );
			}
		}
		$md .= "```php\n{$source_ctx}\n```\n\n";
		$md .= "---\n\n";
	}

	// --- Related Hooks ---
	if ( ! empty( $related ) ) {
		$md .= "## Related Hooks\n\n";
		foreach ( $related as $rel ) {
			$rel_filename = hook_to_filename( $rel['name'] ?? '' );
			$rel_rel      = $rel['relationship'] ?? '';
			$md .= "- [`{$rel['name']}`]({$rel_filename}) — {$rel_rel}\n";
		}
		$md .= "\n---\n\n";
	}

	// --- Internal Usage ---
	if ( ! empty( $usage_arr ) ) {
		$md .= "## Internal Usage\n\n";
		foreach ( $usage_arr as $u ) {
			$md .= sprintf( "Found in `%s:%d`:\n\n", $u['file'] ?? '', $u['line'] ?? 0 );
			if ( ! empty( $u['code'] ) ) {
				$md .= "```php\n{$u['code']}\n```\n\n";
			}
		}
	}

	return $md;
}

// ---------------------------------------------------------------------------
// Path inference
// ---------------------------------------------------------------------------

/**
 * Infer integration name from file path.
 *
 * @param string $filepath File path.
 *
 * @return string Integration name or 'core'.
 */
function infer_integration( string $filepath ): string {
	if ( preg_match( '#/integrations/([^/]+)/#', $filepath, $m ) ) {
		return $m[1];
	}

	if ( str_contains( $filepath, '/api/' ) ) {
		return 'api';
	}

	return 'core';
}

/**
 * Infer source plugin from file path.
 *
 * @param string $filepath File path.
 *
 * @return string Plugin slug.
 */
/**
 * Plugin slug map — populated at runtime from scan paths.
 * Maps the relative path prefix (from path_strip_map) → plugin slug.
 * For the primary plugin, the prefix is '' (empty). For others it's 'plugin-name/'.
 *
 * @var array
 */
$plugin_slug_map = [];

/**
 * Register a plugin's relative prefix and slug.
 *
 * @param string $rel_prefix The relative path prefix (e.g., '' for primary, 'uncanny-automator-pro/' for pro).
 * @param string $slug       The plugin slug.
 *
 * @return void
 */
function register_plugin_slug( string $rel_prefix, string $slug ): void {
	global $plugin_slug_map;
	$plugin_slug_map[ $rel_prefix ] = $slug;
}

/**
 * Infer source plugin from a relative file path.
 *
 * Matches against registered prefixes. Longer prefixes checked first
 * to avoid 'uncanny-automator' matching 'uncanny-automator-pro/' paths.
 *
 * @param string $filepath Relative file path.
 *
 * @return string Plugin slug.
 */
function infer_plugin( string $filepath ): string {
	global $plugin_slug_map;

	// Sort by prefix length descending — longest match wins.
	$prefixes = array_keys( $plugin_slug_map );
	usort( $prefixes, fn( $a, $b ) => strlen( $b ) - strlen( $a ) );

	foreach ( $prefixes as $prefix ) {
		if ( '' === $prefix || str_starts_with( $filepath, $prefix ) ) {
			// For empty prefix, only match if no other prefix matched.
			if ( '' === $prefix ) {
				return $plugin_slug_map[ $prefix ];
			}
			return $plugin_slug_map[ $prefix ];
		}
	}

	return 'unknown';
}

/**
 * Infer the output segment (folder) from a file path.
 *
 * @param string $filepath File path.
 *
 * @return string Segment path like 'core', 'api', 'integrations/gravity-forms'.
 */
function infer_segment( string $filepath ): string {
	// No pro- prefix needed — pro hooks go to a separate repo directory.
	if ( preg_match( '#/integrations/([^/]+)/#', $filepath, $m ) ) {
		return 'integrations/' . $m[1];
	}

	if ( str_contains( $filepath, '/api/' ) ) {
		return 'api';
	}

	return 'core';
}

/**
 * Write an index.php manifest for a plugin's hook-docs directory.
 *
 * @param string $path       Output path for index.php.
 * @param array  $entries    File index entries for this plugin.
 * @param array  $all_hooks  All hooks (for counting).
 * @param string $plugin     Plugin slug to filter counts.
 *
 * @return void
 */
function write_index_file( string $path, array $entries, array $all_hooks, string $plugin ): void {
	$plugin_hooks  = array_filter( $all_hooks, fn( $h ) => $plugin === $h['plugin'] );
	$total         = count( $plugin_hooks );
	$actions       = count( array_filter( $plugin_hooks, fn( $h ) => 'action' === $h['type'] ) );
	$filters       = count( array_filter( $plugin_hooks, fn( $h ) => 'filter' === $h['type'] ) );
	$undocumented  = count( array_filter( $plugin_hooks, fn( $h ) => $h['undocumented'] ) );

	$dir = dirname( $path );
	if ( ! is_dir( $dir ) ) {
		mkdir( $dir, 0755, true );
	}

	$content  = "<?php\n// Generated by scan-hooks.php — do not edit manually.\n";
	$content .= '// Updated: ' . gmdate( 'c' ) . "\n";
	$content .= 'return ' . var_export( array(
		'generated_at'  => gmdate( 'c' ),
		'plugin'        => $plugin,
		'total_hooks'   => $total,
		'total_actions' => $actions,
		'total_filters' => $filters,
		'undocumented'  => $undocumented,
		'files'         => $entries,
	), true ) . ";\n";

	file_put_contents( $path, $content );
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Convert hook name to a safe filename.
 *
 * @param string $hook_name Hook name.
 *
 * @return string Filename ending in .md.
 */
function hook_to_filename( string $hook_name ): string {
	$name = preg_replace( '/[^a-z0-9]+/i', '-', $hook_name );
	$name = trim( strtolower( $name ), '-' );

	return $name . '.md';
}

/**
 * Locate the ripgrep binary.
 *
 * @return string|null Path to rg or null.
 */
function find_ripgrep(): ?string {
	static $cached = false;
	static $path   = null;

	if ( false !== $cached ) {
		return $path;
	}

	$cached = true;

	$candidates = array(
		'/opt/homebrew/bin/rg',
		'/usr/local/bin/rg',
		'/usr/bin/rg',
		'/snap/bin/rg',
	);

	foreach ( $candidates as $candidate ) {
		if ( is_executable( $candidate ) ) {
			$path = $candidate;
			return $path;
		}
	}

	$output = [];
	exec( 'command -v rg 2>/dev/null || which rg 2>/dev/null', $output, $code );
	if ( 0 === $code && ! empty( $output[0] ) && is_executable( $output[0] ) ) {
		$path = $output[0];
		return $path;
	}

	return null;
}

/**
 * Get file lines with caching.
 *
 * @param string $file File path.
 *
 * @return array Lines.
 */
function get_file_lines( string $file ): array {
	global $file_cache;

	if ( ! isset( $file_cache[ $file ] ) ) {
		if ( 100 < count( $file_cache ) ) {
			$file_cache = array_slice( $file_cache, -50, null, true );
		}
		$content = file_get_contents( $file );
		if ( false === $content ) {
			return [];
		}
		$file_cache[ $file ] = explode( "\n", $content );
	}

	return $file_cache[ $file ];
}

// ---------------------------------------------------------------------------
// Run
// ---------------------------------------------------------------------------

exit( main( $argv ) );
