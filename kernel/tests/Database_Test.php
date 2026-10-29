<?php
/**
 * Database error advice and the worker database claim.
 */

use PHPUnit\Framework\TestCase;
use Uncanny_Owl\Wp_Test_Kernel\Database;

class Database_Test extends TestCase {

	public function test_access_denied_to_a_database_names_the_kernels_databases_and_the_grant(): void {
		$stderr = "ERROR 1044 (42000) at line 1: Access denied for user 'uo_site'@'127.0.0.1' to database 'automator_kernel_build_3788'";

		$advice = Database::advice( $stderr, 'uo_site', '127.0.0.1', '3306', 'automator_kernel' );

		$this->assertStringContainsString( "MySQL user 'uo_site' may not create the kernel's databases", $advice );
		$this->assertStringContainsString( 'named automator_kernel_*', $advice );
		$this->assertStringContainsString( "GRANT ALL PRIVILEGES ON `automator\\_kernel\\_%`.* TO 'uo_site'@'127.0.0.1';", $advice );
		$this->assertStringContainsString( 'WP_TEST_KERNEL_DB_USER', $advice );
	}

	public function test_a_refused_login_names_the_user_and_the_overrides(): void {
		$advice = Database::advice( "ERROR 1045 (28000): Access denied for user 'x'@'localhost' (using password: YES)", 'x', 'localhost', '3306', 'automator_kernel' );

		$this->assertStringContainsString( "MySQL refused user 'x'", $advice );
		$this->assertStringContainsString( 'WP_TEST_KERNEL_DB_PASSWORD', $advice );
	}

	public function test_no_server_names_the_host_and_port(): void {
		$advice = Database::advice( "ERROR 2003 (HY000): Can't connect to MySQL server on '127.0.0.1:3307' (61)", 'root', '127.0.0.1', '3307', 'automator_kernel' );

		$this->assertStringContainsString( 'No MySQL server answered at 127.0.0.1:3307', $advice );
	}

	public function test_other_errors_get_no_advice(): void {
		$this->assertSame( '', Database::advice( "ERROR 1064 (42000): You have an error in your SQL syntax", 'root', '127.0.0.1', '3306', 'automator_kernel' ) );
	}

	public function test_db_host_forms_wordpress_accepts(): void {
		$this->assertSame( array( '127.0.0.1', '3306', '' ), Database::parse_host( '127.0.0.1' ) );
		$this->assertSame( array( '127.0.0.1', '10011', '' ), Database::parse_host( '127.0.0.1:10011' ) );
		$this->assertSame( array( 'localhost', '3306', '/Users/me/Library/Application Support/Local/run/abc/mysql/mysqld.sock' ), Database::parse_host( 'localhost:/Users/me/Library/Application Support/Local/run/abc/mysql/mysqld.sock' ), 'LocalWP connects through a socket.' );
	}

	public function test_a_second_session_cannot_claim_a_database_this_one_holds(): void {
		$host = getenv( 'WP_TEST_KERNEL_DB_HOST' ) ?: '127.0.0.1:3306';
		$user = getenv( 'WP_TEST_KERNEL_DB_USER' ) ?: 'root';
		$pass = (string) getenv( 'WP_TEST_KERNEL_DB_PASSWORD' );
		$this->skip_without_mysql( $host, $user, $pass );

		$name = 'wptk_claim_test_' . getmypid();

		$this->assertTrue( ( new Database( $host, $user, $pass ) )->claim( $name ), 'The first claim holds the lock.' );
		$this->assertTrue( ( new Database( $host, $user, $pass ) )->claim( $name ), 'This process may claim it again.' );

		$other = $this->claim_in_another_process( $host, $user, $pass, $name );
		$this->assertSame( 'false', $other, 'Another process cannot claim it while this one holds it.' );
	}

	private function skip_without_mysql( string $host, string $user, string $pass ): void {
		if ( ! function_exists( 'mysqli_init' ) ) {
			$this->markTestSkipped( 'mysqli is not loaded.' );
		}
		mysqli_report( MYSQLI_REPORT_OFF );
		$parts      = explode( ':', $host, 2 );
		$connection = mysqli_init();
		if ( ! @$connection->real_connect( $parts[0], $user, $pass, '', (int) ( $parts[1] ?? 3306 ) ) ) {
			$this->markTestSkipped( 'No MySQL server at ' . $host . ' for user ' . $user . '.' );
		}
		$connection->close();
	}

	private function claim_in_another_process( string $host, string $user, string $pass, string $name ): string {
		$autoload = dirname( __DIR__, 2 ) . '/vendor/autoload.php';
		$code     = sprintf(
			'require %s; var_export( ( new Uncanny_Owl\Wp_Test_Kernel\Database( %s, %s, %s ) )->claim( %s ) );',
			var_export( $autoload, true ),
			var_export( $host, true ),
			var_export( $user, true ),
			var_export( $pass, true ),
			var_export( $name, true )
		);

		return trim( (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $code ) ) );
	}
}
