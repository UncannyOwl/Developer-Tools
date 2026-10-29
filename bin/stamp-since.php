#!/usr/bin/env php
<?php
/**
 * Stamp @since — release version-tag stamper (PHP mirror of
 * src/assets/tools/version/set-version.js).
 *
 * Replaces the LITERAL placeholder `@since <version>` in PHP docblocks with a
 * concrete release version. Developers write `@since <version>` (the literal
 * token `<version>`) in new code and never hand-code the number; this stamps it
 * at release time, so a slipped release (7.6.0 → 7.7.0) needs no re-editing —
 * the placeholder just stamps to whatever actually ships.
 *
 * SUPER LIMITED BY DESIGN: it only rewrites the exact token `@since <version>`.
 * It never touches a concrete `@since X.Y.Z` (that is history), never touches
 * `@version`, and never touches anything outside that placeholder.
 *
 * Usage:
 *   php stamp-since.php --plugin-path .                 # version from the plugin header
 *   php stamp-since.php --plugin-path . --version 7.7.0 # explicit version
 *   php stamp-since.php --plugin-path . --dry-run
 *
 * Options:
 *   --plugin-path <path>  Root dir of the plugin (required).
 *   --version <x.y.z>     Version to stamp. Default: the plugin's Version: header.
 *   --dry-run             List what would change without writing.
 *
 * @package Uncanny_Owl\Developer_Tools
 */

$options = getopt( '', array( 'plugin-path:', 'version:', 'dry-run', 'help' ) );

if ( isset( $options['help'] ) || ! isset( $options['plugin-path'] ) ) {
	fwrite( STDOUT, <<<USAGE

  stamp-since.php — stamp the `@since <version>` placeholder with a release version.

  Usage:
    php stamp-since.php --plugin-path <path> [--version <x.y.z>] [--dry-run]

  Only the literal token `@since <version>` in .php files is rewritten. Concrete
  @since versions (history) and @version tags are never touched.


USAGE
	);
	exit( isset( $options['help'] ) ? 0 : 1 );
}

$plugin_path = realpath( $options['plugin-path'] );
$dry_run     = isset( $options['dry-run'] );

if ( false === $plugin_path || ! is_dir( $plugin_path ) ) {
	fwrite( STDERR, "Error: plugin path does not exist: {$options['plugin-path']}\n" );
	exit( 1 );
}
$plugin_path = rtrim( $plugin_path, DIRECTORY_SEPARATOR );

// ── Resolve the version: explicit --version wins, else the plugin header ──
$version = isset( $options['version'] ) ? trim( (string) $options['version'] ) : '';

if ( '' === $version ) {
	$main_file = null;
	foreach ( array( 'uncanny-automator.php', 'uncanny-automator-pro.php' ) as $candidate ) {
		if ( file_exists( $plugin_path . DIRECTORY_SEPARATOR . $candidate ) ) {
			$main_file = $plugin_path . DIRECTORY_SEPARATOR . $candidate;
			break;
		}
	}
	if ( null === $main_file ) {
		fwrite( STDERR, "Error: no uncanny-automator(.php|-pro.php) found in {$plugin_path}; pass --version.\n" );
		exit( 1 );
	}
	if ( ! preg_match( '/^\s*\*\s*Version:\s+(.+)$/m', (string) file_get_contents( $main_file ), $m ) ) {
		fwrite( STDERR, "Error: could not read the Version: header from {$main_file}; pass --version.\n" );
		exit( 1 );
	}
	$version = trim( $m[1] );
}

// Sanity-gate the version so we never stamp garbage into thousands of files.
if ( ! preg_match( '/^\d+\.\d+(\.\d+)*(-[0-9A-Za-z.\-]+)?$/', $version ) ) {
	fwrite( STDERR, "Error: '{$version}' does not look like a version (e.g. 7.7.0).\n" );
	exit( 1 );
}

// ── Scan .php files, skipping build/dependency trees only. `.php` is the PHP
// stamper's domain wherever it lives; src/assets JS (.ts/.js/.scss) is owned by
// set-version.js and can't collide (different extensions), so no need to skip
// the assets dir — that would risk orphaning a stray .php placeholder there. ──
$skip_dirs = array( 'vendor', 'node_modules', 'build', 'dist', '.git' );

$rii = new RecursiveIteratorIterator(
	new RecursiveCallbackFilterIterator(
		new RecursiveDirectoryIterator( $plugin_path, FilesystemIterator::SKIP_DOTS ),
		static function ( SplFileInfo $file ) use ( $skip_dirs ): bool {
			if ( $file->isDir() ) {
				return ! in_array( $file->getFilename(), $skip_dirs, true );
			}
			return 'php' === strtolower( $file->getExtension() );
		}
	)
);

// Literal placeholder only — `<version>` is a fixed token, so there is no
// partial-match risk (unlike a concrete version, which could sit inside a longer
// number). The capture keeps whatever whitespace followed @since.
$pattern = '/(@since\s+)<version>/';

$changed_files = array();
$total_hits    = 0;

foreach ( $rii as $file ) {
	$path     = $file->getPathname();
	$content  = (string) file_get_contents( $path );
	$replaced = preg_replace_callback(
		$pattern,
		static function ( array $mm ) use ( $version, &$total_hits ): string {
			$total_hits++;
			return $mm[1] . $version;
		},
		$content
	);

	if ( null !== $replaced && $replaced !== $content ) {
		$changed_files[] = ltrim( str_replace( $plugin_path, '', $path ), DIRECTORY_SEPARATOR );
		if ( ! $dry_run ) {
			file_put_contents( $path, $replaced );
		}
	}
}

$prefix = $dry_run ? '[DRY RUN] ' : '';
if ( empty( $changed_files ) ) {
	fwrite( STDOUT, "No `@since <version>` placeholders found. Nothing to stamp.\n" );
	exit( 0 );
}

sort( $changed_files );
fwrite( STDOUT, "{$prefix}Stamped @since <version> -> {$version} in " . count( $changed_files ) . " file(s), {$total_hits} tag(s):\n" );
foreach ( $changed_files as $f ) {
	fwrite( STDOUT, "  - {$f}\n" );
}
exit( 0 );
