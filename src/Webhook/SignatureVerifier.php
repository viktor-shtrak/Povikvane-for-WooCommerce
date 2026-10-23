<?php
/**
 * Checks the signature Povikvane puts on a webhook.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Webhook;

defined( 'ABSPATH' ) || exit;

/**
 * The signature is HMAC-SHA256 over "timestamp.rawBody", keyed with the whole "whsec_"
 * string. During a secret rotation the header holds two v1 values; either one may match.
 */
final class SignatureVerifier {

	public const TOLERANCE_SECONDS = 300;

	/**
	 * Whether a request is properly signed.
	 *
	 * @param string $raw_body The request bytes, before any decoding.
	 * @param string $header   Value of the Povikvane-Signature header.
	 * @param string $secret   The stored secret.
	 * @param int    $now      Current time.
	 */
	public static function verify( string $raw_body, string $header, string $secret, int $now ): bool {
		if ( '' === $secret || strlen( $header ) > 4096 ) {
			return false;
		}

		$parts      = array_map( static fn( string $part ): array => explode( '=', trim( $part ) ), explode( ',', $header ) );
		$timestamps = array_values( array_filter( $parts, static fn( array $part ): bool => 2 === count( $part ) && 't' === $part[0] ) );

		if ( 1 !== count( $timestamps ) || 1 !== preg_match( '/^[0-9]+$/D', $timestamps[0][1] ) ) {
			return false;
		}

		$timestamp = $timestamps[0][1];

		if ( strlen( $timestamp ) > 15 || abs( $now - (int) $timestamp ) > self::TOLERANCE_SECONDS ) {
			return false;
		}

		$expected = hash_hmac( 'sha256', $timestamp . '.' . $raw_body, $secret );

		foreach ( $parts as $part ) {
			if ( 2 === count( $part ) && 'v1' === $part[0] && 1 === preg_match( '/^[0-9a-f]{64}$/iD', $part[1] ) && hash_equals( $expected, strtolower( $part[1] ) ) ) {
				return true;
			}
		}

		return false;
	}
}
