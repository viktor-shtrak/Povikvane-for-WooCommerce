<?php
/**
 * Phone number normalisation to E.164.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Phone;

defined( 'ABSPATH' ) || exit;

/**
 * Turns what customers type into an international number, using the order's
 * country calling code for numbers written nationally.
 */
final class Normalizer {

	/**
	 * Normalise a raw phone number.
	 *
	 * @param string $raw          What the customer entered.
	 * @param string $calling_code Calling code of the customer's country, with or without "+" (for example "+359").
	 */
	public function normalize( string $raw, string $calling_code ): PhoneResult {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return PhoneResult::failure( 'empty' );
		}

		$digits = preg_replace( '/\D+/', '', $raw ) ?? '';
		if ( '' === $digits ) {
			return PhoneResult::failure( 'invalid' );
		}

		$code = preg_replace( '/\D+/', '', $calling_code ) ?? '';

		if ( str_starts_with( $raw, '+' ) ) {
			$international = $digits;
		} elseif ( str_starts_with( $digits, '00' ) ) {
			$international = substr( $digits, 2 );
		} elseif ( '' === $code ) {
			return PhoneResult::failure( 'no_country' );
		} elseif ( str_starts_with( $digits, '0' ) ) {
			$international = $code . substr( $digits, 1 );
		} elseif ( str_starts_with( $digits, $code ) && strlen( $digits ) - strlen( $code ) >= 7 ) {
			$international = $digits;
		} else {
			$international = $code . $digits;
		}

		// "+359 (0) 888 123 456": drop the national trunk zero after the country code.
		if ( '' !== $code && str_starts_with( $international, $code . '0' ) ) {
			$international = $code . substr( $international, strlen( $code ) + 1 );
		}

		$length = strlen( $international );
		if ( $length < 8 || $length > 15 || str_starts_with( $international, '0' ) ) {
			return PhoneResult::failure( 'invalid' );
		}

		return PhoneResult::success( '+' . $international );
	}

	/**
	 * Whether a number is a mobile number, where we know the country's numbering plan.
	 *
	 * Only Bulgaria is checked for now (mobile prefixes 87, 88, 89, 98, 99 after +359).
	 * The prefix list should be re-verified against the national numbering plan
	 * before release.
	 *
	 * @param string $e164 Number including "+".
	 * @return bool|null True/false when known, null when the country is not checked.
	 */
	public function is_mobile( string $e164 ): ?bool {
		if ( str_starts_with( $e164, '+359' ) ) {
			return 1 === preg_match( '/^(8[789]|9[89])\d{7}$/', substr( $e164, 4 ) );
		}

		return null;
	}
}
