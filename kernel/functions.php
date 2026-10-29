<?php
/**
 * Stand-ins for the Codeception helper functions existing tests still call.
 */

use Uncanny_Owl\Wp_Test_Kernel\Boot;

if ( ! function_exists( 'codecept_root_dir' ) ) {
	/**
	 * @param string $append_path
	 *
	 * @return string
	 */
	function codecept_root_dir( $append_path = '' ) {
		return Boot::config()->plugin_root() . '/' . ltrim( $append_path, '/' );
	}
}

if ( ! function_exists( 'codecept_data_dir' ) ) {
	/**
	 * @param string $append_path
	 *
	 * @return string
	 */
	function codecept_data_dir( $append_path = '' ) {
		return rtrim( (string) Boot::config()->get( 'data_dir' ), '/' ) . '/' . ltrim( $append_path, '/' );
	}
}

if ( ! function_exists( 'codecept_output_dir' ) ) {
	/**
	 * @param string $append_path
	 *
	 * @return string
	 */
	function codecept_output_dir( $append_path = '' ) {
		return rtrim( (string) Boot::config()->get( 'output_dir' ), '/' ) . '/' . ltrim( $append_path, '/' );
	}
}

if ( ! function_exists( 'codecept_debug' ) ) {
	/**
	 * Prints only when WP_TEST_KERNEL_DEBUG=1, like Codeception's --debug.
	 *
	 * @param mixed $data
	 *
	 * @return void
	 */
	function codecept_debug( $data ) {
		if ( '1' === getenv( 'WP_TEST_KERNEL_DEBUG' ) ) {
			fwrite( STDERR, ( is_string( $data ) ? $data : print_r( $data, true ) ) . PHP_EOL ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
	}
}
