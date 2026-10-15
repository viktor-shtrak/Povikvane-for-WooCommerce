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
use Povikvane\WooCommerce\Orders\Trigger;
use Povikvane\WooCommerce\Orders\TriggerRepository;
use Povikvane\WooCommerce\Viber\TemplateCatalog;
use Povikvane\WooCommerce\Viber\VariableMapper;
use Povikvane\WooCommerce\Viber\ViberPlanner;
use Povikvane\WooCommerce\Viber\ViberTemplate;

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
	 * @param TemplateCatalog   $catalog  Viber templates.
	 */
	public function __construct(
		private readonly TriggerRepository $triggers,
		private readonly TemplateCatalog $catalog = new TemplateCatalog()
	) {}

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
		echo '</p>';

		$viber = Capabilities::viber_ready( get_option( Capabilities::OPTION, array() ) );

		if ( ! $viber ) {
			$this->viber_unavailable_note( Capabilities::viber_status( get_option( Capabilities::OPTION, array() ) ) );
		}

		if ( $viber ) {
			echo '<details class="pvk-details"><summary>' . esc_html__( 'How Viber works', 'povikvane-for-woocommerce' ) . '</summary><p>' . esc_html__( 'Viber needs an approved transactional template from your Povikvane account. If Viber cannot be used for a message, it goes out as an SMS. A Viber message costs 0.8 credits when Viber delivers it; a fallback SMS is charged as in your Povikvane price list. The SMS text must fit in one SMS part (160 characters, 70 with Cyrillic) for the automatic fallback, and a template that needs a link or a long value is sent as an SMS. New templates appear within 15 minutes, or when you check the connection.', 'povikvane-for-woocommerce' ) . '</p></details>';
		}

		echo '</div><div style="margin-top:12px">';

		foreach ( $this->ordered_statuses() as $slug => $label ) {
			$trigger = $this->triggers->for_status( $slug );
			$name    = TriggerRepository::OPTION . '[' . $slug . ']';

			echo '<div class="pvk-trigger' . ( $trigger->enabled ? ' is-on' : '' ) . '"><div>';
			Ui::toggle( $name . '[enabled]', $trigger->enabled, $label );
			printf(
				'<div class="pvk-trigger__field"><label for="pvk-delay-%1$s">%2$s</label><div class="pvk-inline"><input type="number" id="pvk-delay-%1$s" name="%3$s[delay]" value="%4$d" min="0" max="1440"><span>%5$s</span></div></div>',
				esc_attr( $slug ),
				esc_html__( 'Wait', 'povikvane-for-woocommerce' ),
				esc_attr( $name ),
				(int) $trigger->delay_minutes,
				esc_html__( 'minutes first', 'povikvane-for-woocommerce' )
			);
			$this->channel_field( $name, $slug, $trigger, $viber );
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
	 * How many order statuses are set to Viber but will go out as SMS because Viber is
	 * unavailable or the template is not ready.
	 */
	public function viber_fallback_count(): int {
		$ready = Capabilities::viber_ready( get_option( Capabilities::OPTION, array() ) );
		$count = 0;

		foreach ( array_keys( $this->triggers->statuses() ) as $slug ) {
			$trigger = $this->triggers->for_status( $slug );

			if ( ! $trigger->enabled || Channel::Viber->value !== $trigger->channel ) {
				continue;
			}

			$template = '' === $trigger->viber_template ? null : $this->catalog->find( $trigger->viber_template );

			if ( ! $ready || null === $template || null !== ViberPlanner::problem( $template ) || null !== ViberPlanner::selection_problem( $template, ViberPlanner::choices_for( $trigger->viber_map, $template->id ) ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Tell why Viber is not offered, so its absence is never a mystery.
	 *
	 * @param string $status A Capabilities::VIBER_* constant.
	 */
	private function viber_unavailable_note( string $status ): void {
		echo '<p class="pvk-card__intro pvk-trigger__hint--warn">' . esc_html( ViberView::reason( $status ) ) . '</p>';
	}

	/**
	 * The channel choice for one status. Without Viber the stored choice is kept in
	 * hidden fields, so saving the form never silently changes it.
	 *
	 * @param string  $name    Field name prefix.
	 * @param string  $slug    Status slug.
	 * @param Trigger $trigger Stored configuration.
	 * @param bool    $viber   Whether Viber is available.
	 */
	private function channel_field( string $name, string $slug, Trigger $trigger, bool $viber ): void {
		if ( ! $viber ) {
			printf(
				'<input type="hidden" name="%1$s[channel]" value="%2$s"><input type="hidden" name="%1$s[viber_template]" value="%3$s">',
				esc_attr( $name ),
				esc_attr( $trigger->channel ),
				esc_attr( $trigger->viber_template )
			);

			foreach ( $trigger->viber_map as $template_id => $fields ) {
				foreach ( $fields as $variable => $placeholder ) {
					printf( '<input type="hidden" name="%1$s[viber_map][%2$s][%3$s]" value="%4$s">', esc_attr( $name ), esc_attr( (string) $template_id ), esc_attr( (string) $variable ), esc_attr( $placeholder ) );
				}
			}

			return;
		}

		printf(
			'<div class="pvk-trigger__field"><label for="pvk-channel-%1$s">%2$s</label><select id="pvk-channel-%1$s" name="%3$s[channel]"><option value="sms"%4$s>%5$s</option><option value="viber"%6$s>%7$s</option></select></div>',
			esc_attr( $slug ),
			esc_html__( 'Send via', 'povikvane-for-woocommerce' ),
			esc_attr( $name ),
			selected( Channel::Sms->value, $trigger->channel, false ),
			esc_html__( 'SMS', 'povikvane-for-woocommerce' ),
			selected( Channel::Viber->value, $trigger->channel, false ),
			esc_html__( 'Viber, SMS if not delivered', 'povikvane-for-woocommerce' )
		);

		printf(
			'<div class="pvk-trigger__field" data-pvk-visible-if="pvk-channel-%1$s=viber"><label for="pvk-template-%1$s">%2$s</label><select id="pvk-template-%1$s" name="%3$s[viber_template]"><option value="">%4$s</option>',
			esc_attr( $slug ),
			esc_html__( 'Viber template', 'povikvane-for-woocommerce' ),
			esc_attr( $name ),
			esc_html__( 'Choose a template', 'povikvane-for-woocommerce' )
		);

		foreach ( $this->catalog->all() as $template ) {
			$problem = ViberPlanner::problem( $template );

			printf(
				'<option value="%1$s"%2$s%3$s>%4$s</option>',
				esc_attr( $template->id ),
				selected( $template->id, $trigger->viber_template, false ),
				null === $problem ? '' : ' disabled',
				esc_html( null === $problem ? $template->name : $template->name . ' (' . ViberView::problem_label( $problem ) . ')' )
			);
		}

		echo '</select></div>';

		foreach ( $this->catalog->all() as $template ) {
			if ( null === ViberPlanner::problem( $template ) ) {
				$this->value_fields( $name, $slug, $trigger, $template );
			}
		}

		if ( Channel::Viber->value === $trigger->channel ) {
			$this->selection_notice( $trigger );
		}
	}

	/**
	 * For a template with custom fields, choose what each field is filled with. Only the
	 * fields of the chosen template are shown. firstName and lastName fill themselves.
	 *
	 * @param string        $name     Field name prefix.
	 * @param string        $slug     Status slug.
	 * @param Trigger       $trigger  Stored configuration.
	 * @param ViberTemplate $template Template.
	 */
	private function value_fields( string $name, string $slug, Trigger $trigger, ViberTemplate $template ): void {
		$fields = VariableMapper::needs_choice( $template->variables );

		if ( array() === $fields ) {
			return;
		}

		$chosen  = ViberPlanner::choices_for( $trigger->viber_map, $template->id );
		$options = VariableMapper::choices( Placeholders::NAMES );
		$key     = substr( md5( $template->id ), 0, 8 );

		printf( '<div data-pvk-visible-if="pvk-template-%s=%s">', esc_attr( $slug ), esc_attr( $template->id ) );

		foreach ( $fields as $index => $variable ) {
			$id = 'pvk-value-' . $slug . '-' . $key . '-' . $index;

			printf(
				'<div class="pvk-trigger__field"><label for="%1$s">%2$s</label><select id="%1$s" name="%3$s[viber_map][%4$s][%5$s]"><option value="">%6$s</option>',
				esc_attr( $id ),
				esc_html(
					sprintf(
						/* translators: %s: name of a field in a Viber template, for example customField1. */
						__( 'Fill %s with', 'povikvane-for-woocommerce' ),
						$variable
					)
				),
				esc_attr( $name ),
				esc_attr( $template->id ),
				esc_attr( $variable ),
				esc_html__( 'Choose a value', 'povikvane-for-woocommerce' )
			);

			foreach ( $options as $placeholder ) {
				printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $placeholder ), selected( $placeholder, $chosen[ $variable ] ?? '', false ), esc_html( ViberView::placeholder_label( $placeholder ) ) );
			}

			echo '</select></div>';
		}

		echo '</div>';
	}

	/**
	 * Warn when the chosen template will not be used.
	 *
	 * @param Trigger $trigger Stored configuration.
	 */
	private function selection_notice( Trigger $trigger ): void {
		$template = '' === $trigger->viber_template ? null : $this->catalog->find( $trigger->viber_template );
		$problem  = null === $template ? ViberPlanner::PROBLEM_NOT_APPROVED : ( ViberPlanner::problem( $template ) ?? ViberPlanner::selection_problem( $template, ViberPlanner::choices_for( $trigger->viber_map, $template->id ) ) );

		if ( null === $template ) {
			echo '<p class="pvk-trigger__hint pvk-trigger__hint--warn">' . esc_html__( 'No template chosen, so this message is sent as an SMS.', 'povikvane-for-woocommerce' ) . '</p>';
		} elseif ( null !== $problem ) {
			echo '<p class="pvk-trigger__hint pvk-trigger__hint--warn">' . esc_html(
				sprintf(
					/* translators: %s: reason, for example "awaiting approval". */
					__( 'This template cannot be used yet (%s), so this message is sent as an SMS.', 'povikvane-for-woocommerce' ),
					ViberView::problem_label( $problem )
				)
			) . '</p>';
		}
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
