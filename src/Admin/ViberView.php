<?php
/**
 * Wording for Viber availability and templates.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Admin;

use Povikvane\WooCommerce\Api\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * One place for the reasons shown on the Messages and Connection tabs.
 */
final class ViberView {

	/**
	 * Why Viber is not offered.
	 *
	 * @param string $status A Capabilities::VIBER_* constant.
	 */
	public static function reason( string $status ): string {
		return match ( $status ) {
			Capabilities::VIBER_READY            => __( 'Viber is available on your account.', 'povikvane-for-woocommerce' ),
			Capabilities::VIBER_TEMPLATES_OFF    => __( 'Viber is not available yet: Viber templates are not switched on for your Povikvane account. Order messages go out as SMS.', 'povikvane-for-woocommerce' ),
			Capabilities::VIBER_FALLBACK_REFUSED => __( 'Povikvane refused the SMS fallback for your account (it is not part of every plan), so order messages go out as SMS. Turn off "Send an SMS if Viber cannot deliver" to use Viber without it.', 'povikvane-for-woocommerce' ),
			default                              => __( 'Viber has not been checked yet. Open the Settings tab and press "Check connection".', 'povikvane-for-woocommerce' ),
		};
	}
}
