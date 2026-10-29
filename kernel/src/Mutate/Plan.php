<?php

namespace Uncanny_Owl\Wp_Test_Kernel\Mutate;

/**
 * Which tests judge which files.
 *
 * A file is judged by the *Test.php files directly in its folder's mirror (src/app/Transports/Model_Context_Protocol/Hosted_Bridge
 * → tests/wpunit/app/transports/model-context-protocol/hosted-bridge); a mirror folder with none borrows its
 * parent's, up to the mirror root. `also_tested_by` adds tests that reach a file from outside its mirror.
 * Files that share their tests share one run. Never the whole suite: a mutant is judged by the tests that own it.
 */
final class Plan {

	/**
	 * @param Scope    $scope
	 * @param string[] $files Plugin-relative.
	 *
	 * @return array{runs: array<int, array{files: string[], tests: string[]}>, untested: string[]}
	 */
	public static function build( Scope $scope, array $files ) {
		$runs     = array();
		$untested = array();

		foreach ( $files as $file ) {
			$tests = self::tests_for( $scope, $file );
			if ( empty( $tests ) ) {
				$untested[] = $file;
				continue;
			}
			$key = implode( "\n", $tests );
			if ( ! isset( $runs[ $key ] ) ) {
				$runs[ $key ] = array(
					'files' => array(),
					'tests' => $tests,
				);
			}
			$runs[ $key ]['files'][] = $file;
		}

		return array(
			'runs'     => array_values( $runs ),
			'untested' => $untested,
		);
	}

	/**
	 * @param Scope  $scope
	 * @param string $file
	 *
	 * @return string[] Plugin-relative test files, sorted.
	 */
	public static function tests_for( Scope $scope, $file ) {
		$tests = array();

		foreach ( (array) $scope->get( 'mirrors' ) as $source_prefix => $test_prefix ) {
			$source_prefix = trim( $source_prefix, '/' ) . '/';
			if ( 0 !== strpos( $file, $source_prefix ) ) {
				continue;
			}
			$dir = dirname( substr( $file, strlen( $source_prefix ) ) );
			while ( true ) {
				// Test folders use both styles: recipe-builder/ and Recipe_Builder/.
				$found = array();
				foreach ( array_unique( array( self::mirror_name( $dir ), $dir ) ) as $name ) {
					$mirror = trim( $test_prefix, '/' ) . ( '.' === $dir ? '' : '/' . $name );
					$found  = array_merge( $found, glob( $scope->root() . '/' . $mirror . '/*Test.php' ) ?: array() );
				}
				if ( ! empty( $found ) || '.' === $dir ) {
					$tests = array_merge( $tests, $found );
					break;
				}
				$dir = dirname( $dir );
			}
		}

		foreach ( (array) $scope->get( 'also_tested_by' ) as $source_prefix => $paths ) {
			$source_prefix = trim( $source_prefix, '/' );
			if ( $file !== $source_prefix && 0 !== strpos( $file, $source_prefix . '/' ) ) {
				continue;
			}
			foreach ( (array) $paths as $path ) {
				$tests = array_merge( $tests, self::tests_under( $scope->root() . '/' . trim( $path, '/' ) ) );
			}
		}

		$relative = array();
		foreach ( array_unique( $tests ) as $test ) {
			$relative[] = substr( $test, strlen( $scope->root() ) + 1 );
		}
		sort( $relative );

		return $relative;
	}

	/**
	 * "Transports/Model_Context_Protocol/OAuth" → "transports/model-context-protocol/oauth".
	 *
	 * @param string $dir
	 *
	 * @return string
	 */
	public static function mirror_name( $dir ) {
		return strtolower( str_replace( '_', '-', $dir ) );
	}

	/**
	 * @param string $path File or folder.
	 *
	 * @return string[]
	 */
	private static function tests_under( $path ) {
		if ( is_file( $path ) ) {
			return array( $path );
		}
		if ( ! is_dir( $path ) ) {
			return array();
		}
		$tests    = array();
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( '.php' !== substr( $file->getFilename(), -4 ) || 'Test.php' !== substr( $file->getFilename(), -8 ) ) {
				continue;
			}
			$tests[] = $file->getPathname();
		}

		return $tests;
	}
}
