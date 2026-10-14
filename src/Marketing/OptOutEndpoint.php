<?php
/**
 * The unsubscribe link in promotional messages.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Marketing;

defined( 'ABSPATH' ) || exit;

/**
 * Visiting /?po=<token> withdraws consent for that number at once, without logging
 * in. The signature in the token is what prevents anyone from opting out another
 * number. The link keeps working even if promotional messages are switched off.
 */
final class OptOutEndpoint {

	/**
	 * Constructor.
	 *
	 * @param Consent     $consent Consent rules.
	 * @param OptOutToken $token   Token checker.
	 */
	public function __construct(
		private readonly Consent $consent,
		private readonly OptOutToken $token
	) {}

	/**
	 * Register the hook.
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'maybe_handle' ), 1 );
	}

	/**
	 * Handle an unsubscribe visit.
	 */
	public function maybe_handle(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- An unsubscribe link cannot carry a nonce; the signed token authorises it.
		if ( ! isset( $_GET['po'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- See above.
		$hash = $this->token->verify( sanitize_text_field( wp_unslash( $_GET['po'] ) ) );

		nocache_headers();

		if ( null === $hash ) {
			wp_die( esc_html__( 'This unsubscribe link is not valid.', 'povikvane-for-woocommerce' ), esc_html__( 'Unsubscribe', 'povikvane-for-woocommerce' ), array( 'response' => 400 ) );
		}

		$this->consent->revoke_by_hash( $hash, ConsentEvent::SOURCE_OPTOUT );

		wp_die(
			esc_html(
				sprintf(
					/* translators: %s: shop name. */
					__( 'You have been unsubscribed from promotional SMS messages from %s. You will still receive messages about your orders.', 'povikvane-for-woocommerce' ),
					wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
				)
			),
			esc_html__( 'Unsubscribed', 'povikvane-for-woocommerce' ),
			array( 'response' => 200 )
		);
	}
}
