<?php
/**
 * Message template editor.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Admin;

use Povikvane\WooCommerce\Api\Capabilities;
use Povikvane\WooCommerce\Messages\Channel;
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
		echo '<section class="pvk-card"><div class="pvk-card__head"><h2 class="pvk-card__title">' . esc_html__( 'Order messages', 'povikvane-for-woocommerce' ) . '</h2><p class="pvk-card__intro">' . esc_html__( 'Choose which order statuses send a text message to the customer, and what it says. Only order-related information belongs here; use the Promotions tab for anything promotional.', 'povikvane-for-woocommerce' ) . '</p>';

		echo '<p class="pvk-card__intro">' . esc_html__( 'You can use:', 'povikvane-for-woocommerce' ) . ' ';
		foreach ( Placeholders::NAMES as $name ) {
			if ( 'optout_link' !== $name ) {
				echo '<code>{' . esc_html( $name ) . '}</code> ';
			}
		}
		echo '</p></div><div style="margin-top:12px">';

		$viber = Capabilities::viber_ready( get_option( Capabilities::OPTION, array() ) );

		foreach ( $this->ordered_statuses() as $slug => $label ) {
			$trigger = $this->triggers->for_status( $slug );
			$name    = TriggerRepository::OPTION . '[' . $slug . ']';

			echo '<div class="pvk-trigger' . ( $trigger->enabled ? ' is-on' : '' ) . '"><div>';
			Ui::toggle( $name . '[enabled]', $trigger->enabled, $label );
			printf(
				'<div class="pvk-trigger__delay"><label>%1$s <input type="number" name="%2$s[delay]" value="%3$d" min="0" max="1440"> %4$s</label></div>',
				esc_html__( 'Wait', 'povikvane-for-woocommerce' ),
				esc_attr( $name ),
				(int) $trigger->delay_minutes,
				esc_html__( 'minutes first', 'povikvane-for-woocommerce' )
			);
			$this->channel_field( $name, $slug, $trigger->channel, $viber );
			echo '</div><div>';
			printf(
				'<label class="screen-reader-text" for="pvk-text-%1$s">%2$s</label><textarea id="pvk-text-%1$s" name="%3$s[template]" rows="3" maxlength="1000" data-povikvane-template>%4$s</textarea><div class="pvk-estimate povikvane-estimate" aria-live="polite"></div>',
				esc_attr( $slug ),
				esc_html(
					sprintf(
						/* translators: %s: order status name. */
						__( 'Message when an order becomes "%s"', 'povikvane-for-woocommerce' ),
						$label
					)
				),
				esc_attr( $name ),
				esc_textarea( $trigger->template )
			);
			echo '</div></div>';
		}

		echo '</div><div class="pvk-card__foot">' . esc_html__( 'A delay lets you cancel by changing the status again. A message is only sent if the order still has the status it was created for.', 'povikvane-for-woocommerce' ) . '</div></section>';
	}

	/**
	 * The channel choice for one status. Without Viber the stored choice is kept in a
	 * hidden field, so saving the form never silently changes it.
	 *
	 * @param string $name    Field name prefix.
	 * @param string $slug    Status slug.
	 * @param string $channel Stored channel.
	 * @param bool   $viber   Whether Viber is available.
	 */
	private function channel_field( string $name, string $slug, string $channel, bool $viber ): void {
		if ( ! $viber ) {
			printf( '<input type="hidden" name="%s[channel]" value="%s">', esc_attr( $name ), esc_attr( $channel ) );
			return;
		}

		printf(
			'<div class="pvk-trigger__delay"><label for="pvk-channel-%1$s">%2$s</label> <select id="pvk-channel-%1$s" name="%3$s[channel]"><option value="sms"%4$s>%5$s</option><option value="viber"%6$s>%7$s</option></select></div>',
			esc_attr( $slug ),
			esc_html__( 'Send via', 'povikvane-for-woocommerce' ),
			esc_attr( $name ),
			selected( Channel::Sms->value, $channel, false ),
			esc_html__( 'SMS', 'povikvane-for-woocommerce' ),
			selected( Channel::Viber->value, $channel, false ),
			esc_html__( 'Viber, SMS if not delivered', 'povikvane-for-woocommerce' )
		);
	}

	/**
	 * Statuses with the common ones first, so the list starts with what most shops use.
	 *
	 * @return array<string,string>
	 */
	private function ordered_statuses(): array {
		$statuses = $this->triggers->statuses();
		$priority = array_flip( array( 'processing', 'completed', 'on-hold', 'cancelled', 'refunded', 'failed' ) );

		uksort( $statuses, static fn( string $a, string $b ): int => ( $priority[ $a ] ?? 99 ) <=> ( $priority[ $b ] ?? 99 ) );

		return $statuses;
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
			'shop_url'         => untrailingslashit( home_url() ),
			'optout_link'      => home_url( '/?po=0123456789ab-0123456789ab' ),
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
					'credit'     => __( 'credit', 'povikvane-for-woocommerce' ),
					'credits'    => __( 'credits', 'povikvane-for-woocommerce' ),
					'cyrillic'   => __( 'With a Cyrillic name:', 'povikvane-for-woocommerce' ),
					'unknown'    => __( 'Unknown placeholder:', 'povikvane-for-woocommerce' ),
					'empty'      => __( 'Empty message: nothing will be sent.', 'povikvane-for-woocommerce' ),
				),
			)
		);
	}
}
