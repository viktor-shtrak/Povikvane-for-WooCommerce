<?php
/**
 * Classified result of an API call.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Api;

defined( 'ABSPATH' ) || exit;

/**
 * What the plugin should do about a response.
 */
enum Outcome: string {
	/** HTTP 200. */
	case Success = 'success';
	/** 400 or another client error: do not retry. */
	case PermanentFailure = 'permanent_failure';
	/** 401: credentials rejected. */
	case AuthFailure = 'auth_failure';
	/** 402: no credits. */
	case InsufficientCredits = 'insufficient_credits';
	/** 404: resource or endpoint not found. */
	case NotFound = 'not_found';
	/** 409: same idempotency key still in flight. */
	case InFlight = 'in_flight';
	/** 422 caused by reusing an idempotency key with a different body: a plugin bug. */
	case IdempotencyMismatch = 'idempotency_mismatch';
	/** 429: wait for Retry-After. */
	case RateLimited = 'rate_limited';
	/** 502, other 5xx, timeouts, network errors: retry with the same key. */
	case Retryable = 'retryable';
	/** 504: delivery outcome unknown; never resend. */
	case Uncertain = 'uncertain';
}
