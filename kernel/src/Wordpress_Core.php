<?php

namespace Uncanny_Owl\Wp_Test_Kernel;

use PharData;
use RuntimeException;

/**
 * The kernel's own WordPress, used when no WordPress install is named (wp_root unset): a release
 * from wordpress.org, downloaded once per machine and checked against its published SHA-1, then
 * extracted once per checkout beside it in the downloads folder. Its wp-content is the release's
 * own (default themes, no must-use plugins, no drop-ins), so a dev machine's site setup does not
 * reach the tests.
 */
final class Wordpress_Core {

	const MARKER = '.wp-test-kernel';

	/**
	 * Make sure the configured release is extracted at $config->wp_root().
	 *
	 * @param Config $config
	 *
	 * @return void
	 */
	public static function ensure( Config $config ) {

		if ( ! $config->uses_kernel_wordpress() ) {
			return;
		}

		$root = $config->wp_root();
		if ( self::is_complete( $root ) ) {
			return;
		}

		self::make_dir( $config->downloads_dir() );

		// Parallel workers all reach this point at once; one extracts, the rest wait and reuse.
		$lock = self::lock( rtrim( $root, '/' ) . '.lock' );

		try {
			if ( ! self::is_complete( $root ) ) {
				$archive = self::archive( (string) $config->get( 'wp_version' ), $config->downloads_dir() );
				self::extract( $archive, $root );
			}
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}
	}

	/**
	 * The release archive in the machine-wide downloads folder, downloaded and verified if missing.
	 *
	 * @param string $version
	 * @param string $downloads_dir
	 *
	 * @return string Path of the verified .tar.gz.
	 */
	public static function archive( $version, $downloads_dir ) {

		self::make_dir( $downloads_dir );

		$archive  = $downloads_dir . '/wordpress-' . $version . '.tar.gz';
		$checksum = $archive . '.sha1';

		if ( self::verified( $archive, $checksum ) ) {
			return $archive;
		}

		$lock = self::lock( $archive . '.lock' );

		try {
			if ( ! self::verified( $archive, $checksum ) ) {
				$url = 'https://wordpress.org/wordpress-' . $version . '.tar.gz';
				self::download( $url . '.sha1', $checksum, $version );
				self::download( $url, $archive, $version );

				if ( ! self::verified( $archive, $checksum ) ) {
					@unlink( $archive );
					throw new RuntimeException( sprintf( 'The WordPress %s download does not match the SHA-1 wordpress.org publishes for it; it was deleted. Run again, or set WP_ROOT_FOLDER to a WordPress install.', $version ) );
				}
			}
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}

		return $archive;
	}

	/**
	 * @param string $archive
	 * @param string $checksum
	 *
	 * @return bool
	 */
	private static function verified( $archive, $checksum ) {
		if ( ! is_readable( $archive ) || ! is_readable( $checksum ) ) {
			return false;
		}

		$expected = strtolower( substr( trim( (string) file_get_contents( $checksum ) ), 0, 40 ) );

		return 1 === preg_match( '/^[0-9a-f]{40}$/', $expected ) && hash_equals( $expected, (string) sha1_file( $archive ) );
	}

	/**
	 * @param string $url
	 * @param string $file
	 * @param string $version
	 *
	 * @return void
	 */
	private static function download( $url, $file, $version ) {

		$context = stream_context_create(
			array(
				'http' => array(
					'timeout'       => 120,
					'ignore_errors' => true,
					'header'        => "User-Agent: wp-test-kernel\r\n",
				),
			)
		);
		$body    = @file_get_contents( $url, false, $context );
		$headers = (array) http_get_last_response_headers();
		$status  = isset( $headers[0] ) && preg_match( '/\s(\d{3})\s?/', (string) $headers[0], $match ) ? (int) $match[1] : 0;

		if ( false === $body || 200 !== $status ) {
			throw new RuntimeException(
				sprintf(
					'Could not download WordPress %1$s from wordpress.org (%2$s). %3$s',
					$version,
					0 === $status ? 'no answer' : 'HTTP ' . $status,
					404 === $status ? 'That release does not exist: check wp_version or WP_TEST_KERNEL_WP_VERSION.' : 'The first run needs the network once per release; or set WP_ROOT_FOLDER to a WordPress install.'
				)
			);
		}

		$temporary = $file . '.' . getmypid() . '.tmp';
		file_put_contents( $temporary, $body );
		rename( $temporary, $file );
	}

	/**
	 * Extract the archive's wordpress/ folder to $root, replacing a partial one.
	 *
	 * @param string $archive
	 * @param string $root
	 *
	 * @return void
	 */
	private static function extract( $archive, $root ) {

		$root      = rtrim( $root, '/' );
		$temporary = $root . '.' . getmypid() . '.tmp';
		self::remove( $temporary );
		self::remove( $root );

		( new PharData( $archive ) )->extractTo( $temporary, null, true );

		if ( ! is_readable( $temporary . '/wordpress/wp-settings.php' ) ) {
			self::remove( $temporary );
			throw new RuntimeException( 'The WordPress archive ' . $archive . ' holds no wordpress/wp-settings.php; delete it and run again.' );
		}

		rename( $temporary . '/wordpress', $root );
		self::remove( $temporary );

		// As a fresh install has it, and as CI's checkout does.
		self::make_dir( $root . '/wp-content/uploads' );

		file_put_contents( $root . '/' . self::MARKER, basename( $archive ) . ' ' . sha1_file( $archive ) . "\n" );
	}

	/**
	 * @param string $root
	 *
	 * @return bool
	 */
	private static function is_complete( $root ) {
		return is_readable( rtrim( $root, '/' ) . '/wp-settings.php' ) && is_readable( rtrim( $root, '/' ) . '/' . self::MARKER );
	}

	/**
	 * @param string $path
	 *
	 * @return resource
	 */
	private static function lock( $path ) {
		$lock = fopen( $path, 'c' );
		if ( false === $lock || ! flock( $lock, LOCK_EX ) ) {
			throw new RuntimeException( 'Cannot lock ' . $path . '.' );
		}

		return $lock;
	}

	/**
	 * @param string $dir
	 *
	 * @return void
	 */
	private static function make_dir( $dir ) {
		if ( ! is_dir( $dir ) && ! mkdir( $dir, 0777, true ) && ! is_dir( $dir ) ) {
			throw new RuntimeException( 'Cannot create ' . $dir . '.' );
		}
	}

	/**
	 * @param string $path
	 *
	 * @return void
	 */
	private static function remove( $path ) {
		if ( is_link( $path ) || is_file( $path ) ) {
			unlink( $path );
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}

		$items = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $items as $item ) {
			$item->isDir() && ! $item->isLink() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
		rmdir( $path );
	}
}
