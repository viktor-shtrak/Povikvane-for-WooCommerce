<?php
/**
 * PHPUnit bootstrap for unit tests (no WordPress loaded).
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

// Source files guard against direct access; satisfy the guard in unit tests.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require dirname( __DIR__ ) . '/vendor/autoload.php';

// Stand-in for the WordPress JSON helper used by the API client.
if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * Minimal wp_json_encode().
	 *
	 * @param mixed $data  Data.
	 * @param int   $flags Flags.
	 * @param int   $depth Depth.
	 * @return string|false
	 */
	function wp_json_encode( $data, int $flags = 0, int $depth = 512 ) {
		return json_encode( $data, $flags, $depth );
	}
}

require_once __DIR__ . '/Support/Fakes.php';
