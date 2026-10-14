<?php
/**
 * Drops messages for orders that moved on.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Orders;

use Povikvane\WooCommerce\Log\Message;
use Povikvane\WooCommerce\Messages\SendGate;
use Povikvane\WooCommerce\Messages\SkipReason;

defined( 'ABSPATH' ) || exit;

/**
 * A "completed" message is only worth sending while the order is still completed.
 * This covers delayed messages and retries that waited a while.
 */
final class OrderStatusGate implements SendGate {

	/**
	 * {@inheritDoc}
	 *
	 * @param Message $message Message about to be sent.
	 */
	public function blocks( Message $message ): ?string {
		if ( $message->order_id <= 0 || in_array( $message->event, array( 'manual', 'test' ), true ) ) {
			return null;
		}

		$order = wc_get_order( $message->order_id );

		if ( ! $order ) {
			return SkipReason::ORDER_MISSING;
		}

		return $order->get_status() === $message->event ? null : SkipReason::STATUS_CHANGED;
	}
}
