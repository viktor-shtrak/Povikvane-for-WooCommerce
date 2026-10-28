<?php
/**
 * "Check price" under an order message.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Admin;

use Povikvane\WooCommerce\Account\AccountInfo;
use Povikvane\WooCommerce\Api\Capabilities;
use Povikvane\WooCommerce\Messages\PriceEstimator;
use Povikvane\WooCommerce\Support\Countries;
use Povikvane\WooCommerce\Support\Settings;
use Povikvane\WooCommerce\Viber\TemplateCatalog;
use Povikvane\WooCommerce\Api\Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * Prices the text as it is on screen, edited or not, with sample order values and a
 * sample number in the shop's country. It never sends anything.
 */
final class PriceEstimateAjax {

	public const ACTION = 'povikvane_price_estimate';
	public const NONCE  = 'povikvane_price_estimate';

	/**
	 * Constructor.
	 *
	 * @param Gateway         $gateway  API gateway.
	 * @param Settings        $settings Settings.
	 * @param TemplateCatalog $catalog  Templates of the account.
	 */
	public function __construct(
		private readonly Gateway $gateway,
		private readonly Settings $settings,
		private readonly TemplateCatalog $catalog
	) {}

	/**
	 * Register the handler.
	 */
	public function register(): void {
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Handle the request.
	 */
	public function handle(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array(), 403 );
		}

		$text = isset( $_POST['text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['text'] ) ) : '';
		$caps = get_option( Capabilities::OPTION, array() );
		$fall = $this->settings->viber_sms_fallback() && false !== AccountInfo::sms_fallback_allowed( $caps );

		$estimator = new PriceEstimator(
			$this->gateway,
			$this->catalog,
			$this->settings->viber_enabled(),
			Capabilities::VIBER_READY === Capabilities::viber_status( $caps, $fall ),
			$fall
		);

		$values = array(
			'order_number' => '1234',
			'order_total'  => '45.00',
			'shop_name'    => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'tracking_url' => 'https://track.example.com/TRK1234567890',
			'shop_url'     => untrailingslashit( home_url() ),
			'optout_link'  => home_url( '/?po=0123456789ab-0123456789ab' ),
		);

		$result = $estimator->estimate( $text, '+' . ltrim( Countries::calling_code( $this->settings->default_country() ), '+' ) . '888123456', $values );

		if ( null === $result ) {
			wp_send_json_success( array( 'message' => __( 'Povikvane could not price this message right now. Try again in a moment.', 'povikvane-for-woocommerce' ) ) );
		}

		$credits = rtrim( rtrim( number_format( $result['estimate']->credits, 2, '.', '' ), '0' ), '.' );

		$viber = 'viber' === $result['channel'];
		$one   = '1' === $credits;

		// One credit is "credit"; zero, a fraction or several are "credits".
		if ( $viber ) {
			/* translators: %s: number of credits. */
			$format = $one ? __( 'About %s credit per message, sent over Viber.', 'povikvane-for-woocommerce' ) : __( 'About %s credits per message, sent over Viber.', 'povikvane-for-woocommerce' );
		} else {
			/* translators: %s: number of credits. */
			$format = $one ? __( 'About %s credit per message, sent as an SMS.', 'povikvane-for-woocommerce' ) : __( 'About %s credits per message, sent as an SMS.', 'povikvane-for-woocommerce' );
		}

		wp_send_json_success( array( 'message' => sprintf( $format, $credits ) ) );
	}
}
