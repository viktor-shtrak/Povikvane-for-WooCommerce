<?php
/**
 * Records consent given on an order.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Marketing;

use Povikvane\WooCommerce\Phone\Normalizer;
use Povikvane\WooCommerce\Support\Countries;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a ticked checkout checkbox into a consent record for the customer's number.
 */
final class ConsentRecorder {

	/**
	 * Constructor.
	 *
	 * @param Consent           $consent    Consent rules.
	 * @param Normalizer        $normalizer Phone normaliser.
	 * @param MarketingSettings $settings   Promotional settings.
	 * @param string            $default_country ISO country used when the order has none.
	 */
	public function __construct(
		private readonly Consent $consent,
		private readonly Normalizer $normalizer,
		private readonly MarketingSettings $settings,
		private readonly string $default_country
	) {}

	/**
	 * Record an agreement given at checkout. An unticked box records nothing: it
	 * neither grants nor withdraws.
	 *
	 * @param WC_Order $order   Order.
	 * @param bool     $checked Whether the box was ticked.
	 */
	public function record_for_order( WC_Order $order, bool $checked ): void {
		if ( ! $checked ) {
			return;
		}

		$phone   = $order->get_billing_phone();
		$country = $order->get_billing_country();

		if ( '' === trim( $phone ) ) {
			$phone   = $order->get_shipping_phone();
			$country = $order->get_shipping_country();
		}

		$number = $this->normalizer->normalize( $phone, Countries::calling_code( '' !== $country ? $country : $this->default_country ) );

		if ( ! $number->is_valid() ) {
			return;
		}

		$email = $order->get_billing_email();

		$this->consent->grant(
			(string) $number->e164,
			'' !== $email ? $email : null,
			ConsentEvent::SOURCE_CHECKOUT,
			$order->get_id(),
			$order->get_customer_id(),
			$this->settings->wording_hash()
		);
	}
}
