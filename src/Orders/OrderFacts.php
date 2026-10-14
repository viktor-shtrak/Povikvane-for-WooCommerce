<?php
/**
 * Facts about an order event, free of WooCommerce objects.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Orders;

defined( 'ABSPATH' ) || exit;

/**
 * Everything the event handler needs, so it can be tested without WooCommerce.
 */
final class OrderFacts {

	/**
	 * Constructor.
	 *
	 * @param int                  $order_id     Order ID.
	 * @param string               $status       New status slug without "wc-".
	 * @param int                  $created_at   Unix time the order was created.
	 * @param string               $created_via  How the order was created ("checkout", "admin", ...).
	 * @param string               $phone        Raw billing phone.
	 * @param string               $calling_code Calling code of the billing country, for example "+359".
	 * @param array<string,string> $values       Placeholder values.
	 */
	public function __construct(
		public readonly int $order_id,
		public readonly string $status,
		public readonly int $created_at,
		public readonly string $created_via,
		public readonly string $phone,
		public readonly string $calling_code,
		public readonly array $values
	) {}
}
