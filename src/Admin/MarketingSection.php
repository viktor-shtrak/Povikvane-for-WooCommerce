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

		echo '<h2>' . esc_html__( 'Promotional messages', 'povikvane-for-woocommerce' ) . '</h2>';
		echo '<p>' . esc_html__( 'Promotional SMS may only be sent to customers who agreed to them. The plugin asks with an unticked checkbox at checkout, checks the agreement again just before each message, adds an unsubscribe link to every promotional message and only sends during the hours below. Have the wording reviewed before you switch this on.', 'povikvane-for-woocommerce' ) . '</p>';

		echo '<table class="form-table" role="presentation">';

		printf(
			'<tr><th scope="row">%1$s</th><td><label><input type="checkbox" name="%2$s[enabled]" value="1"%3$s> %4$s</label></td></tr>',
			esc_html__( 'Promotional messages', 'povikvane-for-woocommerce' ),
			esc_attr( $name ),
			checked( $all['enabled'], true, false ),
			esc_html__( 'Ask customers for their agreement and allow promotional messages', 'povikvane-for-woocommerce' )
		);

		printf(
			'<tr><th scope="row"><label for="povikvane_consent_text">%1$s</label></th><td><textarea id="povikvane_consent_text" name="%2$s[consent_text]" rows="3" maxlength="400" class="large-text">%3$s</textarea><p class="description">%4$s</p></td></tr>',
			esc_html__( 'Checkbox wording', 'povikvane-for-woocommerce' ),
			esc_attr( $name ),
			esc_textarea( $this->settings->consent_text() ),
			esc_html__( 'Shown next to the unticked checkbox at checkout and in My Account. A short fingerprint of the wording is stored with each agreement.', 'povikvane-for-woocommerce' )
		);

		printf(
			'<tr><th scope="row">%1$s</th><td>%2$s <input type="number" name="%3$s[quiet_open]" value="%4$d" min="0" max="23" class="small-text"> %5$s <input type="number" name="%3$s[quiet_close]" value="%6$d" min="0" max="23" class="small-text"><p class="description">%7$s</p></td></tr>',
			esc_html__( 'Sending hours', 'povikvane-for-woocommerce' ),
			esc_html__( 'from', 'povikvane-for-woocommerce' ),
			esc_attr( $name ),
			(int) $all['quiet_open'],
			esc_html__( 'to', 'povikvane-for-woocommerce' ),
			(int) $all['quiet_close'],
			esc_html__( 'Whole hours in the shop time zone. Promotional messages wait for the next opening. Order messages are not affected. Equal hours mean no limit.', 'povikvane-for-woocommerce' )
		);

		printf(
			'<tr><th scope="row">%1$s</th><td><input type="number" name="%2$s[cap_max]" value="%3$d" min="0" max="100" class="small-text"> %4$s <input type="number" name="%2$s[cap_days]" value="%5$d" min="0" max="365" class="small-text"> %6$s<p class="description">%7$s</p></td></tr>',
			esc_html__( 'Frequency limit', 'povikvane-for-woocommerce' ),
			esc_attr( $name ),
			(int) $all['cap_max'],
			esc_html__( 'message(s) per phone number in', 'povikvane-for-woocommerce' ),
			(int) $all['cap_days'],
			esc_html__( 'days', 'povikvane-for-woocommerce' ),
			esc_html__( '0 turns the limit off.', 'povikvane-for-woocommerce' )
		);

		printf(
			'<tr><th scope="row">%1$s</th><td><label><input type="checkbox" name="%2$s[followup_enabled]" value="1"%3$s> %4$s <input type="number" name="%2$s[followup_days]" value="%5$d" min="1" max="60" class="small-text"> %6$s</label><p><textarea name="%2$s[followup_template]" rows="3" maxlength="600" class="large-text" data-povikvane-template>%7$s</textarea><span class="povikvane-estimate description" aria-live="polite"></span></p><p class="description">%8$s</p></td></tr>',
			esc_html__( 'Review request', 'povikvane-for-woocommerce' ),
			esc_attr( $name ),
			checked( $all['followup_enabled'], true, false ),
			esc_html__( 'Send a message', 'povikvane-for-woocommerce' ),
			(int) $all['followup_days'],
			esc_html__( 'days after an order is completed, to customers who agreed', 'povikvane-for-woocommerce' ),
			esc_textarea( $this->settings->followup()->template ),
			esc_html__( 'An unsubscribe link is added automatically if the text does not contain {optout_link}. The link counts towards the message length.', 'povikvane-for-woocommerce' )
		);

		echo '</table>';

		$count = $this->ledger->count_opted_in();
		printf(
			'<p>%1$s <a class="button" href="%2$s">%3$s</a></p><p class="description">%4$s</p>',
			esc_html(
				sprintf(
					/* translators: %d: number of customers. */
					_n( '%d customer has agreed to promotional messages.', '%d customers have agreed to promotional messages.', $count, 'povikvane-for-woocommerce' ),
					$count
				)
			),
			esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=povikvane_export_consents' ), 'povikvane_export_consents' ) ),
			esc_html__( 'Download list (CSV)', 'povikvane-for-woocommerce' ),
			esc_html__( 'The file contains full phone numbers, ready to import as contacts in Povikvane for your own campaigns. Handle it as personal data.', 'povikvane-for-woocommerce' )
		);
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
