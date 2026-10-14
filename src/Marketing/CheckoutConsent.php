<?php
/**
 * The consent checkbox at checkout.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Marketing;

use Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields;
use Automattic\WooCommerce\Blocks\Package;
use Throwable;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * An unticked checkbox, shown in both the Blocks checkout (through WooCommerce's
 * additional checkout fields) and the classic shortcode checkout. Ticking it only
 * records consent; it never changes anything else about the order.
 */
final class CheckoutConsent {

	public const BLOCK_FIELD_ID = 'povikvane/marketing-consent';
	public const CLASSIC_FIELD  = 'povikvane_marketing_consent';

	/**
	 * Constructor.
	 *
	 * @param ConsentRecorder   $recorder Records agreements.
	 * @param MarketingSettings $settings Promotional settings.
	 */
	public function __construct(
		private readonly ConsentRecorder $recorder,
		private readonly MarketingSettings $settings
	) {}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'woocommerce_init', array( $this, 'register_block_field' ) );
		add_filter( 'woocommerce_checkout_fields', array( $this, 'add_classic_field' ) );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'on_blocks_order' ) );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'on_classic_order' ), 10, 3 );
	}

	/**
	 * Add the checkbox to the Blocks checkout.
	 */
	public function register_block_field(): void {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}

		try {
			woocommerce_register_additional_checkout_field(
				array(
					'id'       => self::BLOCK_FIELD_ID,
					'label'    => $this->settings->consent_text(),
					'location' => 'order',
					'type'     => 'checkbox',
					'required' => false,
				)
			);
		} catch ( Throwable $error ) {
			// A field that cannot be registered simply does not appear; checkout keeps working.
			unset( $error );
		}
	}

	/**
	 * Add the checkbox to the classic checkout.
	 *
	 * @param mixed $fields Checkout fields.
	 * @return mixed
	 */
	public function add_classic_field( mixed $fields ): mixed {
		if ( ! is_array( $fields ) ) {
			return $fields;
		}

		$fields['order'][ self::CLASSIC_FIELD ] = array(
			'type'     => 'checkbox',
			'label'    => $this->settings->consent_text(),
			'required' => false,
			'default'  => 0,
			'class'    => array( 'form-row-wide' ),
			'priority' => 200,
		);

		return $fields;
	}

	/**
	 * Blocks checkout: the order exists and its additional fields are stored.
	 *
	 * @param mixed $order Order.
	 */
	public function on_blocks_order( mixed $order ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$this->recorder->record_for_order( $order, $this->is_ticked( $this->blocks_value( $order ) ) );
	}

	/**
	 * Classic checkout.
	 *
	 * @param mixed $order_id Order ID.
	 * @param mixed $posted   Posted checkout data.
	 * @param mixed $order    Order.
	 */
	public function on_classic_order( mixed $order_id, mixed $posted, mixed $order = null ): void {
		$order = $order instanceof WC_Order ? $order : wc_get_order( (int) $order_id );

		if ( ! $order instanceof WC_Order || ! is_array( $posted ) ) {
			return;
		}

		$this->recorder->record_for_order( $order, $this->is_ticked( $posted[ self::CLASSIC_FIELD ] ?? null ) );
	}

	/**
	 * The stored value of the Blocks field for an order.
	 *
	 * @param WC_Order $order Order.
	 */
	private function blocks_value( WC_Order $order ): mixed {
		if ( class_exists( Package::class ) && class_exists( CheckoutFields::class ) ) {
			try {
				$fields = Package::container()->get( CheckoutFields::class );

				return $fields->get_field_from_object( self::BLOCK_FIELD_ID, $order, 'other' );
			} catch ( Throwable $error ) {
				unset( $error );
			}
		}

		return $order->get_meta( '_wc_other/' . self::BLOCK_FIELD_ID );
	}

	/**
	 * Whether a stored or posted checkbox value means "ticked".
	 *
	 * @param mixed $value Value.
	 */
	private function is_ticked( mixed $value ): bool {
		return true === $value || in_array( $value, array( 1, '1', 'yes', 'on', 'true' ), true );
	}
}
