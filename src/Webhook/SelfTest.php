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

	/**
	 * Call an address from this server and say what happened.
	 *
	 * @param string $url The site's webhook address.
	 * @return string One of the result constants.
	 */
	public static function run( string $url ): string {
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 8,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => self::body(),
			)
		);

		$decoded = is_wp_error( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true );

		return self::interpret( is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response ), is_array( $decoded ) ? $decoded : array() );
	}

	/**
	 * What a result means, in words for the site owner.
	 *
	 * @param string $result A result constant.
	 */
	public static function message( string $result ): string {
		return match ( $result ) {
			self::OK          => __( 'The address works. To see that Povikvane can reach it, send a test message and watch "Last report received".', 'povikvane-for-woocommerce' ),
			self::NOT_FOUND   => __( 'The address was not found. Check that pretty permalinks are on and the REST API is not disabled, or create a new address.', 'povikvane-for-woocommerce' ),
			self::BLOCKED     => __( 'Your site blocked the address, probably a security plugin or a firewall. Allow requests to /wp-json/povikvane/ and try again.', 'povikvane-for-woocommerce' ),
			self::UNREACHABLE => __( 'This server could not reach its own address. That does not always mean Povikvane cannot; send a test message and watch "Last report received".', 'povikvane-for-woocommerce' ),
			default           => __( 'The address gave an unexpected answer. Try again, or create a new address.', 'povikvane-for-woocommerce' ),
		};
	}
}
