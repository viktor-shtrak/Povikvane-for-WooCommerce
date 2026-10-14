<?php
/**
 * Tests for response classification.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Povikvane\WooCommerce\Api\ErrorClassifier;
use Povikvane\WooCommerce\Api\Outcome;
use Povikvane\WooCommerce\Api\Response;

/**
 * The response matrix.
 */
final class ErrorClassifierTest extends TestCase {

	/**
	 * Status code to outcome.
	 *
	 * @return array<string, array{int, Outcome}>
	 */
	public static function statuses(): array {
		return array(
			'200 ok'               => array( 200, Outcome::Success ),
			'400 bad request'      => array( 400, Outcome::PermanentFailure ),
			'401 unauthorized'     => array( 401, Outcome::AuthFailure ),
			'402 payment required' => array( 402, Outcome::InsufficientCredits ),
			'404 not found'        => array( 404, Outcome::NotFound ),
			'409 conflict'         => array( 409, Outcome::InFlight ),
			'413 too large'        => array( 413, Outcome::PermanentFailure ),
			'422 validation'       => array( 422, Outcome::PermanentFailure ),
			'429 rate limited'     => array( 429, Outcome::RateLimited ),
			'500 server error'     => array( 500, Outcome::Retryable ),
			'502 bad gateway'      => array( 502, Outcome::Retryable ),
			'503 unavailable'      => array( 503, Outcome::Retryable ),
			'504 gateway timeout'  => array( 504, Outcome::Uncertain ),
		);
	}

	/**
	 * Classify by status.
	 *
	 * @param int     $status   HTTP status.
	 * @param Outcome $expected Expected outcome.
	 */
	#[DataProvider( 'statuses' )]
	public function test_classifies_status( int $status, Outcome $expected ): void {
		$this->assertSame( $expected, ErrorClassifier::classify( new Response( $status ) ) );
	}

	/**
	 * A 422 about the idempotency key is a plugin bug, not a validation problem.
	 */
	public function test_idempotency_mismatch_is_recognised(): void {
		$response = new Response(
			422,
			array(),
			array(
				'errors' => array(
					array(
						'status' => '422',
						'detail' => 'Idempotency-Key was already used with a different request body. Use a fresh key for a new message.',
					),
				),
			)
		);

		$this->assertSame( Outcome::IdempotencyMismatch, ErrorClassifier::classify( $response ) );
	}

	/**
	 * Transport failures are retryable with the same key.
	 */
	public function test_transport_failure_is_retryable(): void {
		$this->assertSame( Outcome::Retryable, ErrorClassifier::classify( new Response( 0, array(), null, 'cURL error 28: timed out' ) ) );
	}
}
