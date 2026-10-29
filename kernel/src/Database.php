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

	/** @var string Unix socket path from a host:/socket DB_HOST (LocalWP), else ''. */
	private $socket;

	/** @var string */
	private $user;

	/** @var string */
	private $password;

	/** @var string The configured db_name, which every database the kernel creates starts with. */
	private $name_prefix;

	/**
	 * Connections that hold a worker database's lock until the process ends.
	 *
	 * @var \mysqli[]
	 */
	private static $claims = array();

	/**
	 * @param string $db_host     WordPress DB_HOST format: host, host:port or host:/socket.
	 * @param string $user
	 * @param string $password
	 * @param string $name_prefix The configured db_name, used in error advice.
	 */
	public function __construct( $db_host, $user, $password, $name_prefix = '' ) {

		list( $this->host, $this->port, $this->socket ) = self::parse_host( $db_host );

		$this->user        = (string) $user;
		$this->password    = (string) $password;
		$this->name_prefix = (string) $name_prefix;
	}

	/**
	 * Host, port and socket from a WordPress DB_HOST: host, host:port or host:/path/to/socket.
	 *
	 * @param string $db_host
	 *
	 * @return array{0: string, 1: string, 2: string}
	 */
	public static function parse_host( $db_host ) {
		$parts  = explode( ':', (string) $db_host, 2 );
		$host   = '' === $parts[0] ? 'localhost' : $parts[0];
		$rest   = $parts[1] ?? '';
		$socket = '' !== $rest && '/' === $rest[0] ? $rest : '';

		return array( $host, ctype_digit( $rest ) ? $rest : '3306', $socket );
	}

	/**
	 * @param Config $config
	 *
	 * @return self
	 */
	public static function from_config( Config $config ) {
		return new self( $config->get( 'db_host' ), $config->get( 'db_user' ), $config->get( 'db_password' ), $config->get( 'db_name' ) );
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
	 * Hold a MySQL lock on a database's name until this process ends, so a second test run that
	 * would restore the same database (another terminal, an agent, another checkout on the same
	 * server) stops instead of wiping it under this one.
	 *
	 * @param string $name
	 *
	 * @return bool False when another session holds the lock. True when this process holds it, or
	 *              when no lock can be taken (no mysqli, no connection): then the database
	 *              commands that follow report the problem.
	 */
	public function claim( $name ) {
		$this->assert_name( $name );

		if ( isset( self::$claims[ $name ] ) ) {
			return true;
		}
		if ( ! function_exists( 'mysqli_init' ) ) {
			return true;
		}

		mysqli_report( MYSQLI_REPORT_OFF );
		$connection = mysqli_init();
		if ( false === $connection || ! @$connection->real_connect( $this->host, $this->user, $this->password, '', (int) $this->port, '' === $this->socket ? null : $this->socket ) ) {
			return true;
		}

		$lock   = strlen( $name ) <= 59 ? 'wptk:' . $name : 'wptk:' . md5( $name );
		$result = $connection->query( "SELECT GET_LOCK('" . $connection->real_escape_string( $lock ) . "', 0)" );
		$held   = $result instanceof \mysqli_result ? $result->fetch_row() : null;

		if ( is_array( $held ) && '0' === (string) $held[0] ) {
			$connection->close();
			return false;
		}

		self::$claims[ $name ] = $connection;

		return true;
	}

	/**
	 * What to do about a failed database command, from the client's error output; '' when there is
	 * no advice for it.
	 *
	 * @param string $stderr      The mysql client's error output.
	 * @param string $user        The configured MySQL user.
	 * @param string $host
	 * @param string $port
	 * @param string $name_prefix The configured db_name.
	 *
	 * @return string
	 */
	public static function advice( $stderr, $user, $host, $port, $name_prefix ) {

		if ( false !== strpos( $stderr, 'ERROR 1044' ) ) {
			$account = preg_match( "/for user '([^']*)'@'([^']*)'/", $stderr, $match ) ? "'" . $match[1] . "'@'" . $match[2] . "'" : "'" . $user . "'@'%'";
			$pattern = '' === $name_prefix ? '%' : str_replace( '_', '\\_', $name_prefix ) . '\\_%';

			return sprintf(
				"MySQL user '%1\$s' may not create the kernel's databases. The kernel never uses your site's database: it creates its own, named %2\$s_* (a scratch one to build the snapshot, then one per worker), and drops and restores them. Use a MySQL user that can create databases (on DevStack: root, no password; WP_TEST_KERNEL_DB_USER and WP_TEST_KERNEL_DB_PASSWORD override the plugin's settings), or grant this one: GRANT ALL PRIVILEGES ON `%3\$s`.* TO %4\$s;",
				$user,
				'' === $name_prefix ? 'after db_name' : $name_prefix,
				$pattern,
				$account
			);
		}

		if ( false !== strpos( $stderr, 'ERROR 1045' ) ) {
			return sprintf( "MySQL refused user '%s': wrong user or password. Set them where the plugin's test bootstrap reads them, or override them with WP_TEST_KERNEL_DB_USER and WP_TEST_KERNEL_DB_PASSWORD.", $user );
		}

		if ( false !== strpos( $stderr, 'ERROR 2002' ) || false !== strpos( $stderr, 'ERROR 2003' ) || false !== strpos( $stderr, 'ERROR 2005' ) ) {
			return sprintf( 'No MySQL server answered at %s:%s. Start it (on DevStack: the MySQL service), or point the kernel elsewhere with WP_TEST_KERNEL_DB_HOST.', $host, $port );
		}

		return '';
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
		if ( '' !== $this->socket ) {
			return sprintf( '--socket=%s -u %s', escapeshellarg( $this->socket ), escapeshellarg( $this->user ) );
		}

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
			$advice = self::advice( $stderr, $this->user, $this->host, $this->port, $this->name_prefix );
			throw new RuntimeException( sprintf( "Database command failed (%d): %s\n%s%s", $exit, $command, trim( $stderr ), '' === $advice ? '' : "\n\n" . $advice ) );
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
