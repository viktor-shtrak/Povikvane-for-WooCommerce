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

// In-memory options for classes that read WordPress options.
$GLOBALS['povikvane_test_options'] = array();

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Minimal get_option().
	 *
	 * @param string $name          Option name.
	 * @param mixed  $default_value Default.
	 * @return mixed
	 */
	function get_option( string $name, $default_value = false ) {
		return $GLOBALS['povikvane_test_options'][ $name ] ?? $default_value;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * Minimal update_option().
	 *
	 * @param string $name  Option name.
	 * @param mixed  $value Value.
	 * @return bool
	 */
	function update_option( string $name, $value ): bool {
		$GLOBALS['povikvane_test_options'][ $name ] = $value;

		return true;
	}
}

if ( ! function_exists( 'get_locale' ) ) {
	/**
	 * Minimal get_locale().
	 *
	 * @return string
	 */
	function get_locale(): string {
		return $GLOBALS['povikvane_test_locale'] ?? 'en_US';
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	/**
	 * Minimal delete_option().
	 *
	 * @param string $name Option name.
	 * @return bool
	 */
	function delete_option( string $name ): bool {
		unset( $GLOBALS['povikvane_test_options'][ $name ] );

		return true;
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

// Stand-ins for the WordPress translation and number helpers: the text comes back untranslated.
// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter
if ( ! function_exists( '__' ) ) {
	/**
	 * Untranslated text.
	 *
	 * @param string $text   Text.
	 * @param string $domain Text domain.
	 */
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( '_n' ) ) {
	/**
	 * Untranslated singular or plural text.
	 *
	 * @param string $single Singular text.
	 * @param string $plural Plural text.
	 * @param int    $number Number.
	 * @param string $domain Text domain.
	 */
	function _n( string $single, string $plural, int $number, string $domain = 'default' ): string {
		return 1 === $number ? $single : $plural;
	}
}

if ( ! function_exists( 'number_format_i18n' ) ) {
	/**
	 * Plain number formatting.
	 *
	 * @param float $number   Number.
	 * @param int   $decimals Decimals.
	 */
	function number_format_i18n( float $number, int $decimals = 0 ): string {
		return number_format( $number, $decimals, '.', ',' );
	}
}

// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter

require_once __DIR__ . '/Support/Fakes.php';
