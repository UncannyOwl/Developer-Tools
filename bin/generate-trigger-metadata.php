#!/usr/bin/env php
<?php
/**
 * Trigger metadata extractor for Uncanny Automator Free/Pro/addons.
 *
 * Walks every triggers/*.php file under the plugin's src/integrations/ (or
 * src/integration/) directory, includes it, and invokes `::definition()` on
 * every class that subclasses `Uncanny_Automator\Recipe\Trigger`. Definitions
 * are dumped to `vendor/composer/autoload_trigger_metadata.php` so
 * `Trigger_Metadata_Loader` can register registry stubs + lazy token filter
 * proxies at runtime without constructing the triggers themselves.
 *
 * Triggers whose `definition()` returns null stay on the eager path — they
 * are simply absent from the metadata file.
 *
 * Usage:
 *   php bin/generate-trigger-metadata.php --plugin-path /path/to/plugin
 *
 *   # Addon or Pro plugin whose triggers extend a base plugin's classes:
 *   php bin/generate-trigger-metadata.php \
 *       --plugin-path /path/to/addon-plugin \
 *       --base-autoload /path/to/base-plugin/vendor/autoload.php
 *
 * Output:
 *   {plugin-path}/vendor/composer/autoload_trigger_metadata.php
 *
 * The --base-autoload flag is required when the target plugin's trigger
 * files extend classes owned by a different plugin (e.g. Pro triggers
 * extend Free's \Uncanny_Automator\Recipe\Trigger). Without it, the
 * target's own autoloader cannot resolve the base classes and the
 * include would fatal.
 */

// Define ABSPATH before anything else. Many legacy trigger files guard
// include-time with `defined( 'ABSPATH' ) || exit;` — without this, those
// files would silently kill the extractor.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$options = getopt( '', array( 'plugin-path:', 'base-autoload:' ) );

if ( empty( $options['plugin-path'] ) ) {
	fwrite( STDERR, "Usage: php generate-trigger-metadata.php --plugin-path /path/to/plugin [--base-autoload /path/to/base/vendor/autoload.php]\n" );
	exit( 1 );
}

$plugin_path = realpath( rtrim( $options['plugin-path'], DIRECTORY_SEPARATOR ) );

if ( false === $plugin_path || ! is_dir( $plugin_path ) ) {
	fwrite( STDERR, "ERROR: Plugin path does not exist: {$options['plugin-path']}\n" );
	exit( 1 );
}

// Optional base plugin autoloader. Must load FIRST so addon trigger files
// whose `extends` clause references the base plugin's classes can resolve
// them during the include pass below.
if ( ! empty( $options['base-autoload'] ) ) {
	$base_autoload = realpath( $options['base-autoload'] );
	if ( false === $base_autoload || ! file_exists( $base_autoload ) ) {
		fwrite( STDERR, "ERROR: --base-autoload file does not exist: {$options['base-autoload']}\n" );
		exit( 1 );
	}
	require_once $base_autoload;
}

// Load the consuming plugin's autoloader so `Uncanny_Automator\Recipe\Trigger`
// and concrete subclasses resolve correctly when we include trigger files.
$autoload_file = $plugin_path . '/vendor/autoload.php';
if ( ! file_exists( $autoload_file ) ) {
	fwrite( STDERR, "ERROR: Missing vendor/autoload.php at {$plugin_path}. Run composer install first.\n" );
	exit( 1 );
}
require_once $autoload_file;

if ( ! class_exists( 'Uncanny_Automator\\Recipe\\Trigger' ) ) {
	// Free plugin not present (e.g., extractor invoked in an addon whose
	// dev env doesn't symlink the base plugin). Write an empty metadata file
	// so runtime consumers see "no lazy triggers" and fall back to eager.
	write_metadata_file( $plugin_path, array() );
	fwrite( STDOUT, "Uncanny_Automator\\Recipe\\Trigger not autoloadable — wrote empty metadata file.\n" );
	exit( 0 );
}

if ( ! class_exists( 'Uncanny_Automator\\Recipe\\Trigger_Definition' ) ) {
	fwrite( STDERR, "ERROR: Uncanny_Automator\\Recipe\\Trigger_Definition not autoloadable. This extractor requires the lazy-trigger-definitions feature in the base plugin.\n" );
	exit( 1 );
}

$scan_dirs = array(
	$plugin_path . '/src/integrations',
	$plugin_path . '/src/integration',
);

$trigger_metadata = array();
$errors           = array();

foreach ( $scan_dirs as $scan_dir ) {

	if ( ! is_dir( $scan_dir ) ) {
		continue;
	}

	$integration_dirs = glob( $scan_dir . '/*', GLOB_ONLYDIR );

	foreach ( $integration_dirs as $integration_dir ) {

		if ( 'vendor' === basename( $integration_dir ) ) {
			continue;
		}

		$triggers_dir = $integration_dir . '/triggers';

		if ( ! is_dir( $triggers_dir ) ) {
			continue;
		}

		$trigger_files = glob( $triggers_dir . '/*.php' );

		if ( empty( $trigger_files ) ) {
			continue;
		}

		foreach ( $trigger_files as $trigger_file ) {

			if ( 'index.php' === basename( $trigger_file ) ) {
				continue;
			}

			$before = get_declared_classes();

			try {
				require_once $trigger_file;
			} catch ( Throwable $e ) {
				$errors[] = sprintf( 'Include failed for %s: %s', $trigger_file, $e->getMessage() );
				continue;
			}

			$new_classes = array_diff( get_declared_classes(), $before );

			foreach ( $new_classes as $fqcn ) {

				if ( ! is_subclass_of( $fqcn, 'Uncanny_Automator\\Recipe\\Trigger' ) ) {
					continue;
				}

				try {
					$definition = $fqcn::definition();
				} catch ( Throwable $e ) {
					$errors[] = sprintf( '%s::definition() threw: %s', $fqcn, $e->getMessage() );
					continue;
				}

				if ( null === $definition ) {
					continue;
				}

				if ( ! $definition instanceof Uncanny_Automator\Recipe\Trigger_Definition ) {
					$type     = is_object( $definition ) ? get_class( $definition ) : gettype( $definition );
					$errors[] = sprintf( '%s::definition() must return Trigger_Definition|null, got %s', $fqcn, $type );
					continue;
				}

				if ( isset( $trigger_metadata[ $definition->code ] ) ) {
					// First-write wins. Free defines; Pro's extraction runs in
					// Pro's own composer hook and writes to Pro's metadata file.
					continue;
				}

				// Trigger_Definition::to_array() is a COMPLETE entry (class,
				// code, integration, trigger_type, trigger_meta). The `class`
				// field is populated automatically by
				// Abstract_Trigger::new_definition(). Fall back to the
				// discovered FQCN when a definition was built via the raw
				// Trigger_Definition::create() constructor without
				// for_class().
				$entry = $definition->to_array();
				if ( empty( $entry['class'] ) ) {
					$entry['class'] = $fqcn;
				}

				$trigger_metadata[ $definition->code ] = $entry;
			}
		}
	}
}

write_metadata_file( $plugin_path, $trigger_metadata );

$count = count( $trigger_metadata );
fwrite( STDOUT, "Generated autoload_trigger_metadata.php with {$count} lazy-loadable trigger(s)\n" );

if ( ! empty( $errors ) ) {
	fwrite( STDERR, "Warnings:\n" );
	foreach ( $errors as $error ) {
		fwrite( STDERR, "  - {$error}\n" );
	}
}

exit( 0 );

// ============================================================
// Helpers
// ============================================================

/**
 * Dump the metadata array to vendor/composer/autoload_trigger_metadata.php.
 *
 * @param string $plugin_path The plugin root path.
 * @param array  $metadata    Code => [class, integration, trigger_type, trigger_meta].
 *
 * @return void
 */
function write_metadata_file( $plugin_path, array $metadata ) {

	$composer_dir = $plugin_path . '/vendor/composer';

	if ( ! is_dir( $composer_dir ) ) {
		fwrite( STDERR, "ERROR: {$composer_dir} does not exist. Run composer install first.\n" );
		exit( 1 );
	}

	$content  = '<?php' . PHP_EOL;
	$content .= '// Auto-generated by automator-dev-tools/bin/generate-trigger-metadata.php — do not edit.' . PHP_EOL;
	$content .= '// Consumed by Uncanny_Automator\\Recipe\\Trigger_Metadata_Loader.' . PHP_EOL;
	$content .= 'return ' . var_export( $metadata, true ) . ';' . PHP_EOL;

	$target = $composer_dir . '/autoload_trigger_metadata.php';

	if ( false === file_put_contents( $target, $content, LOCK_EX ) ) {
		fwrite( STDERR, "Failed to write trigger metadata file: {$target}\n" );
		exit( 1 );
	}
}
