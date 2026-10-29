<?php

namespace Uncanny_Owl\Wp_Test_Kernel;

use RuntimeException;

/**
 * A database dump of a fresh WordPress install with the profile's plugins activated.
 *
 * Built once per cache key, then restored into every worker database. The key covers the WordPress
 * version, the plugin list, the files that define the plugin's schema, and the kernel version, so a
 * schema bump rebuilds it without a manual reset.
 */
final class Snapshot {

	/**
	 * Return the snapshot file for this configuration, building it first if needed.
	 *
	 * @param Config $config
	 *
	 * @return string Absolute path of the SQL dump.
	 */
	public static function ensure( Config $config ) {

		$path = self::path( $config );
		if ( is_readable( $path ) ) {
			return $path;
		}

		if ( ! is_dir( $config->cache_dir() ) && ! mkdir( $config->cache_dir(), 0777, true ) && ! is_dir( $config->cache_dir() ) ) {
			throw new RuntimeException( 'Cannot create cache directory ' . $config->cache_dir() );
		}

		// Parallel workers all reach this point at once; one builds, the rest wait and reuse.
		$lock = fopen( $config->cache_dir() . '/snapshot-' . $config->profile() . '.lock', 'c' );
		if ( false === $lock || ! flock( $lock, LOCK_EX ) ) {
			throw new RuntimeException( 'Cannot lock the snapshot cache.' );
		}

		try {
			if ( ! is_readable( $path ) ) {
				self::build( $config, $path );
			}
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}

		return $path;
	}

	/**
	 * @param Config $config
	 *
	 * @return string
	 */
	public static function path( Config $config ) {
		return $config->cache_dir() . '/snapshot-' . $config->profile() . '-' . self::key( $config ) . '.sql';
	}

	/**
	 * @param Config $config
	 *
	 * @return string
	 */
	public static function key( Config $config ) {

		$parts = array(
			'kernel'    => Config::KERNEL_VERSION,
			'wordpress' => self::wordpress_version( $config ),
			'profile'   => $config->profile(),
			'settings'  => $config->to_wordpress_config( '' ),
			'files'     => array(),
		);

		foreach ( (array) $config->get( 'snapshot_key_files' ) as $file ) {
			$parts['files'][ $file ] = is_readable( $file ) ? sha1_file( $file ) : 'missing';
		}

		return substr( sha1( (string) json_encode( $parts ) ), 0, 12 );
	}

	/**
	 * @param Config $config
	 *
	 * @return string
	 */
	public static function wordpress_version( Config $config ) {
		$wp_version = '';
		require $config->wp_root() . 'wp-includes/version.php';

		return (string) $wp_version;
	}

	/**
	 * Install WordPress and activate the plugins in a scratch database (in a child PHP process, so
	 * the test process never loads WordPress in install mode), then dump it.
	 *
	 * @param Config $config
	 * @param string $path
	 *
	 * @return void
	 */
	private static function build( Config $config, $path ) {

		$database = Database::from_config( $config );
		$scratch  = $config->get( 'db_name' ) . '_build_' . getmypid();
		$database->recreate( $scratch );

		try {
			$payload = base64_encode( (string) json_encode( $config->to_wordpress_config( $scratch ) ) );
			$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( dirname( __DIR__ ) . '/install.php' ) . ' ' . escapeshellarg( $payload ) . ' 2>&1';

			$output = array();
			$exit   = 0;
			exec( $command, $output, $exit );

			if ( 0 !== $exit ) {
				throw new RuntimeException( "Snapshot install failed:\n" . implode( "\n", $output ) );
			}
			$notes = strstr( implode( "\n", $output ), 'Note: ' );
			if ( false !== $notes ) {
				fwrite( STDERR, "wp-test-kernel snapshot:\n" . $notes . "\n" );
			}

			$temporary = $path . '.' . getmypid() . '.tmp';
			$database->dump( $scratch, $temporary );
			rename( $temporary, $path );
		} finally {
			$database->drop( $scratch );
		}
	}
}
