#!/usr/bin/env php
<?php
/**
 * Stamp Version — Bleeding Edge Release Tool
 *
 * Appends the short git commit hash to the plugin's version header and CONST,
 * producing a bleeding-edge build like "7.2.0-a1b2c3d".
 *
 * Works for both Free (uncanny-automator) and Pro (uncanny-automator-pro).
 * Auto-detects which plugin it's running against based on the main plugin file.
 *
 * Usage:
 *   php vendor/uncanny-owl/developer-tools/bin/stamp-version.php --plugin-path .
 *   php vendor/uncanny-owl/developer-tools/bin/stamp-version.php --plugin-path . --hash abc1234
 *   php vendor/uncanny-owl/developer-tools/bin/stamp-version.php --plugin-path . --restore
 *
 * Options:
 *   --plugin-path <path>  Root directory of the plugin (required).
 *   --hash <hash>         Override the commit hash instead of reading from git.
 *   --restore             Remove the hash suffix and restore the original version.
 *   --dry-run             Show what would change without writing to disk.
 *
 * @package Uncanny_Owl\Developer_Tools
 */

// ──────────────────────────────────────────────
// CLI argument parsing
// ──────────────────────────────────────────────

$options = getopt( '', array( 'plugin-path:', 'hash:', 'restore', 'dry-run', 'help' ) );

if ( isset( $options['help'] ) || ! isset( $options['plugin-path'] ) ) {
	fwrite( STDOUT, <<<USAGE

  stamp-version.php — Append git commit hash to plugin version for bleeding-edge builds.

  Usage:
    php stamp-version.php --plugin-path <path> [--hash <hash>] [--restore] [--dry-run]

  Options:
    --plugin-path <path>  Root directory of the plugin (required).
    --hash <hash>         Override the commit hash instead of reading from git.
    --restore             Remove the hash suffix and restore the original version.
    --dry-run             Show what would change without writing to disk.


USAGE
	);
	exit( isset( $options['help'] ) ? 0 : 1 );
}

$plugin_path = rtrim( realpath( $options['plugin-path'] ), DIRECTORY_SEPARATOR );
$dry_run     = isset( $options['dry-run'] );
$restore     = isset( $options['restore'] );

if ( ! is_dir( $plugin_path ) ) {
	fwrite( STDERR, "Error: Plugin path does not exist: {$options['plugin-path']}\n" );
	exit( 1 );
}

// ──────────────────────────────────────────────
// Detect plugin type (Free vs Pro)
// ──────────────────────────────────────────────

$plugin_configs = array(
	'free' => array(
		'file'  => 'uncanny-automator.php',
		'const' => 'AUTOMATOR_PLUGIN_VERSION',
	),
	'pro'  => array(
		'file'  => 'uncanny-automator-pro.php',
		'const' => 'AUTOMATOR_PRO_PLUGIN_VERSION',
	),
);

$detected = null;

foreach ( $plugin_configs as $type => $config ) {
	$main_file = $plugin_path . DIRECTORY_SEPARATOR . $config['file'];
	if ( file_exists( $main_file ) ) {
		$detected = $type;
		break;
	}
}

if ( null === $detected ) {
	fwrite( STDERR, "Error: Could not find uncanny-automator.php or uncanny-automator-pro.php in: {$plugin_path}\n" );
	exit( 1 );
}

$main_file    = $plugin_path . DIRECTORY_SEPARATOR . $plugin_configs[ $detected ]['file'];
$version_const = $plugin_configs[ $detected ]['const'];

fwrite( STDOUT, "Detected: {$detected} plugin ({$plugin_configs[ $detected ]['file']})\n" );

// ──────────────────────────────────────────────
// Read the main plugin file
// ──────────────────────────────────────────────

$contents = file_get_contents( $main_file );

if ( false === $contents ) {
	fwrite( STDERR, "Error: Could not read {$main_file}\n" );
	exit( 1 );
}

// ──────────────────────────────────────────────
// Extract current version from the header
// ──────────────────────────────────────────────

// Match: * Version:             7.1.0.1
if ( ! preg_match( '/^\s*\*\s*Version:\s+(.+)$/m', $contents, $header_match ) ) {
	fwrite( STDERR, "Error: Could not find Version header in {$main_file}\n" );
	exit( 1 );
}

$current_version = trim( $header_match[1] );

// Match: define( 'AUTOMATOR_PLUGIN_VERSION', '7.1.0.1' );
$const_pattern = '/define\(\s*\'' . preg_quote( $version_const, '/' ) . '\'\s*,\s*\'([^\']+)\'\s*\)/';

if ( ! preg_match( $const_pattern, $contents, $const_match ) ) {
	fwrite( STDERR, "Error: Could not find {$version_const} define in {$main_file}\n" );
	exit( 1 );
}

$const_version = $const_match[1];

// ──────────────────────────────────────────────
// Restore mode — strip hash suffix
// ──────────────────────────────────────────────

if ( $restore ) {
	// Strip bleeding-edge stamp.
	// Current format: X.Y.Z.0.99-hash or X.Y.Z.W.0.99-hash
	// Legacy format:  X.Y.Z.99-hash
	$base_header = preg_replace( '/\.0\.99-[0-9a-f]{7,9}$/', '', $current_version );
	if ( $base_header === $current_version ) {
		$base_header = preg_replace( '/\.99-[0-9a-f]{7,12}$/', '', $current_version );
	}

	$base_const = preg_replace( '/\.0\.99-[0-9a-f]{7,9}$/', '', $const_version );
	if ( $base_const === $const_version ) {
		$base_const = preg_replace( '/\.99-[0-9a-f]{7,12}$/', '', $const_version );
	}

	if ( $base_header === $current_version && $base_const === $const_version ) {
		fwrite( STDOUT, "No hash suffix found — version is already clean: {$current_version}\n" );
		exit( 0 );
	}

	$new_contents = $contents;
	$new_contents = str_replace(
		$header_match[0],
		str_replace( $current_version, $base_header, $header_match[0] ),
		$new_contents
	);
	$new_contents = str_replace(
		$const_match[0],
		str_replace( $const_version, $base_const, $const_match[0] ),
		$new_contents
	);

	if ( $dry_run ) {
		fwrite( STDOUT, "[DRY RUN] Would restore:\n" );
		fwrite( STDOUT, "  Header:  {$current_version} -> {$base_header}\n" );
		fwrite( STDOUT, "  {$version_const}: {$const_version} -> {$base_const}\n" );
		exit( 0 );
	}

	file_put_contents( $main_file, $new_contents );
	fwrite( STDOUT, "Restored:\n" );
	fwrite( STDOUT, "  Header:  {$current_version} -> {$base_header}\n" );
	fwrite( STDOUT, "  {$version_const}: {$const_version} -> {$base_const}\n" );
	exit( 0 );
}

// ──────────────────────────────────────────────
// Get the commit hash
// ──────────────────────────────────────────────

if ( isset( $options['hash'] ) && '' !== trim( $options['hash'] ) ) {
	$hash = substr( trim( $options['hash'] ), 0, 9 );
} else {
	// Read from git in the plugin directory.
	$hash = trim( shell_exec( "cd " . escapeshellarg( $plugin_path ) . " && git rev-parse --short=9 HEAD 2>/dev/null" ) );

	if ( empty( $hash ) ) {
		fwrite( STDERR, "Error: Not a git repository or git is not available in: {$plugin_path}\n" );
		exit( 1 );
	}
}

// Validate hash is hex.
if ( ! preg_match( '/^[0-9a-f]{7,9}$/', $hash ) ) {
	fwrite( STDERR, "Error: Invalid commit hash: {$hash}\n" );
	exit( 1 );
}

// ──────────────────────────────────────────────
// Strip any existing stamp before appending
// ──────────────────────────────────────────────

// Current format: X.Y.Z.0.99-hash  Legacy: X.Y.Z.99-hash
$base_header = preg_replace( '/\.0\.99-[0-9a-f]{7,9}$/', '', $current_version );
if ( $base_header === $current_version ) {
	$base_header = preg_replace( '/\.99-[0-9a-f]{7,12}$/', '', $current_version );
}

// Append .0.99-<hash> so any official release at the next sub-version always wins.
// e.g. 7.2.0 → 7.2.0.0.99-abc1234d9  (7.2.0.1 > 7.2.0.0.99 ✓)
//      7.2.0.1 → 7.2.0.1.0.99-abc1234d9  (7.2.0.2 > 7.2.0.1.0.99 ✓)
$new_version = $base_header . '.0.99-' . $hash;

// ──────────────────────────────────────────────
// Apply the stamped version
// ──────────────────────────────────────────────

$new_contents = $contents;

// Replace header version.
$new_contents = str_replace(
	$header_match[0],
	str_replace( $current_version, $new_version, $header_match[0] ),
	$new_contents
);

// Replace CONST version.
$new_contents = str_replace(
	$const_match[0],
	str_replace( $const_version, $new_version, $const_match[0] ),
	$new_contents
);

if ( $dry_run ) {
	fwrite( STDOUT, "[DRY RUN] Would stamp:\n" );
	fwrite( STDOUT, "  Header:  {$current_version} -> {$new_version}\n" );
	fwrite( STDOUT, "  {$version_const}: {$const_version} -> {$new_version}\n" );
	exit( 0 );
}

file_put_contents( $main_file, $new_contents );

fwrite( STDOUT, "Stamped:\n" );
fwrite( STDOUT, "  Header:  {$current_version} -> {$new_version}\n" );
fwrite( STDOUT, "  {$version_const}: {$const_version} -> {$new_version}\n" );
exit( 0 );
