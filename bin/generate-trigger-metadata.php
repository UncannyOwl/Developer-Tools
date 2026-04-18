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

// WordPress function stubs. A handful of legacy trigger files self-instantiate
// at file-load time (e.g. a trailing `new My_Trigger()` outside any class
// or function body), which pulls `setup_trigger()` into the include path
// along with hook-registration side effects. Those methods call various
// WP core functions (i18n, hook API, filter API) that aren't defined in a
// CLI build context. Rather than reject such files, we stub the common
// surface so the include succeeds; `definition()` then returns cleanly.
//
// Pass-through stubs are sufficient — no translation / hook-firing happens
// during extraction. Namespaced calls fall back to these globals per PHP's
// function resolution rules.
$passthrough_stubs = array(
	// i18n.
	'__', '_e', '_x', '_n', '_nx',
	'esc_html', 'esc_html__', 'esc_html_e', 'esc_html_x',
	'esc_attr', 'esc_attr__', 'esc_attr_e', 'esc_attr_x',
);
foreach ( $passthrough_stubs as $stub ) {
	if ( ! function_exists( $stub ) ) {
		eval( "function {$stub}( \$text, ...\$rest ) { return \$text; }" ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
	}
}

$noop_stubs = array(
	// Hook API — registrations and dispatch. Return the value unchanged
	// where a return is expected so chained calls don't blow up.
	'add_filter'          => 'true',
	'add_action'          => 'true',
	'remove_filter'       => 'true',
	'remove_action'       => 'true',
	'remove_all_filters'  => 'true',
	'remove_all_actions'  => 'true',
	'has_filter'          => 'false',
	'has_action'          => 'false',
	'did_action'          => '0',
	'doing_action'        => 'false',
	'doing_filter'        => 'false',
	'current_action'      => 'null',
	'current_filter'      => 'null',
);
foreach ( $noop_stubs as $fn => $return_literal ) {
	if ( ! function_exists( $fn ) ) {
		eval( "function {$fn}( ...\$args ) { return {$return_literal}; }" ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
	}
}

// apply_filters returns the first arg; do_action returns null. Separate
// from the return-literal table above because apply_filters needs to
// echo its first argument.
if ( ! function_exists( 'apply_filters' ) ) {
	eval( 'function apply_filters( $tag, $value, ...$args ) { return $value; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
}
if ( ! function_exists( 'apply_filters_deprecated' ) ) {
	eval( 'function apply_filters_deprecated( $tag, $args, $version = "", $replacement = "" ) { return isset( $args[0] ) ? $args[0] : null; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
}
if ( ! function_exists( 'do_action' ) ) {
	eval( 'function do_action( ...$args ) {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
}
if ( ! function_exists( 'do_action_deprecated' ) ) {
	eval( 'function do_action_deprecated( ...$args ) {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
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

// Incremental cache keyed by absolute file path. Each entry stores the file's
// last-seen mtime plus the extraction result:
//
//   'entry' => array  → migrated trigger with a definition() metadata entry
//   'entry' => false  → file contains no trigger with a non-null definition()
//                       (don't re-include on subsequent runs unless mtime
//                       changes)
//
// On each run we stat every trigger file ONCE (cheap). Files whose mtime
// matches the cache skip the PHP include + reflection path entirely, so the
// typical composer dump-autoload cycle during development re-extracts only
// the handful of files that actually changed.
$cache_file = $plugin_path . '/vendor/composer/.autoload_trigger_metadata_cache.php';
$cache      = file_exists( $cache_file ) ? (array) ( include $cache_file ) : array();

$trigger_metadata = array();
$errors           = array();
$new_cache        = array();
$rebuild_stats    = array( 'cached' => 0, 'reloaded' => 0 );

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

			$mtime = @filemtime( $trigger_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			// Cache hit — reuse the prior extraction result.
			if ( false !== $mtime && isset( $cache[ $trigger_file ]['mtime'] ) && $cache[ $trigger_file ]['mtime'] === $mtime ) {

				$cached_entry = isset( $cache[ $trigger_file ]['entry'] ) ? $cache[ $trigger_file ]['entry'] : null;

				// Preserve in the forward-cache so the next run sees it too.
				$new_cache[ $trigger_file ] = array(
					'mtime' => $mtime,
					'entry' => $cached_entry,
				);

				if ( is_array( $cached_entry ) && isset( $cached_entry['code'] ) && ! isset( $trigger_metadata[ $cached_entry['code'] ] ) ) {
					$trigger_metadata[ $cached_entry['code'] ] = $cached_entry;
				}

				++$rebuild_stats['cached'];
				continue;
			}

			// Cache miss or stale — include and re-extract.
			++$rebuild_stats['reloaded'];
			$before = get_declared_classes();

			try {
				require_once $trigger_file;
			} catch ( Throwable $e ) {
				$errors[] = sprintf( 'Include failed for %s: %s', $trigger_file, $e->getMessage() );
				continue;
			}

			$new_classes = array_diff( get_declared_classes(), $before );
			$file_entry  = false;

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

				$file_entry = $entry;

				if ( ! isset( $trigger_metadata[ $entry['code'] ] ) ) {
					// First-write wins. Free defines; Pro's extraction runs in
					// Pro's own composer hook and writes to Pro's metadata file.
					$trigger_metadata[ $entry['code'] ] = $entry;
				}

				// One trigger class per file by convention; stop at the first
				// migrated one so the cache entry is deterministic.
				break;
			}

			$new_cache[ $trigger_file ] = array(
				'mtime' => false !== $mtime ? $mtime : 0,
				'entry' => $file_entry,
			);
		}
	}
}

write_metadata_file( $plugin_path, $trigger_metadata );
write_cache_file( $cache_file, $new_cache );

$count = count( $trigger_metadata );
fwrite( STDOUT, sprintf(
	"Generated autoload_trigger_metadata.php with %d lazy-loadable trigger(s) — %d cached, %d reloaded\n",
	$count,
	$rebuild_stats['cached'],
	$rebuild_stats['reloaded']
) );

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

/**
 * Persist the per-file mtime cache alongside the metadata file so the next
 * run can fast-skip unchanged triggers.
 *
 * @param string $cache_file Absolute path to the sidecar cache PHP file.
 * @param array  $cache      file_path => [ mtime, entry ] shape.
 *
 * @return void
 */
function write_cache_file( $cache_file, array $cache ) {

	$content  = '<?php' . PHP_EOL;
	$content .= '// Auto-generated sidecar cache for generate-trigger-metadata.php.' . PHP_EOL;
	$content .= '// Tracks per-file mtimes so unchanged triggers skip the include/extract pass.' . PHP_EOL;
	$content .= 'return ' . var_export( $cache, true ) . ';' . PHP_EOL;

	if ( false === @file_put_contents( $cache_file, $content, LOCK_EX ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		// Cache write failure isn't fatal — next run just rebuilds from scratch.
		fwrite( STDERR, "Warning: could not write trigger metadata cache at {$cache_file}\n" );
	}
}
