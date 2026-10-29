<?php

namespace Uncanny_Owl\Wp_Test_Kernel;

/**
 * One database per test worker, restored from the snapshot when the worker starts.
 */
final class Worker_Database {

	const READY_ENV = 'WP_TEST_KERNEL_DB_READY';

	/**
	 * Restore the snapshot into this worker's database, unless this process is a PHPUnit child
	 * (process isolation) of a worker that already did.
	 *
	 * @param Config $config
	 * @param string $snapshot
	 *
	 * @return string The database name.
	 */
	public static function prepare( Config $config, $snapshot ) {

		$name = $config->worker_db_name();

		if ( getenv( self::READY_ENV ) === $name ) {
			return $name;
		}

		$database = Database::from_config( $config );
		$database->recreate( $name );
		$database->import( $name, $snapshot );

		// Inherited by PHPUnit's isolated child processes, which boot against the same database.
		putenv( self::READY_ENV . '=' . $name );
		$_ENV[ self::READY_ENV ] = $name;

		return $name;
	}
}
