<?php
/**
 * WordPress HTTP API transport.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Sends requests with wp_remote_request().
 */
final class WpTransport implements Transport {

	private const TIMEOUT_SECONDS = 15;

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $method  HTTP method.
	 * @param string               $url     Absolute URL.
	 * @param array<string,string> $headers Request headers.
	 * @param string|null          $body    Raw request body.
	 */
	public function request( string $method, string $url, array $headers, ?string $body ): Response {
		$args = array(
			'method'      => $method,
			'timeout'     => self::TIMEOUT_SECONDS,
			'redirection' => 0,
			'headers'     => $headers,
			'user-agent'  => 'PovikvaneWooCommerce/' . \Povikvane\WooCommerce\VERSION . '; WordPress/' . get_bloginfo( 'version' ),
		);

		if ( null !== $body ) {
			$args['body'] = $body;
		}

		$raw = wp_remote_request( $url, $args );

		if ( is_wp_error( $raw ) ) {
			return new Response( 0, array(), null, $raw->get_error_message() );
		}

		$decoded = json_decode( wp_remote_retrieve_body( $raw ), true );

		return new Response(
			(int) wp_remote_retrieve_response_code( $raw ),
			$this->headers( $raw ),
			is_array( $decoded ) ? $decoded : null
		);
	}

	/**
	 * Flatten response headers to lower-case names.
	 *
	 * @param array<string,mixed> $raw WordPress HTTP response.
	 * @return array<string,string>
	 */
	private function headers( array $raw ): array {
		$source = wp_remote_retrieve_headers( $raw );
		$list   = is_object( $source ) && method_exists( $source, 'getAll' ) ? $source->getAll() : (array) $source;
		$out    = array();

		foreach ( $list as $name => $value ) {
			$out[ strtolower( (string) $name ) ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
		}

		return $out;
	}
}
