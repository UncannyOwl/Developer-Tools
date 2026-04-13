#!/usr/bin/env php
<?php
/**
 * Hook Documentation Sync — WP-CLI Command.
 *
 * Reads enriched Markdown hook files and UPSERTs them into the WordPress
 * automator_hook CPT at the target site. Designed to run via WP-CLI.
 *
 * Usage (WP-CLI, from the docs site root):
 *   wp eval-file /path/to/sync-hooks.php -- --input /path/to/hook-docs/docs
 *
 * Or register as a WP-CLI command by requiring this file from the plugin.
 *
 * Usage (standalone, requires wp-load.php path):
 *   php bin/sync-hooks.php --input hook-docs/docs --wp-path /path/to/automator-docs
 *
 * @package Automator_Dev_Tools
 */

/**
 * Main sync entry point.
 *
 * @param array $argv CLI arguments.
 *
 * @return int Exit code.
 */
function sync_hooks_main( array $argv ): int {
	$options = getopt( 'h', array(
		'input:',
		'wp-path:',
		'dry-run',
		'help',
	) );

	if ( isset( $options['h'] ) || isset( $options['help'] ) ) {
		fwrite( STDOUT, "Usage: php sync-hooks.php --input <docs-dir> --wp-path <wordpress-root>\n\n" );
		fwrite( STDOUT, "Options:\n" );
		fwrite( STDOUT, "  --input <dir>       Path to enriched hook-docs/docs/ directory (required)\n" );
		fwrite( STDOUT, "  --wp-path <dir>     Path to WordPress installation root (required unless in WP-CLI)\n" );
		fwrite( STDOUT, "  --dry-run           Show what would be synced without writing\n" );
		fwrite( STDOUT, "  --help              Show this help\n" );
		return 0;
	}

	$input_dir = $options['input'] ?? null;
	if ( null === $input_dir || ! is_dir( $input_dir ) ) {
		fwrite( STDERR, "Error: --input directory not found: " . ( $input_dir ?? '(none)' ) . "\n" );
		return 1;
	}

	// Bootstrap WordPress if not already loaded.
	if ( ! function_exists( 'wp_insert_post' ) ) {
		$wp_path = $options['wp-path'] ?? null;
		if ( null === $wp_path ) {
			fwrite( STDERR, "Error: --wp-path is required when not running under WP-CLI.\n" );
			return 1;
		}
		$wp_load = rtrim( $wp_path, '/' ) . '/wp-load.php';
		if ( ! file_exists( $wp_load ) ) {
			fwrite( STDERR, "Error: wp-load.php not found at: {$wp_load}\n" );
			return 1;
		}
		require_once $wp_load;
	}

	$dry_run = isset( $options['dry-run'] );

	fwrite( STDERR, "Scanning for hook files…\n" );

	// Collect all .md files.
	$files = glob_recursive( $input_dir, '*.md' );
	fwrite( STDERR, sprintf( "  Found %d .md files\n", count( $files ) ) );

	$stats = array(
		'created'   => 0,
		'updated'   => 0,
		'skipped'   => 0,
		'failed'    => 0,
		'total'     => count( $files ),
	);

	foreach ( $files as $file_path ) {
		$result = sync_single_hook( $file_path, $dry_run );

		switch ( $result ) {
			case 'created':
				$stats['created']++;
				break;
			case 'updated':
				$stats['updated']++;
				break;
			case 'skipped':
				$stats['skipped']++;
				break;
			default:
				$stats['failed']++;
				break;
		}

		$done = $stats['created'] + $stats['updated'] + $stats['skipped'] + $stats['failed'];
		if ( 0 === $done % 50 ) {
			fwrite( STDERR, sprintf( "  Progress: %d/%d\n", $done, $stats['total'] ) );
		}
	}

	fwrite( STDERR, sprintf(
		"\nSync complete: %d created, %d updated, %d skipped, %d failed (of %d total)\n",
		$stats['created'],
		$stats['updated'],
		$stats['skipped'],
		$stats['failed'],
		$stats['total']
	) );

	return 0 < $stats['failed'] ? 1 : 0;
}

// ---------------------------------------------------------------------------
// Single hook sync
// ---------------------------------------------------------------------------

/**
 * Sync a single hook markdown file to WordPress.
 *
 * @param string $file_path Path to the .md file.
 * @param bool   $dry_run   If true, don't write.
 *
 * @return string 'created', 'updated', 'skipped', or 'failed'.
 */
function sync_single_hook( string $file_path, bool $dry_run = false ): string {
	$content = file_get_contents( $file_path );
	if ( false === $content ) {
		fwrite( STDERR, "  Error reading: {$file_path}\n" );
		return 'failed';
	}

	// Parse frontmatter.
	$meta = parse_yaml_frontmatter( $content );
	$hook_id = $meta['hook_id'] ?? '';

	if ( '' === $hook_id ) {
		fwrite( STDERR, "  Skipping (no hook_id): {$file_path}\n" );
		return 'failed';
	}

	// Parse markdown sections.
	$sections = parse_markdown_sections( $content );

	// Build the post slug from hook_id.
	$post_slug = sanitize_title( str_replace( array( '{', '}' ), '', $hook_id ) );

	// Build pipeline data.
	$pipeline_meta = build_pipeline_meta( $meta, $sections );
	$pipeline_hash = md5( serialize( $pipeline_meta ) );

	// Check if post already exists.
	$existing = get_posts( array(
		'post_type'      => 'automator_hook',
		'name'           => $post_slug,
		'posts_per_page' => 1,
		'post_status'    => 'any',
	) );

	$existing_post = ! empty( $existing ) ? $existing[0] : null;

	if ( $dry_run ) {
		$action = null !== $existing_post ? 'would update' : 'would create';
		fwrite( STDERR, sprintf( "  [dry-run] %s: %s\n", $action, $hook_id ) );
		return null !== $existing_post ? 'updated' : 'created';
	}

	if ( null !== $existing_post ) {
		return update_existing_hook( $existing_post, $pipeline_meta, $pipeline_hash, $meta, $sections );
	}

	return create_new_hook( $post_slug, $pipeline_meta, $pipeline_hash, $meta, $sections );
}

/**
 * Create a new hook post.
 *
 * @param string $post_slug     Post slug.
 * @param array  $pipeline_meta Pipeline-owned meta fields.
 * @param string $pipeline_hash Hash of pipeline meta.
 * @param array  $meta          YAML frontmatter.
 * @param array  $sections      Parsed markdown sections.
 *
 * @return string 'created' or 'failed'.
 */
function create_new_hook( string $post_slug, array $pipeline_meta, string $pipeline_hash, array $meta, array $sections ): string {
	$hook_id = $meta['hook_id'] ?? '';

	$post_id = wp_insert_post( array(
		'post_type'    => 'automator_hook',
		'post_title'   => $hook_id,
		'post_name'    => $post_slug,
		'post_status'  => 'publish',
		'post_content' => '',
	), true );

	if ( is_wp_error( $post_id ) ) {
		fwrite( STDERR, sprintf( "  Error creating %s: %s\n", $hook_id, $post_id->get_error_message() ) );
		return 'failed';
	}

	// Set all meta fields.
	foreach ( $pipeline_meta as $key => $value ) {
		update_post_meta( $post_id, $key, $value );
	}

	// Set human-editable fields (first time = pipeline sets them).
	$readable  = $sections['readable_description'] ?? '';
	$technical = $sections['technical_description'] ?? '';
	$example   = $sections['usage_example'] ?? '';

	update_post_meta( $post_id, '_hook_description_readable', $readable );
	update_post_meta( $post_id, '_hook_description_technical', $technical );
	update_post_meta( $post_id, '_hook_usage_example', $example );
	update_post_meta( $post_id, '_hook_pipeline_hash', $pipeline_hash );

	// Set taxonomies.
	set_hook_taxonomies( $post_id, $meta );

	return 'created';
}

/**
 * Update an existing hook post.
 *
 * Only overwrites pipeline-owned fields. Human-edited fields are preserved
 * unless they still contain a {{PLACEHOLDER}}.
 *
 * @param WP_Post $post          Existing post.
 * @param array   $pipeline_meta Pipeline-owned meta fields.
 * @param string  $pipeline_hash Hash of pipeline meta.
 * @param array   $meta          YAML frontmatter.
 * @param array   $sections      Parsed markdown sections.
 *
 * @return string 'updated' or 'skipped'.
 */
function update_existing_hook( $post, array $pipeline_meta, string $pipeline_hash, array $meta, array $sections ): string {
	$post_id      = $post->ID;
	$hook_id      = $meta['hook_id'] ?? '';
	$current_hash = get_post_meta( $post_id, '_hook_pipeline_hash', true );

	// Skip if nothing changed.
	if ( $current_hash === $pipeline_hash ) {
		return 'skipped';
	}

	// Update title if changed.
	if ( $post->post_title !== $hook_id ) {
		wp_update_post( array(
			'ID'         => $post_id,
			'post_title' => $hook_id,
		) );
	}

	// Update pipeline-owned meta.
	foreach ( $pipeline_meta as $key => $value ) {
		update_post_meta( $post_id, $key, $value );
	}

	// Human-editable fields: only overwrite if current value is empty or contains a placeholder.
	$human_fields = array(
		'_hook_description_readable'  => $sections['readable_description'] ?? '',
		'_hook_description_technical' => $sections['technical_description'] ?? '',
		'_hook_usage_example'         => $sections['usage_example'] ?? '',
	);

	foreach ( $human_fields as $key => $new_value ) {
		$current = get_post_meta( $post_id, $key, true );
		if ( '' === $current || str_contains( $current, '{{' ) ) {
			update_post_meta( $post_id, $key, $new_value );
		}
		// If current value doesn't contain placeholder and isn't empty, leave it alone.
	}

	update_post_meta( $post_id, '_hook_pipeline_hash', $pipeline_hash );

	// Update taxonomies.
	set_hook_taxonomies( $post_id, $meta );

	return 'updated';
}

// ---------------------------------------------------------------------------
// Taxonomy assignment
// ---------------------------------------------------------------------------

/**
 * Set hook taxonomies from frontmatter.
 *
 * @param int   $post_id Post ID.
 * @param array $meta    YAML frontmatter.
 *
 * @return void
 */
function set_hook_taxonomies( int $post_id, array $meta ): void {
	$hook_type   = $meta['hook_type'] ?? 'action';
	$integration = $meta['integration'] ?? 'core';
	$plugin      = $meta['plugin'] ?? 'uncanny-automator';

	// Hook type.
	wp_set_object_terms( $post_id, $hook_type, 'hook_type' );

	// Integration — create term if it doesn't exist.
	$integ_label = ucwords( str_replace( array( '-', '_' ), ' ', $integration ) );
	if ( ! term_exists( $integration, 'hook_integration' ) ) {
		wp_insert_term( $integ_label, 'hook_integration', array( 'slug' => $integration ) );
	}
	wp_set_object_terms( $post_id, $integration, 'hook_integration' );

	// Plugin.
	if ( ! term_exists( $plugin, 'hook_plugin' ) ) {
		wp_insert_term( $plugin, 'hook_plugin', array( 'slug' => $plugin ) );
	}
	wp_set_object_terms( $post_id, $plugin, 'hook_plugin' );
}

// ---------------------------------------------------------------------------
// Markdown parsing
// ---------------------------------------------------------------------------

/**
 * Parse YAML frontmatter from markdown content.
 *
 * @param string $content Full file content.
 *
 * @return array Key-value pairs from frontmatter.
 */
function parse_yaml_frontmatter( string $content ): array {
	$meta = array();

	if ( ! str_starts_with( $content, '---' ) ) {
		return $meta;
	}

	$parts = explode( '---', $content, 3 );
	if ( 3 > count( $parts ) ) {
		return $meta;
	}

	foreach ( explode( "\n", trim( $parts[1] ) ) as $line ) {
		if ( str_contains( $line, ':' ) ) {
			[ $key, $value ] = explode( ':', $line, 2 );
			$meta[ trim( $key ) ] = trim( trim( $value ), '"\'  ' );
		}
	}

	return $meta;
}

/**
 * Parse markdown sections into structured data for syncing.
 *
 * @param string $content Full file content.
 *
 * @return array Structured sections.
 */
function parse_markdown_sections( string $content ): array {
	$sections = array();

	// Strip frontmatter.
	$body = $content;
	if ( str_starts_with( $content, '---' ) ) {
		$parts = explode( '---', $content, 3 );
		if ( 3 <= count( $parts ) ) {
			$body = $parts[2];
		}
	}

	// Readable description: text between "Since" line and first "---".
	if ( preg_match( '/\*\*Since:\*\*.*?\n\n(.*?)(?=\n---)/s', $body, $m ) ) {
		$desc = trim( $m[1] );
		// Remove any remaining description from docblock that precedes it.
		$sections['readable_description'] = $desc;
	}

	// Technical description: under ## Description.
	if ( preg_match( '/## Description\n\n(.*?)(?=\n---)/s', $body, $m ) ) {
		$sections['technical_description'] = trim( $m[1] );
	}

	// Parameters: extract JSON-like structure from the parameters section.
	if ( preg_match( '/## Parameters\n\n(.*?)(?=\n---)/s', $body, $m ) ) {
		$sections['parameters_raw'] = trim( $m[1] );
		$sections['parameters_json'] = parse_params_from_markdown( $m[1] );
	}

	// Return value.
	if ( preg_match( '/## Return Value\n\n(.*?)(?=\n---)/s', $body, $m ) ) {
		$sections['return_value'] = trim( $m[1] );
	}

	// Usage example: extract code from ## Examples section.
	if ( preg_match( '/## Examples\n\n(.*?)(?=\n---)/s', $body, $m ) ) {
		$examples_text = $m[1];
		// Extract all PHP code blocks.
		preg_match_all( '/```php\n(.*?)```/s', $examples_text, $code_matches );
		if ( ! empty( $code_matches[1] ) ) {
			// Take the last code block (likely the AI-generated one, not the docblock example).
			$sections['usage_example'] = trim( end( $code_matches[1] ) );
		}
	}

	// Source context.
	if ( preg_match( '/## Source Code\n\n(.*?)(?=\n---|\Z)/s', $body, $m ) ) {
		$source_section = trim( $m[1] );
		// Extract file:line references.
		preg_match_all( '/`([^`]+:\d+)`/', $source_section, $loc_matches );
		$locations = array();
		foreach ( $loc_matches[1] ?? [] as $loc ) {
			$loc_parts = explode( ':', $loc );
			if ( 2 <= count( $loc_parts ) ) {
				$locations[] = array(
					'file' => $loc_parts[0],
					'line' => (int) end( $loc_parts ),
				);
			}
		}
		$sections['source_locations'] = $locations;

		// Extract code block.
		if ( preg_match( '/```php\n(.*?)```/s', $source_section, $code_m ) ) {
			$sections['source_context'] = trim( $code_m[1] );
		}
	}

	// Related hooks.
	if ( preg_match( '/## Related Hooks\n\n(.*?)(?=\n---|\Z)/s', $body, $m ) ) {
		$related = array();
		preg_match_all( '/\[`([^`]+)`\].*?—\s*(.+)/', $m[1], $rel_matches, PREG_SET_ORDER );
		foreach ( $rel_matches as $rm ) {
			$related[] = array(
				'name'         => $rm[1],
				'relationship' => trim( $rm[2] ),
			);
		}
		$sections['related_hooks'] = $related;
	}

	// Internal usage.
	if ( preg_match( '/## Internal Usage\n\n(.*?)(?=\n---|\Z)/s', $body, $m ) ) {
		$usage = array();
		$usage_text = $m[1];
		preg_match_all( '/Found in `([^`]+)`:\n\n```php\n(.*?)```/s', $usage_text, $u_matches, PREG_SET_ORDER );
		foreach ( $u_matches as $um ) {
			$loc_parts = explode( ':', $um[1] );
			$usage[] = array(
				'file'     => $loc_parts[0],
				'line'     => 2 <= count( $loc_parts ) ? (int) end( $loc_parts ) : 0,
				'callback' => '',
				'code'     => trim( $um[2] ),
			);
		}
		$sections['internal_usage'] = $usage;
	}

	return $sections;
}

/**
 * Parse parameter entries from the markdown parameter section.
 *
 * @param string $params_markdown Raw parameters markdown.
 *
 * @return string JSON-encoded parameter array.
 */
function parse_params_from_markdown( string $params_markdown ): string {
	$params = array();

	// Match each parameter block: - **$name** `type` \n  description
	preg_match_all( '/- \*\*(\$\w+)\*\*\s*`([^`]+)`\s*\n\s*(.*?)(?=\n- \*\*|\n\n  \||\Z)/s', $params_markdown, $matches, PREG_SET_ORDER );

	foreach ( $matches as $m ) {
		$param = array(
			'name'        => $m[1],
			'type'        => $m[2],
			'description' => trim( preg_replace( '/\s+/', ' ', $m[3] ) ),
			'sub_keys'    => null,
		);

		// Check for sub-keys table.
		if ( preg_match( '/\| Key \| Type \| Description \|\n\s*\|.*?\|\n((?:\s*\|.*\|\n?)+)/', $m[3] ?? '', $table_m ) ) {
			$sub_keys = array();
			preg_match_all( '/\|\s*`([^`]+)`\s*\|\s*`([^`]+)`\s*\|\s*(.*?)\s*\|/', $table_m[1], $sk_matches, PREG_SET_ORDER );
			foreach ( $sk_matches as $sk ) {
				$sub_keys[] = array(
					'name'        => $sk[1],
					'type'        => $sk[2],
					'description' => trim( $sk[3] ),
				);
			}
			if ( ! empty( $sub_keys ) ) {
				$param['sub_keys'] = $sub_keys;
				// Clean description of the table content.
				$param['description'] = trim( preg_replace( '/\|.*$/s', '', $param['description'] ) );
			}
		}

		$params[] = $param;
	}

	return wp_json_encode( $params );
}

// ---------------------------------------------------------------------------
// Build pipeline meta
// ---------------------------------------------------------------------------

/**
 * Build the pipeline-owned meta fields from frontmatter and sections.
 *
 * @param array $meta     YAML frontmatter.
 * @param array $sections Parsed markdown sections.
 *
 * @return array Meta key => value pairs.
 */
function build_pipeline_meta( array $meta, array $sections ): array {
	return array(
		'_hook_id'               => $meta['hook_id'] ?? '',
		'_hook_since'            => $meta['since'] ?? '',
		'_hook_dynamic'          => 'true' === ( $meta['dynamic'] ?? 'false' ),
		'_hook_undocumented'     => 'true' === ( $meta['undocumented'] ?? 'false' ),
		'_hook_parameters'       => $sections['parameters_json'] ?? '[]',
		'_hook_return_type'      => extract_return_type( $sections['return_value'] ?? '' ),
		'_hook_return_description' => extract_return_desc( $sections['return_value'] ?? '' ),
		'_hook_source_context'   => $sections['source_context'] ?? '',
		'_hook_source_locations' => wp_json_encode( $sections['source_locations'] ?? array() ),
		'_hook_related'          => wp_json_encode( $sections['related_hooks'] ?? array() ),
		'_hook_internal_usage'   => wp_json_encode( $sections['internal_usage'] ?? array() ),
	);
}

/**
 * Extract return type from the return value section text.
 *
 * @param string $text Return value section.
 *
 * @return string Type or empty.
 */
function extract_return_type( string $text ): string {
	if ( preg_match( '/^`(\w+)`/', trim( $text ), $m ) ) {
		return $m[1];
	}

	return '';
}

/**
 * Extract return description from the return value section text.
 *
 * @param string $text Return value section.
 *
 * @return string Description or empty.
 */
function extract_return_desc( string $text ): string {
	$text = trim( $text );
	// Remove leading `type` backtick.
	$text = preg_replace( '/^`\w+`\s*/', '', $text );

	return trim( $text );
}

// ---------------------------------------------------------------------------
// File helpers
// ---------------------------------------------------------------------------

/**
 * Recursively glob for files matching a pattern.
 *
 * @param string $dir     Base directory.
 * @param string $pattern Glob pattern.
 *
 * @return array File paths.
 */
function glob_recursive( string $dir, string $pattern ): array {
	$files = glob( $dir . '/' . $pattern ) ?: array();

	$subdirs = glob( $dir . '/*', GLOB_ONLYDIR ) ?: array();
	foreach ( $subdirs as $subdir ) {
		$files = array_merge( $files, glob_recursive( $subdir, $pattern ) );
	}

	return $files;
}

// ---------------------------------------------------------------------------
// Run
// ---------------------------------------------------------------------------

exit( sync_hooks_main( $argv ) );
