#!/usr/bin/env php
<?php
/**
 * Build-time item map and catalog generator.
 *
 * Consumes a pre-built autoload_integrations_map.php and extracts codes + metadata
 * from each source file using PHP's tokenizer + regex.
 *
 * Outputs:
 *   1. {plugin-path}/vendor/composer/autoload_item_map.php   — lean runtime map (code, class, file)
 *   2. (optional) {plugin-path}/src/core/includes/pro-items-list.php — Pro/addon items for Free UI dropdown
 *
 * Note: write_item_catalog() exists but is not called during the build. It produces a rich
 * catalog file (autoload_item_catalog.php) with metadata, sentences, is_pro, is_deprecated, etc.
 * Currently too large (~66k tokens) for agentic AI consumption. Available for future use if
 * a lighter format or filtered subset becomes viable.
 *
 * Usage:
 *   php bin/generate-item-map.php --plugin-path /path/to/uncanny-automator
 *   php bin/generate-item-map.php --plugin-path /path/to/uncanny-automator --pro-path /path/to/pro
 *   php bin/generate-item-map.php --plugin-path /path/to/free --pro-path /path/to/pro \
 *       --addon-path /path/to/custom-user-fields:plus \
 *       --addon-path /path/to/elite-integrations:elite
 *
 * Must run AFTER generate_load_files.php (which produces autoload_integrations_map.php).
 */

// --- Parse arguments ---
$options = getopt( '', array( 'plugin-path:', 'pro-path:', 'addon-path:' ) );

if ( empty( $options['plugin-path'] ) ) {
	fwrite( STDERR, "Usage: php generate-item-map.php --plugin-path /path/to/plugin [--pro-path /path/to/pro] [--addon-path /path:tier ...]\n" );
	exit( 1 );
}

$plugin_path = realpath( rtrim( $options['plugin-path'], DIRECTORY_SEPARATOR ) );
$pro_path    = isset( $options['pro-path'] ) ? realpath( rtrim( $options['pro-path'], DIRECTORY_SEPARATOR ) ) : null;

// Parse addon paths — supports multiple --addon-path flags with path:tier format.
$addon_paths = array();
if ( isset( $options['addon-path'] ) ) {
	$raw_addons = is_array( $options['addon-path'] ) ? $options['addon-path'] : array( $options['addon-path'] );
	foreach ( $raw_addons as $addon_arg ) {
		$parts = explode( ':', $addon_arg );
		$path  = realpath( rtrim( $parts[0], DIRECTORY_SEPARATOR ) );
		$tier  = isset( $parts[1] ) ? strtolower( $parts[1] ) : 'plus';
		if ( false !== $path && is_dir( $path ) ) {
			$addon_paths[] = array(
				'path' => $path,
				'tier' => $tier,
			);
		} else {
			fwrite( STDOUT, "Addon path not found, skipping: {$parts[0]}\n" );
		}
	}
}

if ( false === $plugin_path || ! is_dir( $plugin_path ) ) {
	fwrite( STDERR, "ERROR: Plugin path does not exist: {$options['plugin-path']}\n" );
	exit( 1 );
}

// --- Process main (Free) plugin ---
$result = process_plugin( $plugin_path );

if ( null === $result ) {
	exit( 1 );
}

// Write item map (lean).
write_item_map( $result['item_map'], $plugin_path );

// Rich catalog — not written to disk (too large for current consumers). Uncomment when needed.
// write_item_catalog( $result['item_catalog'], $plugin_path );

// Collect Free integration codes for pro_only determination.
$free_integration_codes = get_integration_codes_from_map( $plugin_path );

// --- Process Pro plugin and addons → write pro-items-list.php ---
if ( null !== $pro_path ) {
	$pro_items_path = $plugin_path . '/src/core/includes/pro-items-list.php';

	if ( false === $pro_path || ! is_dir( $pro_path ) ) {
		fwrite( STDOUT, "Pro path not found: {$options['pro-path']} — keeping existing pro-items-list.php\n" );
	} else {
		$pro_integrations_map = $pro_path . '/vendor/composer/autoload_integrations_map.php';
		if ( ! file_exists( $pro_integrations_map ) ) {
			fwrite( STDOUT, "Pro autoload_integrations_map.php not found — keeping existing pro-items-list.php\n" );
		} else {
			// Process Pro plugin items.
			$pro_result = process_plugin( $pro_path, 'Pro' );
			if ( null !== $pro_result ) {
				// Write Pro's own item map into Pro's vendor directory.
				write_item_map( $pro_result['item_map'], $pro_path );
				// write_item_catalog( $pro_result['item_catalog'], $pro_path );

				// Collect integration names from Pro + Free integration files.
				$integration_names = get_integration_names_from_maps( $pro_path, $plugin_path );

				// Process addon plugins (they now have autoload_integrations_map.php).
				$addon_catalogs = array();
				foreach ( $addon_paths as $addon ) {
					$addon_label  = basename( $addon['path'] );
					$addon_result = process_plugin( $addon['path'], "Addon ({$addon_label})" );
					if ( null !== $addon_result ) {
						// Collect integration names from addon integration files.
						$addon_names = get_integration_names_from_addon( $addon['path'] );
						$integration_names = array_merge( $integration_names, $addon_names );

						$addon_catalogs[] = array(
							'item_catalog'      => $addon_result['item_catalog'],
							'tier'              => $addon['tier'],
							'integration_names' => $addon_names,
						);
					}
				}

				// Write consolidated pro-items-list.php.
				write_pro_items_list(
					$pro_result['item_catalog'],
					$addon_catalogs,
					$integration_names,
					$free_integration_codes,
					$pro_items_path
				);
			}
		}
	}
}

// Remove old pro-items-catalog.php if it exists (replaced by pro-items-list.php).
$old_catalog = $plugin_path . '/src/core/includes/pro-items-catalog.php';
if ( file_exists( $old_catalog ) ) {
	unlink( $old_catalog );
	fwrite( STDOUT, "Removed old pro-items-catalog.php\n" );
}

// ============================================================
// Core processing
// ============================================================

/**
 * Process a plugin directory and return item_map + item_catalog arrays.
 *
 * @param string $path  Absolute plugin path.
 * @param string $label Label for output messages.
 *
 * @return array|null  Array with 'item_map' and 'item_catalog' keys, or null on failure.
 */
function process_plugin( $path, $label = 'Plugin' ) {

	$integrations_map_path = $path . '/vendor/composer/autoload_integrations_map.php';

	if ( ! file_exists( $integrations_map_path ) ) {
		fwrite( STDERR, "ERROR: autoload_integrations_map.php not found at {$integrations_map_path}\n" );
		fwrite( STDERR, "Run generate_load_files.php first.\n" );
		return null;
	}

	$integrations_map = include $integrations_map_path;

	return process_integrations_map( $integrations_map, $path, $label );
}

/**
 * Process an integrations map (from file or synthesized) and return item_map + item_catalog.
 *
 * @param array  $integrations_map The integrations map.
 * @param string $path             Absolute plugin path (for relative paths and reporting).
 * @param string $label            Label for output messages.
 *
 * @return array|null  Array with 'item_map' and 'item_catalog' keys, or null on failure.
 */
function process_integrations_map( $integrations_map, $path, $label = 'Plugin' ) {

	$item_map = array(
		'triggers'     => array(),
		'actions'      => array(),
		'closures'     => array(),
		'conditions'   => array(),
		'loop_filters' => array(),
	);

	$item_catalog = array(
		'triggers'     => array(),
		'actions'      => array(),
		'closures'     => array(),
		'conditions'   => array(),
		'loop_filters' => array(),
	);

	// Map integration-map directory names to item-map type keys.
	$type_mapping = array(
		'triggers'     => 'triggers',
		'actions'      => 'actions',
		'closures'     => 'closures',
		'conditions'   => 'conditions',
		'loop-filters' => 'loop_filters',
	);

	// Map item-map types to their code setter/property names.
	$code_setters = array(
		'triggers'     => array( 'set_trigger_code', 'trigger_code' ),
		'actions'      => array( 'set_action_code', 'action_code' ),
		'closures'     => array( 'set_closure_code', 'closure_code' ),
		'conditions'   => array( 'set_code', 'code' ),
		'loop_filters' => array( 'set_meta', 'meta' ),
	);

	// Map item-map types to their meta setter/property names.
	$meta_setters = array(
		'triggers'     => array( 'set_trigger_meta', 'trigger_meta' ),
		'actions'      => array( 'set_action_meta', 'action_meta' ),
		'closures'     => array(),
		'conditions'   => array(),
		'loop_filters' => array(),
	);

	$total_files   = 0;
	$matched_files = 0;
	$unmatched     = array();

	foreach ( $integrations_map as $integration_slug => $data ) {
		foreach ( $type_mapping as $source_type => $target_type ) {
			if ( empty( $data[ $source_type ] ) || ! is_array( $data[ $source_type ] ) ) {
				continue;
			}

			$setters      = isset( $code_setters[ $target_type ] ) ? $code_setters[ $target_type ] : array();
			$meta_setter  = isset( $meta_setters[ $target_type ] ) ? $meta_setters[ $target_type ] : array();

			foreach ( $data[ $source_type ] as $file_path ) {
				// $file_path is absolute (built with $baseDir).
				if ( ! is_file( $file_path ) ) {
					// Try relative path from plugin root.
					$abs = $path . $file_path;
					if ( is_file( $abs ) ) {
						$file_path = $abs;
					} else {
						continue;
					}
				}

				++$total_files;

				$extracted = extract_code_from_file( $file_path, $setters, $meta_setter );

				if ( null === $extracted ) {
					$relative    = str_replace( $path, '', $file_path );
					$unmatched[] = $relative;
					continue;
				}

				++$matched_files;

				$integration_code = $extracted['integration'];
				if ( empty( $integration_code ) ) {
					$integration_code = strtoupper( str_replace( '-', '_', $integration_slug ) );
				}

				$relative = str_replace( $path, '', $file_path );
				$relative = str_replace( '\\', '/', $relative );

				$composite_key = $integration_code . '_' . $extracted['code'];

				// Extract catalog metadata first — needed for is_deprecated flag.
				$catalog_meta = extract_catalog_metadata( $file_path, $target_type );

				// Lean item map — runtime loading only. Indexed by integration code for O(1) lookups.
				// Deprecated items are included — existing recipes still reference them.
				$item_map[ $integration_code ][ $target_type ][ $composite_key ] = array(
					'code'  => $extracted['code'],
					'class' => $extracted['class'],
					'file'  => $relative,
				);

				// Rich catalog — UI, MCP, pro-items dropdown.
				$item_catalog[ $target_type ][ $composite_key ] = array(
					'integration'       => $integration_code,
					'code'              => $extracted['code'],
					'meta'              => $extracted['meta'],
					'class'             => $extracted['class'],
					'readable_sentence' => $catalog_meta['readable_sentence'],
					'is_pro'            => $catalog_meta['is_pro'],
					'is_deprecated'     => $catalog_meta['is_deprecated'],
					'requires_user'     => $catalog_meta['requires_user'],
					'type'              => $target_type,
				);
			}
		}
	}

	// --- Validation ---
	$unmatched_count   = count( $unmatched );
	$unmatched_percent = $total_files > 0 ? ( $unmatched_count / $total_files ) * 100 : 0;

	fwrite( STDOUT, sprintf(
		"%s: %d/%d files matched (%.1f%% unmatched)\n",
		$label,
		$matched_files,
		$total_files,
		$unmatched_percent
	) );

	if ( $unmatched_count > 0 ) {
		fwrite( STDOUT, "Unmatched files:\n" );
		foreach ( $unmatched as $file ) {
			fwrite( STDOUT, "  - {$file}\n" );
		}
	}

	// For large plugins (100+ files), 5% threshold catches bugs.
	// For small addons, allow up to 2 unmatched files (utility classes in action dirs, etc.).
	if ( $unmatched_percent > 5 && $unmatched_count > 2 ) {
		fwrite( STDERR, "ERROR: More than 5% of files unmatched ({$unmatched_count} files). Tokenizer may have a bug.\n" );
		return null;
	}

	// Summary — item map is now integration-first, count items across all integrations by type.
	$type_counts = array();
	foreach ( $item_map as $int_code => $types ) {
		foreach ( $types as $type => $entries ) {
			if ( ! isset( $type_counts[ $type ] ) ) {
				$type_counts[ $type ] = 0;
			}
			$type_counts[ $type ] += count( $entries );
		}
	}
	$parts = array();
	foreach ( $type_counts as $type => $c ) {
		if ( $c > 0 ) {
			$parts[] = ucfirst( str_replace( '_', ' ', $type ) ) . ": {$c}";
		}
	}
	$deprecated_count = 0;
	foreach ( $item_catalog as $type => $entries ) {
		foreach ( $entries as $entry ) {
			if ( ! empty( $entry['is_deprecated'] ) ) {
				++$deprecated_count;
			}
		}
	}
	if ( $deprecated_count > 0 ) {
		$parts[] = "Deprecated: {$deprecated_count}";
	}
	fwrite( STDOUT, implode( ', ', $parts ) . "\n" );

	return array(
		'item_map'     => $item_map,
		'item_catalog' => $item_catalog,
	);
}

// ============================================================
// Addon helpers
// ============================================================

/**
 * Extract integration names from an addon's integrations map.
 *
 * @param string $addon_path Absolute path to addon plugin root.
 *
 * @return array  Integration code => display name map.
 */
function get_integration_names_from_addon( $addon_path ) {

	$map_path = $addon_path . '/vendor/composer/autoload_integrations_map.php';
	if ( ! file_exists( $map_path ) ) {
		return array();
	}

	$map   = include $map_path;
	$names = array();

	foreach ( $map as $slug => $data ) {
		$main_file = isset( $data['main'] ) ? $data['main'] : '';
		if ( ! empty( $main_file ) && is_file( $main_file ) ) {
			$info = extract_integration_info( $main_file );
			if ( ! empty( $info['code'] ) && ! empty( $info['name'] ) ) {
				$names[ $info['code'] ] = $info['name'];
			}
		}
	}

	return $names;
}

/**
 * Extract integration code and display name from an integration class file.
 *
 * Handles both modern ($this->set_integration / $this->set_name) and
 * legacy (public static $integration / register->integration 'name' key) patterns.
 *
 * @param string $file_path Absolute path to the integration class file.
 *
 * @return array  Array with 'code' and 'name' keys.
 */
function extract_integration_info( $file_path ) {

	$source = file_get_contents( $file_path );

	$result = array(
		'code' => '',
		'name' => '',
	);

	if ( false === $source ) {
		return $result;
	}

	// Modern: $this->set_integration( 'CODE' )
	if ( preg_match( '/set_integration\s*\(\s*[\'"]([^\'"]+)/', $source, $m ) ) {
		$result['code'] = $m[1];
	} elseif ( preg_match( '/set_integration\s*\(\s*(?:self|static)::\s*(\w+)/', $source, $m ) ) {
		// Modern with constant: $this->set_integration( self::integration )
		$const_name = $m[1];
		if ( preg_match( '/const\s+' . preg_quote( $const_name, '/' ) . '\s*=\s*[\'"]([^\'"]+)/', $source, $cm ) ) {
			$result['code'] = $cm[1];
		}
	} elseif ( preg_match( '/\$integration\s*=\s*[\'"]([^\'"]+)/', $source, $m ) ) {
		// Legacy: public static $integration = 'CODE'
		$result['code'] = $m[1];
	} elseif ( preg_match( '/[\'"]integration[\'"]\s*=>\s*[\'"]([^\'"]+)/', $source, $m ) ) {
		// Config array: 'integration' => 'CODE' (App_Integration pattern)
		$result['code'] = $m[1];
	}

	// Modern: $this->set_name( 'Display Name' )
	if ( preg_match( '/set_name\s*\(\s*[\'"]([^\'"]+)/', $source, $m ) ) {
		$result['name'] = $m[1];
	} elseif ( preg_match( '/set_name\s*\(\s*(?:self|static)::\s*(\w+)/', $source, $m ) ) {
		// Modern with constant: $this->set_name( self::NAME )
		$const_name = $m[1];
		if ( preg_match( '/const\s+' . preg_quote( $const_name, '/' ) . '\s*=\s*[\'"]([^\'"]+)/', $source, $cm ) ) {
			$result['name'] = $cm[1];
		}
	} elseif ( preg_match( '/[\'"]name[\'"]\s*=>\s*[\'"]([^\'"]+)/', $source, $m ) ) {
		// Legacy: 'name' => 'Display Name' (in register->integration call)
		$result['name'] = $m[1];
	}

	return $result;
}

// ============================================================
// Integration name + code helpers
// ============================================================

/**
 * Get all integration codes from a plugin's integrations map.
 *
 * @param string $plugin_path Plugin root path.
 *
 * @return array  Array of integration codes (uppercase).
 */
function get_integration_codes_from_map( $plugin_path ) {

	$map_path = $plugin_path . '/vendor/composer/autoload_integrations_map.php';
	if ( ! file_exists( $map_path ) ) {
		return array();
	}

	$map   = include $map_path;
	$codes = array();

	foreach ( $map as $slug => $data ) {
		$main_file = isset( $data['main'] ) ? $data['main'] : '';
		if ( ! empty( $main_file ) && is_file( $main_file ) ) {
			$info = extract_integration_info( $main_file );
			if ( ! empty( $info['code'] ) ) {
				$codes[] = $info['code'];
				continue;
			}
		}
		// Fallback: derive from slug.
		$codes[] = strtoupper( str_replace( '-', '_', $slug ) );
	}

	return $codes;
}

/**
 * Build integration_code => display_name map from Pro + Free integration files.
 *
 * Priority: overrides → Pro file name → Free file name → humanized slug fallback.
 *
 * @param string $pro_path  Pro plugin root.
 * @param string $free_path Free plugin root.
 *
 * @return array  Integration code => display name.
 */
function get_integration_names_from_maps( $pro_path, $free_path ) {

	$names = array();

	// Read names from both Free and Pro integration files.
	foreach ( array( $free_path, $pro_path ) as $path ) {
		$map_path = $path . '/vendor/composer/autoload_integrations_map.php';
		if ( ! file_exists( $map_path ) ) {
			continue;
		}

		$map = include $map_path;
		foreach ( $map as $slug => $data ) {
			$main_file = isset( $data['main'] ) ? $data['main'] : '';
			if ( ! empty( $main_file ) && is_file( $main_file ) ) {
				$info = extract_integration_info( $main_file );
				if ( ! empty( $info['code'] ) && ! empty( $info['name'] ) ) {
					// Later entries (Pro) can override Free names if set.
					$names[ $info['code'] ] = $info['name'];
				}
			}
		}
	}

	return $names;
}

// ============================================================
// Extraction functions
// ============================================================

/**
 * Extract code, integration, meta, and FQCN from a PHP file using tokenizer.
 *
 * @param string $file_path    Absolute path to the PHP file.
 * @param array  $setters      Array with [ setter_method, property_name ] for the item code.
 * @param array  $meta_setters Array with [ setter_method, property_name ] for the item meta.
 *
 * @return array|null  Array with 'code', 'integration', 'meta', 'class' keys, or null if no code found.
 */
function extract_code_from_file( $file_path, $setters, $meta_setters = array() ) {

	$source = file_get_contents( $file_path );

	if ( false === $source ) {
		return null;
	}

	$tokens = token_get_all( $source );
	$count  = count( $tokens );

	// Collect class constants, properties, and class info.
	$constants        = array();
	$properties       = array(); // instance properties like $prefix
	$namespace        = '';
	$class_name       = '';
	$integration_code = '';
	$item_code        = '';
	$item_meta        = '';

	for ( $i = 0; $i < $count; $i++ ) {
		$token = $tokens[ $i ];

		if ( ! is_array( $token ) ) {
			continue;
		}

		$token_type  = $token[0];
		$token_value = $token[1];

		// Collect namespace.
		if ( T_NAMESPACE === $token_type ) {
			$namespace = collect_namespace( $tokens, $i, $count );
			continue;
		}

		// Collect class name (first class declaration only).
		if ( T_CLASS === $token_type && empty( $class_name ) ) {
			$class_name = collect_class_name( $tokens, $i, $count );
			continue;
		}

		// Collect constants: const NAME = 'VALUE'
		if ( T_CONST === $token_type ) {
			$const_data = collect_constant( $tokens, $i, $count, $constants );
			if ( null !== $const_data ) {
				$constants[ $const_data['name'] ] = $const_data['value'];
			}
			continue;
		}

		// Collect property declarations: protected $prefix = 'VALUE', private $trigger_code = 'CODE', etc.
		if ( T_VARIABLE === $token_type && preg_match( '/^\$(prefix|trigger_code|action_code|closure_code|code|meta|integration|trigger_meta|action_meta)$/', $token_value, $m ) ) {
			$prop_name = $m[1];
			$value     = collect_assignment_value( $tokens, $i, $count, $constants, $properties );
			if ( null !== $value ) {
				$properties[ $prop_name ] = $value;

				// Directly assign known properties.
				if ( 'integration' === $prop_name ) {
					$integration_code = $value;
				}
				// private $trigger_code = 'CODE' or private $action_code = 'CODE' or private $code = 'CODE'
				if ( ! empty( $setters ) && $setters[1] === $prop_name && empty( $item_code ) ) {
					$item_code = $value;
				}
				// private $trigger_meta = 'META' or private $action_meta = 'META'
				if ( ! empty( $meta_setters ) && isset( $meta_setters[1] ) && $meta_setters[1] === $prop_name && empty( $item_meta ) ) {
					$item_meta = $value;
				}
			}
			continue;
		}

		// Collect set_integration( 'CODE' )
		if ( T_STRING === $token_type && 'set_integration' === $token_value ) {
			$value = collect_function_argument( $tokens, $i, $count, $constants, $properties );
			if ( null !== $value ) {
				$integration_code = $value;
			}
			continue;
		}

		// Collect code via setter: $this->set_trigger_code( ... ) or $this->set_action_code( ... ) etc.
		if ( T_STRING === $token_type && ! empty( $setters ) && $setters[0] === $token_value ) {
			$value = collect_function_argument( $tokens, $i, $count, $constants, $properties );
			if ( null !== $value ) {
				$item_code = $value;
			}
			continue;
		}

		// Collect meta via setter: $this->set_trigger_meta( ... ) or $this->set_action_meta( ... ) etc.
		if ( T_STRING === $token_type && ! empty( $meta_setters ) && isset( $meta_setters[0] ) && $meta_setters[0] === $token_value ) {
			$value = collect_function_argument( $tokens, $i, $count, $constants, $properties );
			if ( null !== $value ) {
				$item_meta = $value;
			}
			continue;
		}

		// Collect $this->integration = 'CODE' (property assignment, not setter method).
		if ( T_STRING === $token_type && 'integration' === $token_value ) {
			$value = collect_assignment_value( $tokens, $i, $count, $constants, $properties );
			if ( null !== $value ) {
				$integration_code = $value;
			}
			continue;
		}

		// Collect code via property assignment in method: $this->trigger_code = ... etc.
		if ( T_STRING === $token_type && ! empty( $setters ) && $setters[1] === $token_value ) {
			$value = collect_assignment_value( $tokens, $i, $count, $constants, $properties );
			if ( null !== $value ) {
				$item_code = $value;
			}
			continue;
		}
	}

	// Fallback: handle double-quoted interpolation like "{$this->prefix}_CODE"
	if ( empty( $item_code ) && ! empty( $properties['prefix'] ) && ! empty( $setters ) ) {
		$setter_name = $setters[0];
		if ( preg_match( '/' . preg_quote( $setter_name, '/' ) . '\(\s*"\\{\\$this->prefix\\}([^"]*)"/', $source, $m ) ) {
			$item_code = $properties['prefix'] . $m[1];
		}
	}

	if ( empty( $item_code ) || empty( $class_name ) ) {
		return null;
	}

	$fqcn = $namespace ? $namespace . '\\' . $class_name : $class_name;

	return array(
		'code'        => $item_code,
		'meta'        => $item_meta,
		'integration' => $integration_code,
		'class'       => $fqcn,
	);
}

/**
 * Extract catalog metadata via regex (readable_sentence, is_pro, requires_user).
 *
 * These fields are "nice to have" for the catalog — regex is sufficient for simple patterns.
 *
 * @param string $file_path   Absolute path to the PHP file.
 * @param string $target_type The item type (triggers, actions, etc.).
 *
 * @return array  Array with 'readable_sentence', 'is_pro', 'requires_user' keys.
 */
function extract_catalog_metadata( $file_path, $target_type ) {

	$source = file_get_contents( $file_path );

	$result = array(
		'readable_sentence' => '',
		'is_pro'            => false,
		'is_deprecated'     => false,
		'requires_user'     => true,
	);

	if ( false === $source ) {
		return $result;
	}

	// Strip block comments before regex matching — /* translators: ... */ between setter and i18n call breaks \s* matching.
	$source_stripped = preg_replace( '/\/\*.*?\*\//s', '', $source );

	// --- Readable sentence ---
	// Covers all WP translation wrappers: esc_html_x, esc_html__, esc_attr_x, esc_attr__, _x, __
	// Pattern: setter/key => optional_wrapper( 'sentence'
	$i18n = 'esc_(?:html|attr)_(?:x|_)|esc_(?:html|attr)__|__|_x|_e';

	// Each pattern has two variants: double-quoted (captures apostrophes safely) and single-quoted.
	// Double-quoted patterns use "([^"\\]*)" — immune to apostrophes.
	// Single-quoted patterns use '([^'\\]*)' — immune to double quotes.
	// Both exclude backslash from the base class so escaped quotes (\' or \") are handled by \\. alternation.
	// Double-quoted variant is listed first so it wins for strings containing apostrophes.
	$dq = '"([^"\\\\]*(?:\\\\.[^"\\\\]*)*)"';   // captures content of "..." with escaped chars
	$sq = '\'([^\'\\\\]*(?:\\\\.[^\'\\\\]*)*)\''; // captures content of '...' with escaped chars

	$sentence_patterns = array(
		// Modern: set_readable_sentence( wrapper( "sentence" / 'sentence' ...
		'/set_readable_sentence\s*\(\s*(?:' . $i18n . ')\s*\(\s*' . $dq . '/s',
		'/set_readable_sentence\s*\(\s*(?:' . $i18n . ')\s*\(\s*' . $sq . '/s',
		// Modern: set_readable_sentence( sprintf( wrapper( "sentence" / 'sentence' ...
		'/set_readable_sentence\s*\(\s*sprintf\s*\(\s*(?:' . $i18n . ')\s*\(\s*' . $dq . '/s',
		'/set_readable_sentence\s*\(\s*sprintf\s*\(\s*(?:' . $i18n . ')\s*\(\s*' . $sq . '/s',
		// Modern: set_readable_sentence( "sentence" / 'sentence' ) — no wrapper
		'/set_readable_sentence\s*\(\s*' . $dq . '/',
		'/set_readable_sentence\s*\(\s*' . $sq . '/',
		// Modern: $readable_sentence = wrapper( "sentence" / 'sentence' ...
		'/\$readable_sentence\s*=\s*(?:' . $i18n . ')\s*\(\s*' . $dq . '/',
		'/\$readable_sentence\s*=\s*(?:' . $i18n . ')\s*\(\s*' . $sq . '/',
		// Modern alt: set_sentence_readable( wrapper( "sentence" / 'sentence' ...
		'/set_sentence_readable\s*\(\s*(?:' . $i18n . ')\s*\(\s*' . $dq . '/s',
		'/set_sentence_readable\s*\(\s*(?:' . $i18n . ')\s*\(\s*' . $sq . '/s',
		'/set_sentence_readable\s*\(\s*' . $dq . '/',
		'/set_sentence_readable\s*\(\s*' . $sq . '/',
		// Legacy: 'select_option_name' => wrapper( "sentence" / 'sentence' ...
		'/[\'"]select_option_name[\'"]\s*=>\s*(?:' . $i18n . ')\s*\(\s*' . $dq . '/s',
		'/[\'"]select_option_name[\'"]\s*=>\s*(?:' . $i18n . ')\s*\(\s*' . $sq . '/s',
		// Legacy: 'select_option_name' => sprintf( wrapper( "sentence" / 'sentence' ...
		'/[\'"]select_option_name[\'"]\s*=>\s*sprintf\s*\(\s*(?:' . $i18n . ')\s*\(\s*' . $dq . '/s',
		'/[\'"]select_option_name[\'"]\s*=>\s*sprintf\s*\(\s*(?:' . $i18n . ')\s*\(\s*' . $sq . '/s',
		'/[\'"]select_option_name[\'"]\s*=>\s*' . $dq . '/',
		'/[\'"]select_option_name[\'"]\s*=>\s*' . $sq . '/',
	);

	foreach ( $sentence_patterns as $pattern ) {
		if ( preg_match( $pattern, $source_stripped, $m ) ) {
			// Unescape PHP string escapes from source (e.g. \' → ' in single-quoted, \" → " in double-quoted).
			$result['readable_sentence'] = stripslashes( $m[1] );
			break;
		}
	}

	// --- is_pro ---
	// Modern: set_is_pro( true ) or set_is_pro( false )
	if ( preg_match( '/set_is_pro\s*\(\s*(true|false)\s*\)/', $source_stripped, $m ) ) {
		$result['is_pro'] = 'true' === $m[1];
	} elseif ( preg_match( '/[\'"]is_pro[\'"]\s*=>\s*(true|false)/', $source_stripped, $m ) ) {
		// Legacy: 'is_pro' => true
		$result['is_pro'] = 'true' === $m[1];
	}

	// --- is_deprecated ---
	// Modern: set_is_deprecated( true )
	if ( preg_match( '/set_is_deprecated\s*\(\s*true\s*\)/', $source_stripped ) ) {
		$result['is_deprecated'] = true;
	} elseif ( preg_match( '/[\'"]is_deprecated[\'"]\s*=>\s*true/', $source_stripped ) ) {
		// Legacy: 'is_deprecated' => true
		$result['is_deprecated'] = true;
	}

	// --- requires_user ---
	// Modern: set_requires_user( true/false )
	if ( preg_match( '/set_requires_user\s*\(\s*(true|false)\s*\)/', $source_stripped, $m ) ) {
		$result['requires_user'] = 'true' === $m[1];
	} elseif ( preg_match( '/set_is_login_required\s*\(\s*(true|false)\s*\)/', $source_stripped, $m ) ) {
		$result['requires_user'] = 'true' === $m[1];
	} elseif ( preg_match( '/set_trigger_type\s*\(\s*[\'"]anonymous[\'"]/', $source_stripped ) ) {
		$result['requires_user'] = false;
	} elseif ( preg_match( '/[\'"]type[\'"]\s*=>\s*[\'"]anonymous[\'"]/', $source_stripped ) ) {
		// Legacy: 'type' => 'anonymous'
		$result['requires_user'] = false;
	}

	return $result;
}

// ============================================================
// Tokenizer helper functions
// ============================================================

/**
 * Collect namespace from tokens starting at the T_NAMESPACE token.
 */
function collect_namespace( $tokens, &$i, $count ) {

	$namespace = '';
	++$i;

	for ( ; $i < $count; $i++ ) {
		$token = $tokens[ $i ];

		if ( ';' === $token || '{' === $token ) {
			break;
		}

		if ( is_array( $token ) ) {
			$type = $token[0];
			if ( T_STRING === $type || T_NS_SEPARATOR === $type || ( defined( 'T_NAME_QUALIFIED' ) && T_NAME_QUALIFIED === $type ) ) {
				$namespace .= $token[1];
			}
		}
	}

	return trim( $namespace );
}

/**
 * Collect class name from tokens starting at the T_CLASS token.
 */
function collect_class_name( $tokens, &$i, $count ) {

	++$i;

	for ( ; $i < $count; $i++ ) {
		$token = $tokens[ $i ];

		if ( is_array( $token ) && T_STRING === $token[0] ) {
			return $token[1];
		}

		if ( is_array( $token ) && T_WHITESPACE === $token[0] ) {
			continue;
		}

		break;
	}

	return '';
}

/**
 * Collect a class constant declaration: const NAME = 'VALUE'
 */
function collect_constant( $tokens, &$i, $count, $existing_constants ) {

	$const_name = null;
	$j          = $i + 1;

	// Find constant name.
	for ( ; $j < $count; $j++ ) {
		if ( is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
			continue;
		}
		if ( is_array( $tokens[ $j ] ) && T_STRING === $tokens[ $j ][0] ) {
			$const_name = $tokens[ $j ][1];
			++$j;
			break;
		}
		break;
	}

	if ( null === $const_name ) {
		return null;
	}

	// Find '='.
	for ( ; $j < $count; $j++ ) {
		if ( is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
			continue;
		}
		if ( '=' === $tokens[ $j ] ) {
			++$j;
			break;
		}
		return null;
	}

	$const_value = collect_string_expression( $tokens, $j, $count, $existing_constants );

	if ( null === $const_value ) {
		return null;
	}

	$i = $j;

	return array(
		'name'  => $const_name,
		'value' => $const_value,
	);
}

/**
 * Collect the value after an = sign.
 */
function collect_assignment_value( $tokens, &$i, $count, $constants, $properties = array() ) {

	$j = $i + 1;

	for ( ; $j < $count; $j++ ) {
		if ( is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
			continue;
		}
		if ( '=' === $tokens[ $j ] ) {
			++$j;
			break;
		}
		return null;
	}

	return collect_string_expression( $tokens, $j, $count, $constants, $properties );
}

/**
 * Collect the first argument of a function call.
 */
function collect_function_argument( $tokens, &$i, $count, $constants, $properties = array() ) {

	$j = $i + 1;

	for ( ; $j < $count; $j++ ) {
		if ( is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
			continue;
		}
		if ( '(' === $tokens[ $j ] ) {
			++$j;
			break;
		}
		return null;
	}

	return collect_string_expression( $tokens, $j, $count, $constants, $properties );
}

/**
 * Collect a string expression: handles string literals, self::CONST, static::CONST,
 * $this->property, and concatenation with the . operator.
 */
function collect_string_expression( $tokens, &$j, $count, $constants, $properties = array() ) {

	$result    = '';
	$has_value = false;

	for ( ; $j < $count; $j++ ) {
		$token = $tokens[ $j ];

		// Skip whitespace.
		if ( is_array( $token ) && T_WHITESPACE === $token[0] ) {
			continue;
		}

		// String literal.
		if ( is_array( $token ) && T_CONSTANT_ENCAPSED_STRING === $token[0] ) {
			$result   .= trim( $token[1], "\"'" );
			$has_value = true;
			continue;
		}

		// $this->propertyName reference.
		if ( is_array( $token ) && T_VARIABLE === $token[0] && '$this' === $token[1] ) {
			$k = $j + 1;
			while ( $k < $count && is_array( $tokens[ $k ] ) && T_WHITESPACE === $tokens[ $k ][0] ) {
				++$k;
			}
			if ( $k < $count && is_array( $tokens[ $k ] ) && T_OBJECT_OPERATOR === $tokens[ $k ][0] ) {
				++$k;
				while ( $k < $count && is_array( $tokens[ $k ] ) && T_WHITESPACE === $tokens[ $k ][0] ) {
					++$k;
				}
				if ( $k < $count && is_array( $tokens[ $k ] ) && T_STRING === $tokens[ $k ][0] ) {
					$prop_name = $tokens[ $k ][1];
					if ( isset( $properties[ $prop_name ] ) ) {
						$result   .= $properties[ $prop_name ];
						$has_value = true;
						$j         = $k;
						continue;
					}
					return null;
				}
			}
			return null;
		}

		// self:: or static:: constant reference.
		if ( is_array( $token ) && T_STRING === $token[0] && ( 'self' === $token[1] || 'static' === $token[1] ) ) {
			$k = $j + 1;
			while ( $k < $count && is_array( $tokens[ $k ] ) && T_WHITESPACE === $tokens[ $k ][0] ) {
				++$k;
			}
			if ( $k < $count && is_array( $tokens[ $k ] ) && T_DOUBLE_COLON === $tokens[ $k ][0] ) {
				++$k;
				while ( $k < $count && is_array( $tokens[ $k ] ) && T_WHITESPACE === $tokens[ $k ][0] ) {
					++$k;
				}
				if ( $k < $count && is_array( $tokens[ $k ] ) && T_STRING === $tokens[ $k ][0] ) {
					$const_name = $tokens[ $k ][1];
					if ( isset( $constants[ $const_name ] ) ) {
						$result   .= $constants[ $const_name ];
						$has_value = true;
						$j         = $k;
						continue;
					}
					return null;
				}
			}
			return null;
		}

		// Concatenation operator.
		if ( '.' === $token ) {
			continue;
		}

		// End of expression.
		break;
	}

	return $has_value ? $result : null;
}

// ============================================================
// Output writers
// ============================================================

/**
 * Write the lean item map (runtime loading).
 */
function write_item_map( $item_map, $plugin_path ) {

	$output  = '<?php' . PHP_EOL;
	$output .= '// Auto-generated by generate-item-map.php — DO NOT EDIT.' . PHP_EOL;
	$output .= '// Structure: integration_code => type => composite_key => item.' . PHP_EOL;
	$output .= '$vendorDir = dirname(dirname(__FILE__));' . PHP_EOL;
	$output .= '$baseDir = dirname($vendorDir);' . PHP_EOL;
	$output .= 'return array(' . PHP_EOL;

	ksort( $item_map );

	foreach ( $item_map as $integration_code => $types ) {
		$output .= "\t'" . addslashes( $integration_code ) . "' => array(" . PHP_EOL;

		foreach ( $types as $type => $entries ) {
			$output .= "\t\t'" . $type . "' => array(" . PHP_EOL;

			ksort( $entries );

			foreach ( $entries as $composite_key => $entry ) {
				$output .= "\t\t\t'" . addslashes( $composite_key ) . "' => array(" . PHP_EOL;
				$output .= "\t\t\t\t'code'  => '" . addslashes( $entry['code'] ) . "'," . PHP_EOL;
				$output .= "\t\t\t\t'class' => '" . addslashes( $entry['class'] ) . "'," . PHP_EOL;
				$output .= "\t\t\t\t'file'  => \$baseDir . '" . $entry['file'] . "'," . PHP_EOL;
				$output .= "\t\t\t)," . PHP_EOL;
			}

			$output .= "\t\t)," . PHP_EOL;
		}

		$output .= "\t)," . PHP_EOL;
	}

	$output .= ');' . PHP_EOL;

	$output_path = $plugin_path . '/vendor/composer/autoload_item_map.php';

	if ( false === file_put_contents( $output_path, $output, LOCK_EX ) ) {
		fwrite( STDERR, "ERROR: Failed to write {$output_path}\n" );
		return;
	}

	fwrite( STDOUT, "Written to: " . str_replace( $plugin_path . '/', '', $output_path ) . "\n" );
}

/**
 * Write the rich item catalog (UI, MCP, explorer).
 */
function write_item_catalog( $item_catalog, $plugin_path ) {

	$output  = '<?php' . PHP_EOL;
	$output .= '// Auto-generated by generate-item-map.php — DO NOT EDIT.' . PHP_EOL;
	$output .= 'return array(' . PHP_EOL;

	foreach ( $item_catalog as $type => $entries ) {
		$output .= "\t'" . $type . "' => array(" . PHP_EOL;

		ksort( $entries );

		foreach ( $entries as $composite_key => $entry ) {
			$output .= "\t\t'" . addslashes( $composite_key ) . "' => array(" . PHP_EOL;
			$output .= "\t\t\t'integration'       => '" . addslashes( $entry['integration'] ) . "'," . PHP_EOL;
			$output .= "\t\t\t'code'              => '" . addslashes( $entry['code'] ) . "'," . PHP_EOL;
			$output .= "\t\t\t'meta'              => '" . addslashes( $entry['meta'] ) . "'," . PHP_EOL;
			$output .= "\t\t\t'readable_sentence' => " . php_quote( $entry['readable_sentence'] ) . "," . PHP_EOL;
			$output .= "\t\t\t'is_pro'            => " . ( $entry['is_pro'] ? 'true' : 'false' ) . ',' . PHP_EOL;
			$output .= "\t\t\t'requires_user'     => " . ( $entry['requires_user'] ? 'true' : 'false' ) . ',' . PHP_EOL;
			$output .= "\t\t)," . PHP_EOL;
		}

		$output .= "\t)," . PHP_EOL;
	}

	$output .= ');' . PHP_EOL;

	$output_path = $plugin_path . '/vendor/composer/autoload_item_catalog.php';

	if ( false === file_put_contents( $output_path, $output, LOCK_EX ) ) {
		fwrite( STDERR, "ERROR: Failed to write {$output_path}\n" );
		return;
	}

	fwrite( STDOUT, "Written to: " . str_replace( $plugin_path . '/', '', $output_path ) . "\n" );
}

/**
 * Write the consolidated pro-items-list.php.
 *
 * Matches the exact format consumed by Utilities::get_pro_items_list(), Structure class,
 * and Recipe_Post_Utilities (sent to JS as UncannyAutomator.pro_items).
 *
 * Format per integration:
 *   'CODE' => array(
 *       'name'       => 'Display Name',
 *       'pro_only'   => 'yes'|'no',
 *       'elite_only' => 'yes'|'no',
 *       'triggers'   => array( array( 'name' => '...', 'type' => 'logged-in'|'anonymous', 'is_pro' => true, 'is_elite' => false ) ),
 *       'actions'    => array( array( 'name' => '...', 'is_pro' => true, 'is_elite' => false ) ),
 *   )
 *
 * @param array  $pro_catalog           Pro plugin item catalog.
 * @param array  $addon_catalogs        Array of addon results (each with 'item_catalog', 'tier', 'integration_names').
 * @param array  $integration_names     Integration code => display name map.
 * @param array  $free_integration_codes Array of integration codes present in Free.
 * @param string $output_path           Absolute path to write pro-items-list.php.
 */
/**
 * Wrap a string in PHP-safe quotes for code generation.
 *
 * Uses single quotes by default. Switches to double quotes if the string contains
 * an apostrophe (single quote) — avoids broken translation strings like esc_html_x( '...it\'s...' ).
 *
 * @param string $str The raw string value.
 *
 * @return string  Quoted string safe for PHP output (e.g. 'foo' or "bar's").
 */
function php_quote( $str ) {
	if ( false !== strpos( $str, "'" ) ) {
		// Use double quotes — escape any $ or " inside.
		return '"' . str_replace( array( '\\', '"', '$' ), array( '\\\\', '\\"', '\\$' ), $str ) . '"';
	}
	return "'" . $str . "'";
}

function write_pro_items_list( $pro_catalog, $addon_catalogs, $integration_names, $free_integration_codes, $output_path ) {

	// Reorganize all items by integration code.
	$by_integration = array();

	// Process Pro items.
	collect_items_by_integration( $by_integration, $pro_catalog, 'pro', $integration_names );

	// Process addon items.
	foreach ( $addon_catalogs as $addon ) {
		collect_items_by_integration( $by_integration, $addon['item_catalog'], $addon['tier'], $addon['integration_names'] );
	}

	// Collect addon integration codes (addons always have pro_only = 'no').
	$addon_integration_codes = array();
	foreach ( $addon_catalogs as $addon ) {
		foreach ( array( 'triggers', 'actions' ) as $type ) {
			if ( empty( $addon['item_catalog'][ $type ] ) ) {
				continue;
			}
			foreach ( $addon['item_catalog'][ $type ] as $entry ) {
				$addon_integration_codes[ $entry['integration'] ] = true;
			}
		}
	}

	// Determine pro_only / elite_only for each integration.
	$free_codes_map = array_flip( $free_integration_codes );

	foreach ( $by_integration as $int_code => &$data ) {
		// Addon items are never "pro_only" — they're in their own tier (plus/elite).
		// Pro items: 'yes' if the integration does NOT exist in Free.
		if ( isset( $addon_integration_codes[ $int_code ] ) ) {
			$data['pro_only'] = 'no';
		} else {
			$data['pro_only'] = isset( $free_codes_map[ $int_code ] ) ? 'no' : 'yes';
		}

		// elite_only: 'yes' if ANY item in this integration is elite tier.
		$has_elite = false;
		foreach ( array( 'triggers', 'actions' ) as $type ) {
			foreach ( $data[ $type ] as $item ) {
				if ( ! empty( $item['is_elite'] ) ) {
					$has_elite = true;
					break 2;
				}
			}
		}
		$data['elite_only'] = $has_elite ? 'yes' : 'no';
	}
	unset( $data );

	ksort( $by_integration );

	// Build the output matching automator_pro_items_list() function format.
	$output  = '<?php' . PHP_EOL;
	$output .= '// Auto-generated by generate-item-map.php — DO NOT EDIT.' . PHP_EOL;
	$output .= PHP_EOL;
	$output .= '/**' . PHP_EOL;
	$output .= ' * Pro items list.' . PHP_EOL;
	$output .= ' *' . PHP_EOL;
	$output .= ' * The list of items that are available in the pro version of the plugin.' . PHP_EOL;
	$output .= ' * Used to generate the pro items list.' . PHP_EOL;
	$output .= ' *' . PHP_EOL;
	$output .= ' * @return array' . PHP_EOL;
	$output .= ' */' . PHP_EOL;
	$output .= 'function automator_pro_items_list() {' . PHP_EOL;
	$output .= "\treturn array(" . PHP_EOL;

	foreach ( $by_integration as $int_code => $data ) {
		$name       = addslashes( $data['name'] );
		$pro_only   = $data['pro_only'];
		$elite_only = $data['elite_only'];

		$output .= "\t\t'" . addslashes( $int_code ) . "' => array(" . PHP_EOL;
		$output .= "\t\t\t'name'       => '" . $name . "'," . PHP_EOL;
		$output .= "\t\t\t'pro_only'   => '" . $pro_only . "'," . PHP_EOL;
		$output .= "\t\t\t'elite_only' => '" . $elite_only . "'," . PHP_EOL;

		// Triggers.
		if ( empty( $data['triggers'] ) ) {
			$output .= "\t\t\t'triggers'   => array()," . PHP_EOL;
		} else {
			$output .= "\t\t\t'triggers'   => array(" . PHP_EOL;
			foreach ( $data['triggers'] as $item ) {
				$output .= "\t\t\t\tarray(" . PHP_EOL;
				$output .= "\t\t\t\t\t'name'     => esc_html_x( " . php_quote( $item['name'] ) . ", 'Automator Pro item', 'uncanny-automator' )," . PHP_EOL;
				$output .= "\t\t\t\t\t'type'     => '" . $item['type'] . "'," . PHP_EOL;
				$output .= "\t\t\t\t\t'is_pro'   => " . ( $item['is_pro'] ? 'true' : 'false' ) . ',' . PHP_EOL;
				$output .= "\t\t\t\t\t'is_elite' => " . ( $item['is_elite'] ? 'true' : 'false' ) . ',' . PHP_EOL;
				$output .= "\t\t\t\t)," . PHP_EOL;
			}
			$output .= "\t\t\t)," . PHP_EOL;
		}

		// Actions.
		if ( empty( $data['actions'] ) ) {
			$output .= "\t\t\t'actions'    => array()," . PHP_EOL;
		} else {
			$output .= "\t\t\t'actions'    => array(" . PHP_EOL;
			foreach ( $data['actions'] as $item ) {
				$output .= "\t\t\t\tarray(" . PHP_EOL;
				$output .= "\t\t\t\t\t'name'     => esc_html_x( " . php_quote( $item['name'] ) . ", 'Automator Pro item', 'uncanny-automator' )," . PHP_EOL;
				$output .= "\t\t\t\t\t'is_pro'   => " . ( $item['is_pro'] ? 'true' : 'false' ) . ',' . PHP_EOL;
				$output .= "\t\t\t\t\t'is_elite' => " . ( $item['is_elite'] ? 'true' : 'false' ) . ',' . PHP_EOL;
				$output .= "\t\t\t\t)," . PHP_EOL;
			}
			$output .= "\t\t\t)," . PHP_EOL;
		}

		$output .= "\t\t)," . PHP_EOL;
	}

	$output .= "\t);" . PHP_EOL;
	$output .= '}' . PHP_EOL;

	if ( false === file_put_contents( $output_path, $output, LOCK_EX ) ) {
		fwrite( STDERR, "ERROR: Failed to write {$output_path}\n" );
		return;
	}

	fwrite( STDOUT, "\nPro items list: " . $output_path . "\n" );
	fwrite( STDOUT, "  Integrations: " . count( $by_integration ) . "\n" );

	// Count items.
	$trigger_count = 0;
	$action_count  = 0;
	foreach ( $by_integration as $data ) {
		$trigger_count += count( $data['triggers'] );
		$action_count  += count( $data['actions'] );
	}
	fwrite( STDOUT, "  Triggers: {$trigger_count}, Actions: {$action_count}\n" );
}

/**
 * Collect items from a catalog into the by-integration structure.
 *
 * @param array  &$by_integration Target array organized by integration code.
 * @param array  $catalog         Item catalog (triggers, actions, etc.).
 * @param string $source          Source identifier: 'pro', 'plus', or 'elite'.
 * @param array  $names           Integration code => display name map.
 */
function collect_items_by_integration( &$by_integration, $catalog, $source, $names = array() ) {

	$is_pro   = ( 'pro' === $source );
	$is_elite = ( 'elite' === $source );

	foreach ( array( 'triggers', 'actions' ) as $type ) {
		if ( empty( $catalog[ $type ] ) ) {
			continue;
		}
		foreach ( $catalog[ $type ] as $composite_key => $entry ) {
			// Skip deprecated items — they should not appear in the pro items picker.
			if ( ! empty( $entry['is_deprecated'] ) ) {
				continue;
			}
			$int_code = $entry['integration'];
			if ( ! isset( $by_integration[ $int_code ] ) ) {
				// Resolve display name.
				$display_name = '';
				if ( isset( $names[ $int_code ] ) ) {
					$display_name = $names[ $int_code ];
				}
				// Fallback: humanize the code.
				if ( empty( $display_name ) ) {
					$display_name = ucwords( strtolower( str_replace( '_', ' ', $int_code ) ) );
				}

				$by_integration[ $int_code ] = array(
					'name'     => $display_name,
					'triggers' => array(),
					'actions'  => array(),
				);
			}

			$item = array(
				'name'     => $entry['readable_sentence'],
				'is_pro'   => $is_pro,
				'is_elite' => $is_elite,
			);

			if ( 'triggers' === $type ) {
				$item['type'] = $entry['requires_user'] ? 'logged-in' : 'anonymous';
			}

			$by_integration[ $int_code ][ $type ][] = $item;
		}
	}
}
