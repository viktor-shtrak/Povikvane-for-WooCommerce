<?php
/**
 * Signed opt-out links.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Marketing;

defined( 'ABSPATH' ) || exit;

/**
 * Builds and checks the short token in the unsubscribe link of a promotional SMS:
 * the number's hash plus a signature, 25 characters in all (every character is
 * paid for in an SMS). Without the signature nobody can opt another number out.
 */
final class OptOutToken {

	/**
	 * Constructor.
	 *
	 * @param string $secret Key used to sign tokens.
	 */
	public function __construct( private readonly string $secret ) {}

	/**
	 * Token for a number hash.
	 *
	 * @param string $phone_hash Keyed hash of the number.
	 */
	public function create( string $phone_hash ): string {
		return $phone_hash . '-' . $this->signature( $phone_hash );
	}

	/**
	 * The number hash in a valid token, or null.
	 *
	 * @param string $token Token from a link.
	 */
	public function verify( string $token ): ?string {
		if ( 1 !== preg_match( '/^([a-f0-9]{12})-([a-f0-9]{12})$/', $token, $parts ) ) {
			return null;
		}

		return hash_equals( $this->signature( $parts[1] ), $parts[2] ) ? $parts[1] : null;
	}

	/**
	 * Signature of a hash.
	 *
	 * @param string $phone_hash Keyed hash of the number.
	 */
	private function signature( string $phone_hash ): string {
		return substr( hash_hmac( 'sha256', 'optout|' . $phone_hash, $this->secret ), 0, 12 );
	}
}
