<?php
/**
 * Builds order facts from a WooCommerce order.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Orders;

use Povikvane\WooCommerce\Support\Countries;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * The only place that reads a WC_Order for message purposes.
 */
final class OrderContext {

	/**
	 * Constructor.
	 *
	 * @param string $default_country ISO country used when the order has none.
	 */
	public function __construct( private readonly string $default_country ) {}

	/**
	 * Facts for an order reaching a status.
	 *
	 * @param WC_Order $order  Order.
	 * @param string   $status New status slug without "wc-".
	 */
	public function facts( WC_Order $order, string $status ): OrderFacts {
		$phone   = $order->get_billing_phone();
		$country = $order->get_billing_country();

		if ( '' === trim( $phone ) ) {
			$phone   = $order->get_shipping_phone();
			$country = $order->get_shipping_country();
		}

		$country = '' !== $country ? $country : $this->default_country;
		$created = $order->get_date_created();

		return new OrderFacts(
			$order->get_id(),
			$status,
			null === $created ? time() : $created->getTimestamp(),
			$order->get_created_via(),
			$phone,
			Countries::calling_code( $country ),
			$this->values( $order )
		);
	}

	/**
	 * Placeholder values for an order.
	 *
	 * @param WC_Order $order Order.
	 * @return array<string,string>
	 */
	public function values( WC_Order $order ): array {
		return array(
			'order_number'     => (string) $order->get_order_number(),
			// The currency code (not its symbol) keeps messages in the cheaper GSM-7 encoding.
			'order_total'      => number_format( (float) $order->get_total(), wc_get_price_decimals(), '.', '' ) . ' ' . $order->get_currency(),
			'first_name'       => $order->get_billing_first_name(),
			'last_name'        => $order->get_billing_last_name(),
			'shop_name'        => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'order_status_url' => $order->get_checkout_order_received_url(),
			/**
			 * Tracking number for the {tracking_number} placeholder.
			 *
			 * @param string   $number Tracking number; empty by default.
			 * @param WC_Order $order  Order.
			 */
			'tracking_number'  => (string) apply_filters( 'povikvane_tracking_number', '', $order ),
			/**
			 * Tracking URL for the {tracking_url} placeholder.
			 *
			 * @param string   $url   Tracking URL; empty by default.
			 * @param WC_Order $order Order.
			 */
			'tracking_url'     => (string) apply_filters( 'povikvane_tracking_url', '', $order ),
		);
	}
}
