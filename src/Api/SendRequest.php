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
	 * @param string               $to              Recipient in E.164.
	 * @param string               $text            Message body.
	 * @param string               $channel         "sms" or "viber".
	 * @param string|null          $reference       Caller reference; omitted from the request when null.
	 * @param string|null          $idempotency_key Idempotency-Key header; omitted when null.
	 * @param string|null          $template_id     Viber template ID; omitted when null.
	 * @param array<string,string> $params Template variable values.
	 * @param string|null          $fallback_sms    SMS text the API sends if Viber fails; omitted when null.
	 */
	public function __construct(
		public readonly string $to,
		public readonly string $text,
		public readonly string $channel = 'sms',
		public readonly ?string $reference = null,
		public readonly ?string $idempotency_key = null,
		public readonly ?string $template_id = null,
		public readonly array $params = array(),
		public readonly ?string $fallback_sms = null
	) {}
}
