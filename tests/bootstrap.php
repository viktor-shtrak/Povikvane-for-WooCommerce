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

// WordPress time constants used by the code under test.
foreach ( array(
	'MINUTE_IN_SECONDS' => 60,
	'HOUR_IN_SECONDS'   => 3600,
	'DAY_IN_SECONDS'    => 86400,
) as $povikvane_constant => $povikvane_seconds ) {
	if ( ! defined( $povikvane_constant ) ) {
		define( $povikvane_constant, $povikvane_seconds );
	}
}

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

// Stand-in for the WordPress URL parser used by the site fingerprint.
if ( ! function_exists( 'wp_parse_url' ) ) {
	/**
	 * Minimal wp_parse_url().
	 *
	 * @param string $url URL.
	 * @return array<string,mixed>|false
	 */
	function wp_parse_url( string $url ) {
		return parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Test stand-in for wp_parse_url().
	}
}

require_once __DIR__ . '/Support/Fakes.php';
