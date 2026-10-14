<?php
/**
 * Country helpers backed by WooCommerce.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Support;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce country list and calling codes.
 */
final class Countries {

	/**
	 * Country names keyed by ISO code.
	 *
	 * @return array<string,string>
	 */
	public static function all(): array {
		return function_exists( 'WC' ) ? (array) WC()->countries->get_countries() : array();
	}

	/**
	 * Calling code for a country, for example "+359"; empty if unknown.
	 *
	 * @param string $iso ISO 3166-1 alpha-2 code.
	 */
	public static function calling_code( string $iso ): string {
		if ( ! function_exists( 'WC' ) ) {
			return '';
		}

		$code = WC()->countries->get_country_calling_code( $iso );
		if ( is_array( $code ) ) {
			$code = reset( $code );
		}

		return is_string( $code ) ? $code : '';
	}
}
