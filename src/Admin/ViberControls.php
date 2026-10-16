<?php
/**
 * The two Viber switches: Viber itself and its SMS fallback.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Shared by the Messages tab and the setup wizard, so both behave the same.
 */
final class ViberControls {

	/**
	 * Print the Viber switch.
	 *
	 * @param bool   $enabled      Whether Viber is on.
	 * @param string $enabled_name Field name of the switch.
	 */
	public static function render_switch( bool $enabled, string $enabled_name ): void {
		Ui::field_open( __( 'Use Viber', 'povikvane-for-woocommerce' ) );
		Ui::toggle( $enabled_name, $enabled, __( 'Send order messages over Viber when possible', 'povikvane-for-woocommerce' ), __( 'A Viber message costs 0.8 credits. Messages without an approved template are still sent as SMS.', 'povikvane-for-woocommerce' ), '', 'data-pvk-viber-switch' );
		Ui::field_close();
	}

	/**
	 * Print the SMS fallback switch: greyed out, with a tooltip, when the plan has none.
	 *
	 * @param bool      $fallback         Whether the fallback is on.
	 * @param bool|null $fallback_allowed Whether the plan has it; null when unknown (treated as allowed).
	 * @param string    $fallback_name    Field name of the switch.
	 */
	public static function render_fallback( bool $fallback, ?bool $fallback_allowed, string $fallback_name ): void {
		Ui::field_open( __( 'SMS fallback', 'povikvane-for-woocommerce' ) );

		if ( false === $fallback_allowed ) {
			$tip = __( 'Your Povikvane plan does not include the SMS fallback. It is not part of the Starter and Bronze plans.', 'povikvane-for-woocommerce' );

			echo '<div class="pvk-inline">';
			Ui::toggle( $fallback_name, false, __( 'Send an SMS if Viber cannot deliver', 'povikvane-for-woocommerce' ), '', '', 'disabled data-pvk-fallback-switch' );
			printf(
				'<span class="pvk-info" tabindex="0" role="img" aria-label="%1$s" data-pvk-tip="%1$s">i</span>',
				esc_attr( $tip )
			);
			echo '</div>';
		} else {
			Ui::toggle( $fallback_name, $fallback, __( 'Send an SMS if Viber cannot deliver', 'povikvane-for-woocommerce' ), __( 'Not included in the Starter and Bronze plans: turn it on only if your plan has it. With it on, the SMS text must fit in one SMS part and the message length estimate is shown under each message.', 'povikvane-for-woocommerce' ), '', 'data-pvk-fallback-switch' );
		}

		Ui::field_close();
	}
}
