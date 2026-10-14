<?php
/**
 * Message template editor.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Admin;

use Povikvane\WooCommerce\Messages\Placeholders;
use Povikvane\WooCommerce\Orders\TriggerRepository;

use const Povikvane\WooCommerce\PLUGIN_FILE;
use const Povikvane\WooCommerce\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * The table of order statuses with an on/off switch, delay and text for each,
 * plus a live estimate of how many SMS segments (credits) a text costs.
 */
final class TemplatesSection {

	/**
	 * Constructor.
	 *
	 * @param TriggerRepository $triggers Trigger configuration.
	 */
	public function __construct( private readonly TriggerRepository $triggers ) {}

	/**
	 * Print the editor. Must be called inside the settings form.
	 */
	public function render(): void {
		echo '<h2>' . esc_html__( 'Messages', 'povikvane-for-woocommerce' ) . '</h2>';
		echo '<p>' . esc_html__( 'Choose which order statuses send a text message to the customer, and what it says. Only order-related information belongs here; do not use these messages for promotions.', 'povikvane-for-woocommerce' ) . '</p>';

		echo '<p>' . esc_html__( 'Placeholders:', 'povikvane-for-woocommerce' ) . ' ';
		foreach ( Placeholders::NAMES as $name ) {
			echo '<code>{' . esc_html( $name ) . '}</code> ';
		}
		echo '</p>';

		echo '<table class="widefat striped" id="povikvane-templates"><thead><tr>';
		foreach ( array( __( 'Order status', 'povikvane-for-woocommerce' ), __( 'Send', 'povikvane-for-woocommerce' ), __( 'Delay (minutes)', 'povikvane-for-woocommerce' ), __( 'Message', 'povikvane-for-woocommerce' ) ) as $heading ) {
			echo '<th>' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $this->triggers->statuses() as $slug => $label ) {
			$trigger = $this->triggers->for_status( $slug );
			$name    = TriggerRepository::OPTION . '[' . $slug . ']';

			printf(
				'<tr><th scope="row">%1$s</th><td><input type="checkbox" name="%2$s[enabled]" value="1"%3$s aria-label="%4$s"></td><td><input type="number" name="%2$s[delay]" value="%5$d" min="0" max="1440" class="small-text"></td><td><textarea name="%2$s[template]" rows="3" maxlength="1000" class="large-text" data-povikvane-template>%6$s</textarea><div class="povikvane-estimate description" aria-live="polite"></div></td></tr>',
				esc_html( $label ),
				esc_attr( $name ),
				checked( $trigger->enabled, true, false ),
				esc_attr( sprintf( /* translators: %s: order status name. */ __( 'Send a message when an order becomes "%s"', 'povikvane-for-woocommerce' ), $label ) ),
				(int) $trigger->delay_minutes,
				esc_textarea( $trigger->template )
			);
		}

		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'A delay lets you cancel by changing the status again; a message is only sent if the order still has the status it was created for.', 'povikvane-for-woocommerce' ) . '</p>';
	}

	/**
	 * Load the editor scripts.
	 */
	public function enqueue(): void {
		wp_enqueue_script( 'povikvane-segments', plugins_url( 'assets/js/segments.js', PLUGIN_FILE ), array(), VERSION, true );
		wp_enqueue_script( 'povikvane-admin', plugins_url( 'assets/js/admin.js', PLUGIN_FILE ), array( 'povikvane-segments' ), VERSION, true );

		$shop = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		$common = array(
			'order_number'     => '1234',
			'order_total'      => '45.00 EUR',
			'shop_name'        => $shop,
			'order_status_url' => home_url( '/checkout/order-received/1234/?key=wc_order_abcdefghijklm' ),
			'tracking_number'  => 'TRK1234567890',
			'tracking_url'     => 'https://track.example.com/TRK1234567890',
		);

		wp_localize_script(
			'povikvane-admin',
			'povikvaneAdmin',
			array(
				'names'   => Placeholders::NAMES,
				'samples' => array(
					'latin'    => $common + array(
						'first_name' => 'Maria',
						'last_name'  => 'Petrova',
					),
					'cyrillic' => $common + array(
						'first_name' => 'Мария',
						'last_name'  => 'Петрова',
					),
				),
				'i18n'    => array(
					'gsm7'       => __( 'Standard SMS', 'povikvane-for-woocommerce' ),
					'ucs2'       => __( 'Unicode SMS (e.g. Cyrillic)', 'povikvane-for-woocommerce' ),
					'characters' => __( 'characters', 'povikvane-for-woocommerce' ),
					'segment'    => __( 'SMS part', 'povikvane-for-woocommerce' ),
					'segments'   => __( 'SMS parts', 'povikvane-for-woocommerce' ),
					'credits'    => __( 'credits', 'povikvane-for-woocommerce' ),
					'cyrillic'   => __( 'With a Cyrillic name:', 'povikvane-for-woocommerce' ),
					'unknown'    => __( 'Unknown placeholder:', 'povikvane-for-woocommerce' ),
					'empty'      => __( 'Empty message: nothing will be sent.', 'povikvane-for-woocommerce' ),
				),
			)
		);
	}
}
