<?php
/**
 * Promotional message settings.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Admin;

use Povikvane\WooCommerce\Marketing\ConsentEvent;
use Povikvane\WooCommerce\Marketing\ConsentRepository;
use Povikvane\WooCommerce\Marketing\Defaults;
use Povikvane\WooCommerce\Marketing\MarketingSettings;
use Povikvane\WooCommerce\Support\Csv;

defined( 'ABSPATH' ) || exit;

/**
 * The "Promotional messages" part of the settings form, and the download of
 * customers who agreed.
 */
final class MarketingSection {

	private const CAPABILITY = 'manage_woocommerce';
	private const EXPORT_MAX = 50000;
	private const PAGE_SIZE  = 500;

	/**
	 * Constructor.
	 *
	 * @param MarketingSettings $settings Promotional settings.
	 * @param ConsentRepository $ledger   Consent records.
	 * @param string            $locale   Site locale.
	 */
	public function __construct(
		private readonly MarketingSettings $settings,
		private readonly ConsentRepository $ledger,
		private readonly string $locale
	) {}

	/**
	 * Register the download action.
	 */
	public function register(): void {
		add_action( 'admin_post_povikvane_export_consents', array( $this, 'handle_export' ) );
	}

	/**
	 * Sanitise the submitted settings.
	 *
	 * @param mixed $input Submitted value.
	 * @return array<string,mixed>
	 */
	public function sanitize( mixed $input ): array {
		$input = is_array( $input ) ? $input : array();

		$consent  = trim( sanitize_textarea_field( (string) ( $input['consent_text'] ?? '' ) ) );
		$template = trim( sanitize_textarea_field( (string) ( $input['followup_template'] ?? '' ) ) );

		return array(
			'enabled'           => ! empty( $input['enabled'] ),
			// Text equal to the default is not stored, so the default can follow the site language.
			'consent_text'      => Defaults::consent_text( $this->locale ) === $consent ? '' : $this->shorten( $consent, 400 ),
			'quiet_open'        => MarketingSettings::clamp( (int) ( $input['quiet_open'] ?? 9 ), 0, 23 ),
			'quiet_close'       => MarketingSettings::clamp( (int) ( $input['quiet_close'] ?? 20 ), 0, 23 ),
			'cap_max'           => MarketingSettings::clamp( (int) ( $input['cap_max'] ?? 1 ), 0, 100 ),
			'cap_days'          => MarketingSettings::clamp( (int) ( $input['cap_days'] ?? 7 ), 0, 365 ),
			'followup_enabled'  => ! empty( $input['followup_enabled'] ),
			'followup_days'     => MarketingSettings::clamp( (int) ( $input['followup_days'] ?? 7 ), 1, 60 ),
			'followup_template' => Defaults::followup_template( $this->locale ) === $template ? '' : $this->shorten( $template, 600 ),
		);
	}

	/**
	 * Print the section. Must be called inside the settings form.
	 */
	public function render(): void {
		$all  = $this->settings->all();
		$name = MarketingSettings::OPTION;

		Ui::card_open( __( 'Promotional messages', 'povikvane-for-woocommerce' ), __( 'Promotional SMS may only be sent to customers who agreed to them. The plugin asks with an unticked checkbox at checkout, checks the agreement again just before each message, adds an unsubscribe link to every promotional message and only sends during the hours below. Have the wording reviewed before you switch this on.', 'povikvane-for-woocommerce' ), $all['enabled'] ? '' : 'pvk-card--accent' );

		Ui::field_open( __( 'Status', 'povikvane-for-woocommerce' ) );
		Ui::toggle( $name . '[enabled]', $all['enabled'], __( 'Ask customers for their agreement and allow promotional messages', 'povikvane-for-woocommerce' ), __( 'Off by default. Nothing promotional is ever sent while this is off.', 'povikvane-for-woocommerce' ) );
		Ui::field_close();

		Ui::field_open( __( 'Checkbox wording', 'povikvane-for-woocommerce' ), 'povikvane_consent_text', __( 'Shown next to the unticked checkbox at checkout and in My Account. A short fingerprint of the wording is stored with each agreement.', 'povikvane-for-woocommerce' ) );
		printf(
			'<textarea id="povikvane_consent_text" name="%1$s[consent_text]" rows="3" maxlength="400">%2$s</textarea>',
			esc_attr( $name ),
			esc_textarea( $this->settings->consent_text() )
		);
		Ui::field_close();

		Ui::card_close();

		Ui::card_open( __( 'When and how often', 'povikvane-for-woocommerce' ) );

		Ui::field_open( __( 'Sending hours', 'povikvane-for-woocommerce' ), '', __( 'Whole hours in the shop time zone. Promotional messages wait for the next opening. Order messages are not affected. Equal hours mean no limit.', 'povikvane-for-woocommerce' ) );
		printf(
			'<div class="pvk-inline"><span>%1$s</span> <input type="number" name="%2$s[quiet_open]" value="%3$d" min="0" max="23" aria-label="%4$s"> <span>%5$s</span> <input type="number" name="%2$s[quiet_close]" value="%6$d" min="0" max="23" aria-label="%7$s"></div>',
			esc_html__( 'From', 'povikvane-for-woocommerce' ),
			esc_attr( $name ),
			(int) $all['quiet_open'],
			esc_attr__( 'Opening hour', 'povikvane-for-woocommerce' ),
			esc_html__( 'to', 'povikvane-for-woocommerce' ),
			(int) $all['quiet_close'],
			esc_attr__( 'Closing hour', 'povikvane-for-woocommerce' )
		);
		Ui::field_close();

		Ui::field_open( __( 'Frequency limit', 'povikvane-for-woocommerce' ), '', __( '0 turns the limit off.', 'povikvane-for-woocommerce' ) );
		printf(
			'<div class="pvk-inline"><input type="number" name="%1$s[cap_max]" value="%2$d" min="0" max="100" aria-label="%3$s"> <span>%4$s</span> <input type="number" name="%1$s[cap_days]" value="%5$d" min="0" max="365" aria-label="%6$s"> <span>%7$s</span></div>',
			esc_attr( $name ),
			(int) $all['cap_max'],
			esc_attr__( 'Messages allowed', 'povikvane-for-woocommerce' ),
			esc_html__( 'message(s) per phone number in', 'povikvane-for-woocommerce' ),
			(int) $all['cap_days'],
			esc_attr__( 'Period in days', 'povikvane-for-woocommerce' ),
			esc_html__( 'days', 'povikvane-for-woocommerce' )
		);
		Ui::field_close();

		Ui::card_close();

		Ui::card_open( __( 'Review request', 'povikvane-for-woocommerce' ), __( 'Ask customers who agreed for feedback after their order is completed.', 'povikvane-for-woocommerce' ) );

		Ui::field_open( __( 'Status', 'povikvane-for-woocommerce' ) );
		Ui::toggle( $name . '[followup_enabled]', $all['followup_enabled'], __( 'Send a review request', 'povikvane-for-woocommerce' ) );
		printf(
			'<div class="pvk-inline"><input type="number" name="%1$s[followup_days]" value="%2$d" min="1" max="60" aria-label="%3$s"> <span>%4$s</span></div>',
			esc_attr( $name ),
			(int) $all['followup_days'],
			esc_attr__( 'Days after completion', 'povikvane-for-woocommerce' ),
			esc_html__( 'days after an order is completed', 'povikvane-for-woocommerce' )
		);
		Ui::field_close();

		Ui::field_open( __( 'Message', 'povikvane-for-woocommerce' ), 'povikvane_followup_template', __( 'An unsubscribe link is added automatically if the text does not contain {optout_link}. The link counts towards the message length.', 'povikvane-for-woocommerce' ) );
		printf(
			'<textarea id="povikvane_followup_template" name="%1$s[followup_template]" rows="3" maxlength="600" data-povikvane-template>%2$s</textarea><div class="pvk-estimate povikvane-estimate" aria-live="polite"></div>',
			esc_attr( $name ),
			esc_textarea( $this->settings->followup()->template )
		);
		Ui::field_close();

		Ui::card_close();

		$count = $this->ledger->count_opted_in();

		Ui::card_open( __( 'Customers who agreed', 'povikvane-for-woocommerce' ) );
		Ui::field_open( __( 'Opted in', 'povikvane-for-woocommerce' ), '', __( 'The file contains full phone numbers, ready to import as contacts in Povikvane for your own campaigns. Handle it as personal data.', 'povikvane-for-woocommerce' ) );
		printf(
			'<p><strong>%1$s</strong></p><p><a class="pvk-btn" href="%2$s">%3$s</a></p>',
			esc_html(
				sprintf(
					/* translators: %d: number of customers. */
					_n( '%d customer has agreed to promotional messages.', '%d customers have agreed to promotional messages.', $count, 'povikvane-for-woocommerce' ),
					$count
				)
			),
			esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=povikvane_export_consents' ), 'povikvane_export_consents' ) ),
			esc_html__( 'Download list (CSV)', 'povikvane-for-woocommerce' )
		);
		Ui::field_close();
		Ui::card_close();
	}

	/**
	 * Download customers who agreed, in the contact import format of Povikvane.
	 */
	public function handle_export(): void {
		check_admin_referer( 'povikvane_export_consents' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'povikvane-for-woocommerce' ), '', array( 'response' => 403 ) );
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="povikvane-sms-consents-' . gmdate( 'Ymd-His' ) . '.csv"' );

		echo "\xEF\xBB\xBF"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed bytes (UTF-8 marker for Excel).

		$header = Csv::row( array( 'phoneNumber', 'firstName', 'lastName', 'customField1', 'groupName' ) );
		echo $header . "\r\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Every cell is quoted by Csv::cell().

		for ( $offset = 0; $offset < self::EXPORT_MAX; $offset += self::PAGE_SIZE ) {
			$events = $this->ledger->opted_in( self::PAGE_SIZE, $offset );

			foreach ( $events as $event ) {
				$line = Csv::row(
					array(
						(string) $event->phone,
						$this->name_for( $event, 'first' ),
						$this->name_for( $event, 'last' ),
						gmdate( 'Y-m-d', $event->created_at ),
						'Webshop SMS opt-in',
					)
				);

				echo $line . "\r\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Every cell is quoted by Csv::cell().
			}

			if ( count( $events ) < self::PAGE_SIZE ) {
				break;
			}
		}

		exit;
	}

	/**
	 * A customer's first or last name, from the order or the account.
	 *
	 * @param ConsentEvent $event Consent event.
	 * @param string       $part  "first" or "last".
	 */
	private function name_for( ConsentEvent $event, string $part ): string {
		if ( $event->order_id > 0 ) {
			$order = wc_get_order( $event->order_id );

			if ( $order instanceof \WC_Order ) {
				$name = 'first' === $part ? $order->get_billing_first_name() : $order->get_billing_last_name();

				if ( '' !== $name ) {
					return $name;
				}
			}
		}

		return $event->user_id > 0 ? (string) get_user_meta( $event->user_id, $part . '_name', true ) : '';
	}

	/**
	 * Cut text to a length.
	 *
	 * @param string $text   Text.
	 * @param int    $length Maximum characters.
	 */
	private function shorten( string $text, int $length ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $length ) : substr( $text, 0, $length );
	}
}
