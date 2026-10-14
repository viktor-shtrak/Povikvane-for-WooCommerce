<?php
/**
 * The secret in the webhook URL.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Webhook;

defined( 'ABSPATH' ) || exit;

/**
 * Povikvane does not sign webhooks yet, so the URL itself is the secret: 64 random
 * hexadecimal characters, created on first use. Anyone who learns it can only
 * report results for messages that already exist, but it can be regenerated.
 */
final class Token {

	public const OPTION = 'povikvane_webhook_token';

	/**
	 * The current token, created if there is none.
	 */
	public function get(): string {
		$token = get_option( self::OPTION );

		if ( ! is_string( $token ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
			return $this->regenerate();
		}

		return $token;
	}

	/**
	 * Replace the token. The old webhook URL stops working at once.
	 */
	public function regenerate(): string {
		$token = bin2hex( random_bytes( 32 ) );
		update_option( self::OPTION, $token, false );

		return $token;
	}

	/**
	 * Constant-time comparison.
	 *
	 * @param string $candidate Token from a request.
	 */
	public function matches( string $candidate ): bool {
		return hash_equals( $this->get(), $candidate );
	}

	/**
	 * The full webhook URL to paste into Povikvane.
	 */
	public function url(): string {
		return rest_url( 'povikvane/v1/dlr/' . $this->get() );
	}
}
