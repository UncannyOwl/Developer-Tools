#!/usr/bin/env php
<?php
/**
 * Build-time item map generator.
 *
 * Consumes a pre-built autoload_integrations_map.php and extracts trigger/action/closure/
 * condition/loop-filter codes from each source file using PHP's tokenizer.
 *
 * Output: {plugin-path}/vendor/composer/autoload_item_map.php
 *
 * Usage:
 *   php bin/generate-item-map.php --plugin-path /path/to/uncanny-automator
 *   php bin/generate-item-map.php --plugin-path /path/to/uncanny-automator-pro
 *
 * Must run AFTER generate_load_files.php (which produces autoload_integrations_map.php).
 */

// --- Parse arguments ---
$options = getopt( '', array( 'plugin-path:' ) );

if ( empty( $options['plugin-path'] ) ) {
	fwrite( STDERR, "Usage: php generate-item-map.php --plugin-path /path/to/plugin\n" );
	exit( 1 );
}

$plugin_path = rtrim( $options['plugin-path'], DIRECTORY_SEPARATOR );

if ( ! is_dir( $plugin_path ) ) {
	fwrite( STDERR, "ERROR: Plugin path does not exist: {$plugin_path}\n" );
	exit( 1 );
}

$integrations_map_path = $plugin_path . '/vendor/composer/autoload_integrations_map.php';

if ( ! file_exists( $integrations_map_path ) ) {
	fwrite( STDERR, "ERROR: autoload_integrations_map.php not found at {$integrations_map_path}\n" );
	fwrite( STDERR, "Run generate_load_files.php first.\n" );
	exit( 1 );
}

$integrations_map = include $integrations_map_path;

$item_map = array(
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

// Map item-map types to their setter/property names.
$code_setters = array(
	'triggers'     => array( 'set_trigger_code', 'trigger_code' ),
	'actions'      => array( 'set_action_code', 'action_code' ),
	'closures'     => array( 'set_closure_code', 'closure_code' ),
	'conditions'   => array( 'set_code', 'code' ),
	'loop_filters' => array( 'set_code', 'code' ),
);

$total_files   = 0;
$matched_files = 0;
$unmatched     = array();

foreach ( $integrations_map as $integration_slug => $data ) {
	foreach ( $type_mapping as $source_type => $target_type ) {
		if ( empty( $data[ $source_type ] ) || ! is_array( $data[ $source_type ] ) ) {
			continue;
		}

		$setters = isset( $code_setters[ $target_type ] ) ? $code_setters[ $target_type ] : array();

		foreach ( $data[ $source_type ] as $file_path ) {
			// $file_path is absolute (built with $baseDir).
			if ( ! is_file( $file_path ) ) {
				// Try relative path from plugin root.
				$abs = $plugin_path . $file_path;
				if ( is_file( $abs ) ) {
					$file_path = $abs;
				} else {
					continue;
				}
			}

			++$total_files;

			$extracted = extract_code_from_file( $file_path, $setters );

			if ( null === $extracted ) {
				$relative    = str_replace( $plugin_path, '', $file_path );
				$unmatched[] = $relative;
				continue;
			}

			++$matched_files;

			$integration_code = $extracted['integration'];
			if ( empty( $integration_code ) ) {
				$integration_code = strtoupper( str_replace( '-', '_', $integration_slug ) );
			}

			$relative = str_replace( $plugin_path, '', $file_path );
			$relative = str_replace( '\\', '/', $relative );

			$item_map[ $target_type ][ $extracted['code'] ] = array(
				'integration' => $integration_code,
				'class'       => $extracted['class'],
				'file'        => $relative,
			);
		}
	}
}

// --- Validation ---
$unmatched_count   = count( $unmatched );
$unmatched_percent = $total_files > 0 ? ( $unmatched_count / $total_files ) * 100 : 0;

fwrite( STDOUT, sprintf(
	"Item map: %d/%d files matched (%.1f%% unmatched)\n",
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
	exit( 1 );
}

// --- Summary ---
$parts = array();
foreach ( $item_map as $type => $entries ) {
	$c = count( $entries );
	if ( $c > 0 ) {
		$parts[] = ucfirst( str_replace( '_', ' ', $type ) ) . ": {$c}";
	}
}
fwrite( STDOUT, implode( ', ', $parts ) . "\n" );

// --- Write output ---
$output = build_item_map_output( $item_map );

$output_path = $plugin_path . '/vendor/composer/autoload_item_map.php';

if ( false === file_put_contents( $output_path, $output, LOCK_EX ) ) {
	fwrite( STDERR, "ERROR: Failed to write {$output_path}\n" );
	exit( 1 );
}

fwrite( STDOUT, "Written to: " . str_replace( $plugin_path . '/', '', $output_path ) . "\n" );

// ============================================================
// Functions
// ============================================================

/**
 * Extract trigger/action/closure/condition/loop-filter code, integration code, and FQCN from a PHP file.
 *
 * Uses token_get_all() to handle constants, concatenation, property references, and string literals.
 *
 * @param string $file_path  Absolute path to the PHP file.
 * @param array  $setters    Array with [ setter_method, property_name ] e.g. ['set_trigger_code', 'trigger_code'].
 *
 * @return array|null  Array with 'code', 'integration', 'class' keys, or null if no code found.
 */
function extract_code_from_file( $file_path, $setters ) {

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
		if ( T_VARIABLE === $token_type && preg_match( '/^\$(prefix|trigger_code|action_code|closure_code|code|integration)$/', $token_value, $m ) ) {
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
		'integration' => $integration_code,
		'class'       => $fqcn,
	);
}

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

/**
 * Build the PHP output for the item map file.
 */
function build_item_map_output( $item_map ) {

	$output  = '<?php' . PHP_EOL;
	$output .= '// Auto-generated by generate-item-map.php — DO NOT EDIT.' . PHP_EOL;
	$output .= '$vendorDir = dirname(dirname(__FILE__));' . PHP_EOL;
	$output .= '$baseDir = dirname($vendorDir);' . PHP_EOL;
	$output .= 'return array(' . PHP_EOL;

	foreach ( $item_map as $type => $entries ) {
		$output .= "\t'" . $type . "' => array(" . PHP_EOL;

		ksort( $entries );

		foreach ( $entries as $code => $entry ) {
			$output .= "\t\t'" . addslashes( $code ) . "' => array(" . PHP_EOL;
			$output .= "\t\t\t'integration' => '" . addslashes( $entry['integration'] ) . "'," . PHP_EOL;
			$output .= "\t\t\t'class'       => '" . addslashes( $entry['class'] ) . "'," . PHP_EOL;
			$output .= "\t\t\t'file'        => \$baseDir . '" . $entry['file'] . "'," . PHP_EOL;
			$output .= "\t\t)," . PHP_EOL;
		}

		$output .= "\t)," . PHP_EOL;
	}

	$output .= ');' . PHP_EOL;

	return $output;
}
