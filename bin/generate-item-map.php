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
 *   2. {plugin-path}/vendor/composer/autoload_item_catalog.php — rich catalog (meta, sentence, is_pro, etc.)
 *   3. (optional) {plugin-path}/src/core/includes/pro-items-catalog.php — stripped Pro items for Free UI
 *
 * Usage:
 *   php bin/generate-item-map.php --plugin-path /path/to/uncanny-automator
 *   php bin/generate-item-map.php --plugin-path /path/to/uncanny-automator --pro-path /path/to/uncanny-automator-pro
 *
 * Must run AFTER generate_load_files.php (which produces autoload_integrations_map.php).
 */

// --- Parse arguments ---
$options = getopt( '', array( 'plugin-path:', 'pro-path:' ) );

if ( empty( $options['plugin-path'] ) ) {
	fwrite( STDERR, "Usage: php generate-item-map.php --plugin-path /path/to/plugin [--pro-path /path/to/pro]\n" );
	exit( 1 );
}

$plugin_path = realpath( rtrim( $options['plugin-path'], DIRECTORY_SEPARATOR ) );
$pro_path    = isset( $options['pro-path'] ) ? realpath( rtrim( $options['pro-path'], DIRECTORY_SEPARATOR ) ) : null;

if ( false === $plugin_path || ! is_dir( $plugin_path ) ) {
	fwrite( STDERR, "ERROR: Plugin path does not exist: {$options['plugin-path']}\n" );
	exit( 1 );
}

// --- Process main plugin ---
$result = process_plugin( $plugin_path );

if ( null === $result ) {
	exit( 1 );
}

// Write item map (lean).
write_item_map( $result['item_map'], $plugin_path );

// Write item catalog (rich).
write_item_catalog( $result['item_catalog'], $plugin_path );

// --- Process Pro plugin (optional) ---
if ( null !== $pro_path ) {
	$pro_catalog_path = $plugin_path . '/src/core/includes/pro-items-catalog.php';

	if ( false === $pro_path || ! is_dir( $pro_path ) ) {
		fwrite( STDOUT, "Pro path not found: {$options['pro-path']} — keeping existing pro-items-catalog.php\n" );
	} else {
		$pro_integrations_map = $pro_path . '/vendor/composer/autoload_integrations_map.php';
		if ( ! file_exists( $pro_integrations_map ) ) {
			fwrite( STDOUT, "Pro autoload_integrations_map.php not found — keeping existing pro-items-catalog.php\n" );
		} else {
			$pro_result = process_plugin( $pro_path, 'Pro' );
			if ( null !== $pro_result ) {
				write_pro_items_catalog( $pro_result['item_catalog'], $pro_catalog_path, $pro_path );
			}
		}
	}
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

				// Lean item map — runtime loading only.
				$item_map[ $target_type ][ $composite_key ] = array(
					'integration' => $integration_code,
					'code'        => $extracted['code'],
					'class'       => $extracted['class'],
					'file'        => $relative,
				);

				// Rich catalog — UI, MCP, pro-items dropdown.
				$catalog_meta = extract_catalog_metadata( $file_path, $target_type );

				$item_catalog[ $target_type ][ $composite_key ] = array(
					'integration'       => $integration_code,
					'code'              => $extracted['code'],
					'meta'              => $extracted['meta'],
					'class'             => $extracted['class'],
					'readable_sentence' => $catalog_meta['readable_sentence'],
					'is_pro'            => $catalog_meta['is_pro'],
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

	if ( $unmatched_percent > 5 ) {
		fwrite( STDERR, "ERROR: More than 5% of files unmatched. Tokenizer may have a bug.\n" );
		return null;
	}

	// Summary.
	$parts = array();
	foreach ( $item_map as $type => $entries ) {
		$c = count( $entries );
		if ( $c > 0 ) {
			$parts[] = ucfirst( str_replace( '_', ' ', $type ) ) . ": {$c}";
		}
	}
	fwrite( STDOUT, implode( ', ', $parts ) . "\n" );

	return array(
		'item_map'     => $item_map,
		'item_catalog' => $item_catalog,
	);
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

	$sentence_patterns = array(
		// Modern: set_readable_sentence( wrapper( 'sentence' ...
		'/set_readable_sentence\s*\(\s*(?:' . $i18n . ')\s*\(\s*[\'"]([^\'"]+)/s',
		// Modern: set_readable_sentence( sprintf( wrapper( 'sentence' ...
		'/set_readable_sentence\s*\(\s*sprintf\s*\(\s*(?:' . $i18n . ')\s*\(\s*[\'"]([^\'"]+)/s',
		// Modern: set_readable_sentence( 'sentence' ) — no wrapper
		'/set_readable_sentence\s*\(\s*[\'"]([^\'"]+)/',
		// Modern: $readable_sentence = wrapper( 'sentence' ... then set_readable_sentence( $readable_sentence )
		'/\$readable_sentence\s*=\s*(?:' . $i18n . ')\s*\(\s*[\'"]([^\'"]+)/',
		// Modern alt: set_sentence_readable( wrapper( 'sentence' ...
		'/set_sentence_readable\s*\(\s*(?:' . $i18n . ')\s*\(\s*[\'"]([^\'"]+)/s',
		'/set_sentence_readable\s*\(\s*[\'"]([^\'"]+)/',
		// Legacy: 'select_option_name' => wrapper( 'sentence' ...
		'/[\'"]select_option_name[\'"]\s*=>\s*(?:' . $i18n . ')\s*\(\s*[\'"]([^\'"]+)/s',
		// Legacy: 'select_option_name' => sprintf( wrapper( 'sentence' ...
		'/[\'"]select_option_name[\'"]\s*=>\s*sprintf\s*\(\s*(?:' . $i18n . ')\s*\(\s*[\'"]([^\'"]+)/s',
		'/[\'"]select_option_name[\'"]\s*=>\s*[\'"]([^\'"]+)/',
	);

	foreach ( $sentence_patterns as $pattern ) {
		if ( preg_match( $pattern, $source_stripped, $m ) ) {
			$result['readable_sentence'] = $m[1];
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
	$output .= '$vendorDir = dirname(dirname(__FILE__));' . PHP_EOL;
	$output .= '$baseDir = dirname($vendorDir);' . PHP_EOL;
	$output .= 'return array(' . PHP_EOL;

	foreach ( $item_map as $type => $entries ) {
		$output .= "\t'" . $type . "' => array(" . PHP_EOL;

		ksort( $entries );

		foreach ( $entries as $composite_key => $entry ) {
			$output .= "\t\t'" . addslashes( $composite_key ) . "' => array(" . PHP_EOL;
			$output .= "\t\t\t'integration' => '" . addslashes( $entry['integration'] ) . "'," . PHP_EOL;
			$output .= "\t\t\t'code'        => '" . addslashes( $entry['code'] ) . "'," . PHP_EOL;
			$output .= "\t\t\t'class'       => '" . addslashes( $entry['class'] ) . "'," . PHP_EOL;
			$output .= "\t\t\t'file'        => \$baseDir . '" . $entry['file'] . "'," . PHP_EOL;
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
			$output .= "\t\t\t'readable_sentence' => '" . addslashes( $entry['readable_sentence'] ) . "'," . PHP_EOL;
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
 * Write the stripped-down Pro items catalog (replaces pro-items-list.php).
 *
 * Organized by integration code, matching the format consumed by Structure class.
 */
function write_pro_items_catalog( $pro_catalog, $output_path, $pro_path ) {

	// Reorganize by integration code for the dropdown UI.
	$by_integration = array();

	foreach ( array( 'triggers', 'actions' ) as $type ) {
		if ( empty( $pro_catalog[ $type ] ) ) {
			continue;
		}
		foreach ( $pro_catalog[ $type ] as $composite_key => $entry ) {
			$int_code = $entry['integration'];
			if ( ! isset( $by_integration[ $int_code ] ) ) {
				$by_integration[ $int_code ] = array(
					'triggers' => array(),
					'actions'  => array(),
				);
			}

			$item = array(
				'name' => $entry['readable_sentence'],
			);

			if ( 'triggers' === $type ) {
				$item['type'] = $entry['requires_user'] ? 'logged-in' : 'anonymous';
			}

			$by_integration[ $int_code ][ $type ][] = $item;
		}
	}

	ksort( $by_integration );

	$output  = '<?php' . PHP_EOL;
	$output .= '// Auto-generated by generate-item-map.php — DO NOT EDIT.' . PHP_EOL;
	$output .= '// Stripped-down Pro items for Free plugin UI dropdown.' . PHP_EOL;
	$output .= '// Regenerated when Pro path is available at build time.' . PHP_EOL;
	$output .= 'return array(' . PHP_EOL;

	foreach ( $by_integration as $int_code => $data ) {
		$output .= "\t'" . addslashes( $int_code ) . "' => array(" . PHP_EOL;
		foreach ( array( 'triggers', 'actions' ) as $type ) {
			$output .= "\t\t'" . $type . "' => array(" . PHP_EOL;
			foreach ( $data[ $type ] as $item ) {
				$output .= "\t\t\tarray(" . PHP_EOL;
				$output .= "\t\t\t\t'name' => '" . addslashes( $item['name'] ) . "'," . PHP_EOL;
				if ( isset( $item['type'] ) ) {
					$output .= "\t\t\t\t'type' => '" . addslashes( $item['type'] ) . "'," . PHP_EOL;
				}
				$output .= "\t\t\t)," . PHP_EOL;
			}
			$output .= "\t\t)," . PHP_EOL;
		}
		$output .= "\t)," . PHP_EOL;
	}

	$output .= ');' . PHP_EOL;

	if ( false === file_put_contents( $output_path, $output, LOCK_EX ) ) {
		fwrite( STDERR, "ERROR: Failed to write {$output_path}\n" );
		return;
	}

	fwrite( STDOUT, "Pro items catalog: " . $output_path . "\n" );
	fwrite( STDOUT, "  Integrations: " . count( $by_integration ) . "\n" );
}
