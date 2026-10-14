<?php
/**
 * Raw HTTP response from the API transport.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Status, lower-cased headers and decoded JSON body. A transport failure has
 * status 0 and a message.
 */
final class Response {

	/**
	 * Constructor.
	 *
	 * @param int                  $status          HTTP status, or 0 on transport failure.
	 * @param array<string,string> $headers         Header values keyed by lower-case name.
	 * @param array<mixed>|null    $body            Decoded JSON body, if any.
	 * @param string|null          $transport_error Transport error message, if the request failed.
	 */
	public function __construct(
		public readonly int $status,
		public readonly array $headers = array(),
		public readonly ?array $body = null,
		public readonly ?string $transport_error = null
	) {}

	/**
	 * Header value by case-insensitive name.
	 *
	 * @param string $name Header name.
	 */
	public function header( string $name ): ?string {
		return $this->headers[ strtolower( $name ) ] ?? null;
	}

	/**
	 * Human-readable error detail from the JSON:API error body.
	 */
	public function error_detail(): ?string {
		$detail = $this->body['errors'][0]['detail'] ?? null;

		return is_string( $detail ) ? $detail : null;
	}

	/**
	 * Machine-readable error code, once the service provides one (for example
	 * "idempotency_key_reuse"). Null for responses without it.
	 */
	public function error_code(): ?string {
		$code = $this->body['errors'][0]['code'] ?? null;

		return is_string( $code ) && '' !== $code ? $code : null;
	}

	/**
	 * Message ID carried in the 504 error meta, if present.
	 */
	public function error_message_id(): ?string {
		$id = $this->body['errors'][0]['meta']['message-id'] ?? null;

		return is_string( $id ) ? $id : null;
	}
}
