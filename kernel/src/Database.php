<?php

namespace Uncanny_Owl\Wp_Test_Kernel;

use RuntimeException;

/**
 * Server-level database work done through the mysql / mysqldump command-line clients.
 */
final class Database {

	/** @var string */
	private $host;

	/** @var string */
	private $port;

	/** @var string */
	private $user;

	/** @var string */
	private $password;

	/**
	 * @param string $db_host  WordPress DB_HOST format: host, host:port or host:/socket.
	 * @param string $user
	 * @param string $password
	 */
	public function __construct( $db_host, $user, $password ) {

		$parts          = explode( ':', (string) $db_host, 2 );
		$this->host     = $parts[0];
		$this->port     = isset( $parts[1] ) && ctype_digit( $parts[1] ) ? $parts[1] : '3306';
		$this->user     = (string) $user;
		$this->password = (string) $password;
	}

	/**
	 * @param Config $config
	 *
	 * @return self
	 */
	public static function from_config( Config $config ) {
		return new self( $config->get( 'db_host' ), $config->get( 'db_user' ), $config->get( 'db_password' ) );
	}

	/**
	 * Drop and create a database.
	 *
	 * @param string $name
	 *
	 * @return void
	 */
	public function recreate( $name ) {
		$this->assert_name( $name );
		$this->sql( sprintf( 'DROP DATABASE IF EXISTS `%1$s`; CREATE DATABASE `%1$s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci;', $name ) );
	}

	/**
	 * @param string $name
	 *
	 * @return void
	 */
	public function drop( $name ) {
		$this->assert_name( $name );
		$this->sql( sprintf( 'DROP DATABASE IF EXISTS `%s`;', $name ) );
	}

	/**
	 * @param string $name
	 * @param string $file
	 *
	 * @return void
	 */
	public function dump( $name, $file ) {
		$this->assert_name( $name );
		$command = sprintf(
			'mysqldump %s --single-transaction --skip-comments --skip-dump-date --no-tablespaces --set-gtid-purged=OFF --routines --triggers %s > %s',
			$this->connection_args(),
			escapeshellarg( $name ),
			escapeshellarg( $file )
		);
		$this->run( $command );
	}

	/**
	 * @param string $name
	 * @param string $file
	 *
	 * @return void
	 */
	public function import( $name, $file ) {
		$this->assert_name( $name );
		$command = sprintf( 'mysql %s %s < %s', $this->connection_args(), escapeshellarg( $name ), escapeshellarg( $file ) );
		$this->run( $command );
	}

	/**
	 * @param string $statement
	 *
	 * @return string
	 */
	public function sql( $statement ) {
		$command = sprintf( 'mysql %s -N -B -e %s', $this->connection_args(), escapeshellarg( $statement ) );

		return $this->run( $command );
	}

	/**
	 * @return string
	 */
	private function connection_args() {
		return sprintf( '-h %s -P %s -u %s', escapeshellarg( $this->host ), escapeshellarg( $this->port ), escapeshellarg( $this->user ) );
	}

	/**
	 * @param string $command
	 *
	 * @return string
	 */
	private function run( $command ) {

		$env = getenv();
		if ( '' !== $this->password ) {
			$env['MYSQL_PWD'] = $this->password;
		}

		$process = proc_open( $command, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, null, $env );
		if ( ! is_resource( $process ) ) {
			throw new RuntimeException( 'Could not start: ' . $command );
		}

		$stdout = (string) stream_get_contents( $pipes[1] );
		$stderr = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$exit = proc_close( $process );

		if ( 0 !== $exit ) {
			throw new RuntimeException( sprintf( "Database command failed (%d): %s\n%s", $exit, $command, trim( $stderr ) ) );
		}

		return $stdout;
	}

	/**
	 * @param string $name
	 *
	 * @return void
	 */
	private function assert_name( $name ) {
		if ( 1 !== preg_match( '/^[A-Za-z0-9_]{1,64}$/', (string) $name ) ) {
			throw new RuntimeException( 'Unsafe database name: ' . $name );
		}
	}
}
