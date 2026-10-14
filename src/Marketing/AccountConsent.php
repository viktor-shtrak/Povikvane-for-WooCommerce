<?php
/**
 * Consent preference in My Account.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Marketing;

use Povikvane\WooCommerce\Phone\Normalizer;
use Povikvane\WooCommerce\Support\Countries;

defined( 'ABSPATH' ) || exit;

/**
 * Lets a logged-in customer agree to, or withdraw from, promotional messages on
 * the "Account details" page. The state belongs to their billing phone number.
 */
final class AccountConsent {

	private const FIELD  = 'povikvane_marketing_consent';
	private const MARKER = 'povikvane_marketing_consent_present';

	/**
	 * Constructor.
	 *
	 * @param Consent           $consent    Consent rules.
	 * @param Normalizer        $normalizer Phone normaliser.
	 * @param MarketingSettings $settings   Promotional settings.
	 * @param string            $default_country ISO country used when the customer has none.
	 */
	public function __construct(
		private readonly Consent $consent,
		private readonly Normalizer $normalizer,
		private readonly MarketingSettings $settings,
		private readonly string $default_country
	) {}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'woocommerce_edit_account_form', array( $this, 'render' ) );
		add_action( 'woocommerce_save_account_details', array( $this, 'save' ) );
	}

	/**
	 * Show the checkbox.
	 */
	public function render(): void {
		$number = $this->customer_number( get_current_user_id() );

		printf(
			'<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide"><label><input type="checkbox" name="%1$s" value="1"%2$s> %3$s</label><input type="hidden" name="%4$s" value="1"></p>',
			esc_attr( self::FIELD ),
			null !== $number && $this->consent->has_consent( $number ) ? ' checked' : '',
			esc_html( $this->settings->consent_text() ),
			esc_attr( self::MARKER )
		);
	}

	/**
	 * Save the choice. WooCommerce has already verified its own form nonce.
	 *
	 * @param mixed $user_id Customer ID.
	 */
	public function save( mixed $user_id ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the account details nonce before this action runs.
		if ( ! isset( $_POST[ self::MARKER ] ) ) {
			return;
		}

		$ticked = ! empty( $_POST[ self::FIELD ] );
		// phpcs:enable

		$number = $this->customer_number( (int) $user_id );

		if ( null === $number ) {
			if ( $ticked && function_exists( 'wc_add_notice' ) ) {
				wc_add_notice( __( 'Add a mobile number to your billing address first, so we know where to send messages.', 'povikvane-for-woocommerce' ), 'error' );
			}

			return;
		}

		if ( $ticked ) {
			$this->consent->grant( $number, $this->customer_email( (int) $user_id ), ConsentEvent::SOURCE_ACCOUNT, 0, (int) $user_id, $this->settings->wording_hash() );
		} else {
			$this->consent->revoke( $number, ConsentEvent::SOURCE_ACCOUNT, (int) $user_id );
		}
	}

	/**
	 * The customer's billing number in E.164, if it is valid.
	 *
	 * @param int $user_id Customer ID.
	 */
	private function customer_number( int $user_id ): ?string {
		if ( $user_id <= 0 ) {
			return null;
		}

		$phone   = (string) get_user_meta( $user_id, 'billing_phone', true );
		$country = (string) get_user_meta( $user_id, 'billing_country', true );
		$result  = $this->normalizer->normalize( $phone, Countries::calling_code( '' !== $country ? $country : $this->default_country ) );

		return $result->is_valid() ? $result->e164 : null;
	}

	/**
	 * The customer's email address.
	 *
	 * @param int $user_id Customer ID.
	 */
	private function customer_email( int $user_id ): ?string {
		$user = get_userdata( $user_id );

		return $user && '' !== $user->user_email ? $user->user_email : null;
	}
}
