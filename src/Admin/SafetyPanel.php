<?php
/**
 * The Safety and data tab.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Admin;

use Povikvane\WooCommerce\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Protections against accidental messages, the credit warning, and data retention.
 */
final class SafetyPanel {

	/**
	 * Print the panel. Must be called inside the settings form.
	 *
	 * @param array<string,mixed> $values Current settings.
	 */
	public function render( array $values ): void {
		$name = Settings::OPTION;

		Ui::card_open( __( 'Protections', 'povikvane-for-woocommerce' ), __( 'Keep messages from going to the wrong people or in the wrong amounts.', 'povikvane-for-woocommerce' ) );

		Ui::field_open( __( 'Old orders', 'povikvane-for-woocommerce' ), 'povikvane_order_age', __( 'Protects against messages for old orders after imports or bulk changes. 0 means no limit.', 'povikvane-for-woocommerce' ) );
		printf(
			'<div class="pvk-inline"><span>%1$s</span> <input type="number" id="povikvane_order_age" name="%2$s[max_order_age_days]" value="%3$d" min="0" max="365"> <span>%4$s</span></div>',
			esc_html__( 'Do not message orders older than', 'povikvane-for-woocommerce' ),
			esc_attr( $name ),
			(int) $values['max_order_age_days'],
			esc_html__( 'days', 'povikvane-for-woocommerce' )
		);
		Ui::field_close();

		Ui::field_open( __( 'Landline numbers', 'povikvane-for-woocommerce' ) );
		Ui::toggle( $name . '[mobile_only]', (bool) $values['mobile_only'], __( 'Skip numbers that are not mobile (checked for Bulgaria)', 'povikvane-for-woocommerce' ), __( 'A message to a landline is charged but cannot be delivered.', 'povikvane-for-woocommerce' ) );
		Ui::field_close();

		Ui::field_open( __( 'Orders created in the admin', 'povikvane-for-woocommerce' ) );
		Ui::toggle( $name . '[skip_admin_created]', (bool) $values['skip_admin_created'], __( 'Do not send automatic messages for orders created by hand', 'povikvane-for-woocommerce' ) );
		Ui::field_close();

		Ui::field_open( __( 'Bursts of messages', 'povikvane-for-woocommerce' ), 'povikvane_storm', __( 'Stops accidental mass messaging, for example after changing many orders at once. The messages are kept for you to send or discard. 0 turns this off.', 'povikvane-for-woocommerce' ) );
		printf(
			'<div class="pvk-inline"><span>%1$s</span> <input type="number" id="povikvane_storm" name="%2$s[storm_threshold]" value="%3$d" min="0" max="10000"> <span>%4$s</span></div>',
			esc_html__( 'Pause when more than', 'povikvane-for-woocommerce' ),
			esc_attr( $name ),
			(int) $values['storm_threshold'],
			esc_html__( 'messages are created within 10 minutes', 'povikvane-for-woocommerce' )
		);
		Ui::field_close();

		Ui::card_close();

		Ui::card_open( __( 'Credits', 'povikvane-for-woocommerce' ) );

		Ui::field_open( __( 'Low credits warning', 'povikvane-for-woocommerce' ), 'povikvane_low_balance', __( 'Shows a notice in the admin and sends the site administrator one email a day while credits stay below this level. 0 turns the warning off.', 'povikvane-for-woocommerce' ) );
		printf(
			'<div class="pvk-inline"><span>%1$s</span> <input type="number" id="povikvane_low_balance" name="%2$s[low_balance_threshold]" value="%3$d" min="0"> <span>%4$s</span></div>',
			esc_html__( 'Warn me below', 'povikvane-for-woocommerce' ),
			esc_attr( $name ),
			(int) $values['low_balance_threshold'],
			esc_html__( 'credits', 'povikvane-for-woocommerce' )
		);
		Ui::field_close();

		Ui::card_close();

		Ui::card_open( __( 'Data and privacy', 'povikvane-for-woocommerce' ), __( 'Message text is personal data, so it is erased after a while. Records are kept longer for billing questions.', 'povikvane-for-woocommerce' ) );

		Ui::field_open( __( 'Message text', 'povikvane-for-woocommerce' ), 'povikvane_keep_text', __( '0 keeps it forever.', 'povikvane-for-woocommerce' ) );
		printf(
			'<div class="pvk-inline"><span>%1$s</span> <input type="number" id="povikvane_keep_text" name="%2$s[retention_body_days]" value="%3$d" min="0" max="3650"> <span>%4$s</span></div>',
			esc_html__( 'Erase after', 'povikvane-for-woocommerce' ),
			esc_attr( $name ),
			(int) $values['retention_body_days'],
			esc_html__( 'days', 'povikvane-for-woocommerce' )
		);
		Ui::field_close();

		Ui::field_open( __( 'Message records', 'povikvane-for-woocommerce' ), 'povikvane_keep_rows', __( 'Order, status, credits and phone number. 0 keeps them forever.', 'povikvane-for-woocommerce' ) );
		printf(
			'<div class="pvk-inline"><span>%1$s</span> <input type="number" id="povikvane_keep_rows" name="%2$s[retention_row_days]" value="%3$d" min="0" max="3650"> <span>%4$s</span></div>',
			esc_html__( 'Delete after', 'povikvane-for-woocommerce' ),
			esc_attr( $name ),
			(int) $values['retention_row_days'],
			esc_html__( 'days', 'povikvane-for-woocommerce' )
		);
		Ui::field_close();

		Ui::field_open( __( 'CSV export', 'povikvane-for-woocommerce' ) );
		Ui::toggle( $name . '[export_full_numbers]', (bool) $values['export_full_numbers'], __( 'Include full phone numbers in the message log export', 'povikvane-for-woocommerce' ), __( 'Otherwise numbers are shortened.', 'povikvane-for-woocommerce' ) );
		Ui::field_close();

		Ui::card_close();
	}
}
