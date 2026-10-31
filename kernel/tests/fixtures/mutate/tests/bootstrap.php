<?php
/**
 * The fixture plugin's test bootstrap.
 *
 * With MUTATE_FIXTURE_BOOT_WARNING=1 it raises a PHP warning while it boots and shows it on stderr, as
 * Automator does in a checkout without built assets ("Asset file not found", shown by the kernel on
 * stderr). A normal test run passes with it.
 */

// The test tools' autoloader: a Composer project of their own (tests/tools), as in Automator Free.
require __DIR__ . '/tools/vendor/autoload.php';

spl_autoload_register(
	static function ( $class ) {
		$file = dirname( __DIR__ ) . '/src/' . str_replace( '\\', '/', substr( $class, strlen( 'Fixture\\' ) ) ) . '.php';
		if ( 0 === strpos( $class, 'Fixture\\' ) && is_readable( $file ) ) {
			require $file;
		}
	}
);

if ( '1' === getenv( 'MUTATE_FIXTURE_BOOT_WARNING' ) ) {
	ini_set( 'display_errors', 'stderr' );
	trigger_error( 'Asset file not found for handle "fixture": build/fixture.asset.php', E_USER_WARNING );
}
