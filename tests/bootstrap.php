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
	 * @param string $url       URL.
	 * @param int    $component Component, or -1 for all.
	 * @return mixed
	 */
	function wp_parse_url( string $url, int $component = -1 ) {
		return parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Test stand-in for wp_parse_url().
	}
}

// Stand-ins for the WordPress translation and number helpers: the text comes back untranslated.
// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter
// In-memory transients and text sanitising for classes that use them.
if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * Escape text for HTML.
	 *
	 * @param string $text Text.
	 */
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'home_url' ) ) {
	/**
	 * The shop address used in the tests.
	 */
	function home_url(): string {
		return 'https://shop.test/';
	}
}

if ( ! function_exists( 'untrailingslashit' ) ) {
	/**
	 * Remove a trailing slash.
	 *
	 * @param string $value Address.
	 */
	function untrailingslashit( string $value ): string {
		return rtrim( $value, '/\\' );
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	/**
	 * The one signed-in user the tests use.
	 */
	function get_current_user_id(): int {
		return 1;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	/**
	 * Minimal set_transient().
	 *
	 * @param string $name  Name.
	 * @param mixed  $value Value.
	 * @param int    $ttl   Seconds (ignored).
	 */
	function set_transient( string $name, $value, int $ttl = 0 ): bool {
		$GLOBALS['povikvane_test_transients'][ $name ] = $value;

		return true;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	/**
	 * Minimal get_transient().
	 *
	 * @param string $name Name.
	 * @return mixed
	 */
	function get_transient( string $name ) {
		return $GLOBALS['povikvane_test_transients'][ $name ] ?? false;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	/**
	 * Minimal delete_transient().
	 *
	 * @param string $name Name.
	 */
	function delete_transient( string $name ): bool {
		unset( $GLOBALS['povikvane_test_transients'][ $name ] );

		return true;
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * Minimal sanitize_text_field().
	 *
	 * @param string $text Text.
	 */
	function sanitize_text_field( string $text ): string {
		return trim( strip_tags( $text ) );
	}
}

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

if ( ! function_exists( '_x' ) ) {
	/**
	 * Untranslated text with a context.
	 *
	 * @param string $text    Text.
	 * @param string $context Context.
	 * @param string $domain  Text domain.
	 */
	function _x( string $text, string $context, string $domain = 'default' ): string {
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
