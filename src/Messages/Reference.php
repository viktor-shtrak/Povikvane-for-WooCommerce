<?php
/**
 * Builds the reference that doubles as the API idempotency key.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Messages;

defined( 'ABSPATH' ) || exit;

/**
 * Reference format: woo:{site}:{order}:{event}:{attempt}.
 *
 * The same string is sent as `message.reference` and as `Idempotency-Key`, so it
 * only uses characters accepted by both (the idempotency key forbids "/").
 */
final class Reference {

	private const MAX_LENGTH = 128;

	/**
	 * Reference for an order event.
	 *
	 * @param string $site_token Short stable site identifier.
	 * @param int    $order_id   Order ID.
	 * @param string $event      Event slug (for example "processing").
	 * @param int    $attempt    1 for the first send, incremented only for deliberate resends.
	 */
	public static function for_event( string $site_token, int $order_id, string $event, int $attempt = 1 ): string {
		return sprintf( 'woo:%s:%d:%s:%d', self::clean( $site_token ), $order_id, self::clean( $event ), max( 1, $attempt ) );
	}

	/**
	 * Reference for a test message.
	 *
	 * @param string $site_token Short stable site identifier.
	 * @param int    $timestamp  Unix timestamp.
	 */
	public static function for_test( string $site_token, int $timestamp ): string {
		return sprintf( 'woo:%s:test:%d:1', self::clean( $site_token ), $timestamp );
	}

	/**
	 * Whether a value is acceptable as both a reference and an idempotency key.
	 *
	 * @param string $reference Candidate value.
	 */
	public static function is_valid( string $reference ): bool {
		return strlen( $reference ) >= 1
			&& strlen( $reference ) <= self::MAX_LENGTH
			&& 1 === preg_match( '/^[A-Za-z0-9_:.\-]+$/', $reference );
	}

	/**
	 * Reduce a segment to safe characters.
	 *
	 * @param string $value Raw value.
	 */
	private static function clean( string $value ): string {
		$value = strtolower( trim( $value ) );
		$value = preg_replace( '/[^a-z0-9_\-]+/', '-', $value ) ?? '';

		return trim( $value, '-' );
	}
}
