<?php
/**
 * A message to send.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Request to send one message.
 */
final class SendRequest {

	/**
	 * Constructor.
	 *
	 * @param string      $to              Recipient in E.164.
	 * @param string      $text            Message body.
	 * @param string      $channel         "sms" or "viber".
	 * @param string|null $reference       Caller reference; omitted from the request when null.
	 * @param string|null $idempotency_key Idempotency-Key header; omitted when null.
	 * @param string|null $fallback        "sms" to let the API fall back to SMS; omitted when null.
	 */
	public function __construct(
		public readonly string $to,
		public readonly string $text,
		public readonly string $channel = 'sms',
		public readonly ?string $reference = null,
		public readonly ?string $idempotency_key = null,
		public readonly ?string $fallback = null
	) {}
}
