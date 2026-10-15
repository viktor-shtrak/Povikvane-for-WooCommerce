<?php
/**
 * Checks that this site answers at its own webhook address.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Webhook;

defined( 'ABSPATH' ) || exit;

/**
 * The site calls its own webhook address with a marked test event. This proves the
 * address works from outside the WordPress admin (permalinks, REST API, firewalls),
 * but not that Povikvane can reach it; a real delivery report does that. A test event
 * is answered and then forgotten: it never counts as a report from Povikvane.
 */
final class SelfTest {

	public const EVENT = 'povikvane.self_test';

	public const OK          = 'ok';
	public const NOT_FOUND   = 'not_found';
	public const BLOCKED     = 'blocked';
	public const UNREACHABLE = 'unreachable';
	public const UNEXPECTED  = 'unexpected';

	/**
	 * The body of the test request.
	 */
	public static function body(): string {
		return (string) wp_json_encode( array( 'event' => self::EVENT ) );
	}

	/**
	 * Turn the answer into a result.
	 *
	 * @param int          $status HTTP status, or 0 when the request itself failed.
	 * @param array<mixed> $body   Decoded JSON answer.
	 * @return string One of the result constants.
	 */
	public static function interpret( int $status, array $body ): string {
		if ( 0 === $status ) {
			return self::UNREACHABLE;
		}

		if ( 200 === $status && 'test' === ( $body['result'] ?? null ) ) {
			return self::OK;
		}

		return match ( true ) {
			404 === $status => self::NOT_FOUND,
			in_array( $status, array( 401, 403, 429 ), true ) => self::BLOCKED,
			default => self::UNEXPECTED,
		};
	}
}
