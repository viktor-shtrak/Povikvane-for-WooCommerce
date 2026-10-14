<?php
/**
 * Maps API responses to plugin outcomes.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Implements the response matrix from the development plan.
 */
final class ErrorClassifier {

	/**
	 * Classify a response.
	 *
	 * @param Response $response Response from the transport.
	 */
	public static function classify( Response $response ): Outcome {
		if ( null !== $response->transport_error || 0 === $response->status ) {
			return Outcome::Retryable;
		}

		$status = $response->status;

		if ( $status >= 200 && $status < 300 ) {
			return Outcome::Success;
		}

		return match ( $status ) {
			401     => Outcome::AuthFailure,
			402     => Outcome::InsufficientCredits,
			404     => Outcome::NotFound,
			409     => Outcome::InFlight,
			422     => self::is_idempotency_mismatch( $response ) ? Outcome::IdempotencyMismatch : Outcome::PermanentFailure,
			429     => Outcome::RateLimited,
			504     => Outcome::Uncertain,
			default => $status >= 500 ? Outcome::Retryable : Outcome::PermanentFailure,
		};
	}

	/**
	 * Whether a 422 is the idempotency fingerprint mismatch.
	 *
	 * When the service sends an error code, that decides. Until it does, the
	 * message text is the only signal.
	 *
	 * @param Response $response 422 response.
	 */
	private static function is_idempotency_mismatch( Response $response ): bool {
		$code = $response->error_code();

		if ( null !== $code ) {
			return 'idempotency_key_reuse' === $code;
		}

		return str_contains( (string) $response->error_detail(), 'Idempotency-Key' );
	}
}
