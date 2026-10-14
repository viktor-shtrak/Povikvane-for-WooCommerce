<?php
/**
 * WooCommerce order status hook.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Orders;

use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Connects WooCommerce's status change event to the event handler.
 */
final class StatusHooks {

	/**
	 * Constructor.
	 *
	 * @param OrderEventHandler $handler  Decides what to do.
	 * @param TriggerProvider   $triggers Trigger configuration.
	 * @param OrderContext      $context  Builds order facts.
	 */
	public function __construct(
		private readonly OrderEventHandler $handler,
		private readonly TriggerProvider $triggers,
		private readonly OrderContext $context
	) {}

	/**
	 * Register the hook.
	 */
	public function register(): void {
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_changed' ), 10, 4 );
	}

	/**
	 * Handle an order changing status.
	 *
	 * @param mixed $order_id Order ID.
	 * @param mixed $from     Old status slug.
	 * @param mixed $to       New status slug.
	 * @param mixed $order    Order object, when WooCommerce passes it.
	 */
	public function on_status_changed( mixed $order_id, mixed $from, mixed $to, mixed $order = null ): void {
		$trigger = $this->triggers->for_status( (string) $to );

		if ( ! $trigger->enabled ) {
			return;
		}

		$order = $order instanceof WC_Order ? $order : wc_get_order( (int) $order_id );

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$this->handler->handle( $this->context->facts( $order, (string) $to ), $trigger );
	}
}
