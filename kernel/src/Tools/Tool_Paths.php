<?php

namespace Uncanny_Owl\Wp_Test_Kernel\Tools;

use RuntimeException;

/**
 * Where the tools Developer-Tools was installed with live (PHPUnit, Infection, PHPCS, PHPMD).
 *
 * A plugin either requires Developer-Tools in its own composer.json, so the tools land in the plugin's
 * vendor/, or keeps its test tools in a Composer project of their own (Automator Free:
 * tests/tools/composer.json), so that WordPress, which requires the plugin's vendor/autoload.php on
 * every request, never loads a dev package. Either way the tools sit in the vendor/ folder
 * Developer-Tools was installed into, which need not be the plugin's. The plugin a command works on is
 * a separate fact: its --plugin-path, or the folder the command runs in.
 *
 * This file has no dependencies, so the bin/ scripts that run without an autoloader can require it.
 */
final class Tool_Paths {

	/**
	 * The vendor/ folder Developer-Tools was installed into: <vendor>/uncanny-owl/developer-tools as a
	 * dependency, or the checkout's own vendor/ when Developer-Tools is the root project (the order
	 * bin/mutate and bin/wp-test-kernel look for an autoloader in).
	 *
	 * @param string|null $package_root Developer-Tools' own folder; null for the one holding this file.
	 *
	 * @return string
	 *
	 * @throws RuntimeException When neither folder holds a Composer autoloader.
	 */
	public static function vendor_dir( $package_root = null ) {
		$package_root = rtrim( null === $package_root ? dirname( __DIR__, 3 ) : (string) $package_root, '/' );

		foreach ( array( $package_root . '/vendor', dirname( $package_root, 2 ) ) as $vendor ) {
			if ( is_file( $vendor . '/autoload.php' ) ) {
				return $vendor;
			}
		}

		throw new RuntimeException( "Developer-Tools at $package_root is neither a Composer project with its own vendor/ nor installed in one (<vendor>/uncanny-owl/developer-tools): run composer install." );
	}

	/**
	 * Composer's binaries for that vendor/ folder (vendor/bin), such as phpunit, infection and phpcs.
	 *
	 * @param string|null $package_root Developer-Tools' own folder; null for the one holding this file.
	 *
	 * @return string
	 */
	public static function bin_dir( $package_root = null ) {
		return self::vendor_dir( $package_root ) . '/bin';
	}
}
