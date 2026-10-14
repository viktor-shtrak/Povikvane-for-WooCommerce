<?php
/**
 * Parsed API result.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Outcome plus the parts of the response callers need.
 */
final class ApiResult {

	/**
	 * Constructor.
	 *
	 * @param Outcome      $outcome     Classified outcome.
	 * @param int          $http_status HTTP status, or 0 on transport failure.
	 * @param array<mixed> $data        The response `data` member (one resource, or a list for lookups).
	 * @param string|null  $detail      Error detail or transport error text.
	 * @param int|null     $retry_after Seconds from the Retry-After header.
	 * @param string|null  $request_id  X-Request-Id, for support.
	 * @param string|null  $message_id  Message ID from `data.id`, or from the 504 error meta.
	 */
	public function __construct(
		public readonly Outcome $outcome,
		public readonly int $http_status,
		public readonly array $data = array(),
		public readonly ?string $detail = null,
		public readonly ?int $retry_after = null,
		public readonly ?string $request_id = null,
		public readonly ?string $message_id = null
	) {}

	/**
	 * Attributes of a single-resource response.
	 *
	 * @return array<string,mixed>
	 */
	public function attributes(): array {
		$attributes = $this->data['attributes'] ?? array();

		return is_array( $attributes ) ? $attributes : array();
	}
}
