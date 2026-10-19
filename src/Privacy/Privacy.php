<?php
/**
 * WordPress privacy tools: policy text, export and erase.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Privacy;

use Povikvane\WooCommerce\Log\Repository;
use Povikvane\WooCommerce\Marketing\Consent;
use Povikvane\WooCommerce\Marketing\ConsentRepository;
use Povikvane\WooCommerce\Phone\Normalizer;
use Povikvane\WooCommerce\Support\Countries;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Plugs into Tools > Export/Erase Personal Data. Everything is found through the
 * customer's orders (by billing email): their messages and consent records.
 * Erasing removes message text and numbers but keeps the opt-out decisions in an
 * anonymous form, so a person who unsubscribed is not contacted again.
 */
final class Privacy {

	private const NAME       = 'Povikvane SMS for WooCommerce';
	private const MAX_ORDERS = 200;

	/**
	 * Constructor.
	 *
	 * @param Repository        $messages        Message log.
	 * @param ConsentRepository $ledger          Consent records.
	 * @param Consent           $consent         Consent rules (for hashing numbers).
	 * @param Normalizer        $normalizer      Phone normaliser.
	 * @param string            $default_country ISO country used when an order has none.
	 * @param callable|null     $erase_contact   Removes a customer's contact in Povikvane; receives the number.
	 */
	public function __construct(
		private readonly Repository $messages,
		private readonly ConsentRepository $ledger,
		private readonly Consent $consent,
		private readonly Normalizer $normalizer,
		private readonly string $default_country,
		private readonly mixed $erase_contact = null
	) {}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'add_policy_text' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'add_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'add_eraser' ) );
	}

	/**
	 * Suggest wording for the site's privacy policy.
	 */
	public function add_policy_text(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$text  = '<h2>' . esc_html__( 'SMS messages', 'povikvane-for-woocommerce' ) . '</h2>';
		$text .= '<p class="privacy-policy-tutorial">' . esc_html__( 'Suggested wording. Review and adapt it to how you use the plugin.', 'povikvane-for-woocommerce' ) . '</p>';
		$text .= '<p>' . esc_html__( 'When you place an order, we may send text messages about it (for example when it is completed) to the phone number you gave. We use the Povikvane messaging service to send them on our behalf, so your phone number and the message text are passed to that service.', 'povikvane-for-woocommerce' ) . '</p>';
		$text .= '<p>' . esc_html__( 'If you agreed to it, we also send occasional promotional text messages. You can withdraw your agreement at any time using the link in each message or in your account. We keep a record of your agreement and of any withdrawal.', 'povikvane-for-woocommerce' ) . '</p>';
		$text .= '<p>' . esc_html__( 'We keep the text of messages for a limited time and the record of each message (order, status, phone number) for longer, as set in the plugin settings.', 'povikvane-for-woocommerce' ) . '</p>';

		wp_add_privacy_policy_content( self::NAME, wp_kses_post( $text ) );
	}

	/**
	 * Register the exporter.
	 *
	 * @param mixed $exporters Registered exporters.
	 * @return mixed
	 */
	public function add_exporter( mixed $exporters ): mixed {
		if ( is_array( $exporters ) ) {
			$exporters['povikvane-sms'] = array(
				'exporter_friendly_name' => self::NAME,
				'callback'               => array( $this, 'export' ),
			);
		}

		return $exporters;
	}

	/**
	 * Register the eraser.
	 *
	 * @param mixed $erasers Registered erasers.
	 * @return mixed
	 */
	public function add_eraser( mixed $erasers ): mixed {
		if ( is_array( $erasers ) ) {
			$erasers['povikvane-sms'] = array(
				'eraser_friendly_name' => self::NAME,
				'callback'             => array( $this, 'erase' ),
			);
		}

		return $erasers;
	}

	/**
	 * Personal data export for an email address.
	 *
	 * @param string $email_address Email address.
	 * @return array{data:array<int,array<string,mixed>>,done:bool}
	 */
	public function export( string $email_address ): array {
		$orders = $this->orders_for( $email_address );
		$data   = array();

		foreach ( $this->messages->for_orders( array_keys( $orders ) ) as $message ) {
			$data[] = array(
				'group_id'    => 'povikvane_sms_messages',
				'group_label' => __( 'SMS messages', 'povikvane-for-woocommerce' ),
				'item_id'     => 'povikvane-sms-' . $message->id,
				'data'        => array(
					array(
						'name'  => __( 'Date', 'povikvane-for-woocommerce' ),
						'value' => gmdate( 'Y-m-d H:i:s', $message->created_at ) . ' UTC',
					),
					array(
						'name'  => __( 'Order', 'povikvane-for-woocommerce' ),
						'value' => (string) $message->order_id,
					),
					array(
						'name'  => __( 'Type', 'povikvane-for-woocommerce' ),
						'value' => $message->event . ' (' . $message->kind->value . ')',
					),
					array(
						'name'  => __( 'Status', 'povikvane-for-woocommerce' ),
						'value' => $message->status->value,
					),
					array(
						'name'  => __( 'Phone number', 'povikvane-for-woocommerce' ),
						'value' => $message->recipient,
					),
					array(
						'name'  => __( 'Text', 'povikvane-for-woocommerce' ),
						'value' => $message->body,
					),
				),
			);
		}

		foreach ( $this->ledger->events_for( $this->hashes_for( $orders ), $email_address ) as $event ) {
			$data[] = array(
				'group_id'    => 'povikvane_sms_consent',
				'group_label' => __( 'SMS promotional consent', 'povikvane-for-woocommerce' ),
				'item_id'     => 'povikvane-consent-' . $event->id,
				'data'        => array(
					array(
						'name'  => __( 'Date', 'povikvane-for-woocommerce' ),
						'value' => gmdate( 'Y-m-d H:i:s', $event->created_at ) . ' UTC',
					),
					array(
						'name'  => __( 'Decision', 'povikvane-for-woocommerce' ),
						'value' => $event->granted ? __( 'Agreed', 'povikvane-for-woocommerce' ) : __( 'Withdrew', 'povikvane-for-woocommerce' ),
					),
					array(
						'name'  => __( 'Where', 'povikvane-for-woocommerce' ),
						'value' => $event->source,
					),
					array(
						'name'  => __( 'Phone number', 'povikvane-for-woocommerce' ),
						'value' => (string) $event->phone,
					),
					array(
						'name'  => __( 'Email', 'povikvane-for-woocommerce' ),
						'value' => (string) $event->email,
					),
				),
			);
		}

		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * Personal data erasure for an email address.
	 *
	 * @param string $email_address Email address.
	 * @return array{items_removed:bool,items_retained:bool,messages:string[],done:bool}
	 */
	public function erase( string $email_address ): array {
		$orders = $this->orders_for( $email_address );
		// Before the numbers are wiped from the log, so the contact in Povikvane can go too.
		if ( is_callable( $this->erase_contact ) ) {
			foreach ( $this->phones_for( $orders ) as $phone ) {
				( $this->erase_contact )( $phone );
			}
		}

		$messages = $this->messages->anonymize_for_orders( array_keys( $orders ) );
		$records  = $this->ledger->erase_personal_data( $this->hashes_for( $orders ), $email_address );

		return array(
			'items_removed'  => $messages > 0 || $records > 0,
			'items_retained' => $records > 0,
			'messages'       => $records > 0
				? array( __( 'SMS opt-in and opt-out decisions were kept without the phone number and email, so that a withdrawal stays in force.', 'povikvane-for-woocommerce' ) )
				: array(),
			'done'           => true,
		);
	}

	/**
	 * Orders placed with an email address, keyed by order ID.
	 *
	 * @param string $email_address Email address.
	 * @return array<int,WC_Order>
	 */
	private function orders_for( string $email_address ): array {
		if ( '' === $email_address || ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$orders = wc_get_orders(
			array(
				'billing_email' => $email_address,
				'limit'         => self::MAX_ORDERS,
				'type'          => 'shop_order',
			)
		);
		$keyed  = array();

		foreach ( is_array( $orders ) ? $orders : array() as $order ) {
			if ( $order instanceof WC_Order ) {
				$keyed[ $order->get_id() ] = $order;
			}
		}

		return $keyed;
	}

	/**
	 * Valid phone numbers on some orders, in E.164.
	 *
	 * @param array<int,WC_Order> $orders Orders.
	 * @return string[]
	 */
	private function phones_for( array $orders ): array {
		$phones = array();

		foreach ( $orders as $order ) {
			$country = $order->get_billing_country();
			$number  = $this->normalizer->normalize( $order->get_billing_phone(), Countries::calling_code( '' !== $country ? $country : $this->default_country ) );

			if ( $number->is_valid() ) {
				$phones[ (string) $number->e164 ] = true;
			}
		}

		return array_map( 'strval', array_keys( $phones ) );
	}

	/**
	 * Keyed hashes of the phone numbers on some orders.
	 *
	 * @param array<int,WC_Order> $orders Orders.
	 * @return string[]
	 */
	private function hashes_for( array $orders ): array {
		$hashes = array();

		foreach ( $orders as $order ) {
			$country = $order->get_billing_country();
			$number  = $this->normalizer->normalize( $order->get_billing_phone(), Countries::calling_code( '' !== $country ? $country : $this->default_country ) );

			if ( $number->is_valid() ) {
				$hashes[ $this->consent->hash( (string) $number->e164 ) ] = true;
			}
		}

		return array_keys( $hashes );
	}
}
