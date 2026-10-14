<?php
/**
 * Low-balance email via wp_mail().
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Balance;

defined( 'ABSPATH' ) || exit;

/**
 * Emails the site administrator. The message contains no customer data.
 */
final class WpMailer implements LowBalanceMailer {

	/**
	 * {@inheritDoc}
	 *
	 * @param float $available Credits left.
	 * @param int   $threshold Warning threshold.
	 */
	public function send( float $available, int $threshold ): void {
		$shop = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		wp_mail(
			(string) get_option( 'admin_email' ),
			sprintf(
				/* translators: %s: shop name. */
				__( '[%s] Low SMS credits', 'povikvane-for-woocommerce' ),
				$shop
			),
			sprintf(
				/* translators: 1: credits left, 2: warning threshold, 3: settings page URL. */
				__( "Your Povikvane account has %1\$s credits left (warning level: %2\$d).\n\nSMS messages cannot be sent when the credits run out. Top up your credits in your Povikvane account.\n\nThis warning is sent at most once a day. You can change the level here:\n%3\$s", 'povikvane-for-woocommerce' ),
				number_format_i18n( $available, 1 ),
				$threshold,
				admin_url( 'admin.php?page=povikvane-sms' )
			)
		);
	}
}
