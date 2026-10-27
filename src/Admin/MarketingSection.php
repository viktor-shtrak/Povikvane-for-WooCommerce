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
use Povikvane\WooCommerce\Marketing\ContactSync;
use Povikvane\WooCommerce\Marketing\UnavailableContactSync;
use Povikvane\WooCommerce\Marketing\Defaults;
use Povikvane\WooCommerce\Marketing\MarketingSettings;
use Povikvane\WooCommerce\Marketing\OptOutForm;
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
	 * @param ContactSync       $sync     Copies customers to Povikvane.
	 */
	public function __construct(
		private readonly MarketingSettings $settings,
		private readonly ConsentRepository $ledger,
		private readonly string $locale,
		private readonly ContactSync $sync = new UnavailableContactSync()
	) {}

	/**
	 * Register the download action.
	 */
	public function register(): void {
		add_action( 'admin_post_povikvane_export_consents', array( $this, 'handle_export' ) );
		add_action( 'admin_post_povikvane_sync_contacts', array( $this, 'handle_sync' ) );
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

		Ui::card_open( __( 'Promotional messages', 'povikvane-for-woocommerce' ), __( 'Promotional SMS may only be sent to customers who agreed to them. The plugin asks with an unticked checkbox at checkout, checks the agreement again just before each message, adds an unsubscribe link to every promotional message and only sends during the hours below. Have the wording reviewed before you switch this on.', 'povikvane-for-woocommerce' ) );

		Ui::field_open( __( 'Status', 'povikvane-for-woocommerce' ) );
		Ui::toggle( $name . '[enabled]', $all['enabled'], __( 'Ask customers for their agreement and allow promotional messages', 'povikvane-for-woocommerce' ), __( 'Off by default. Nothing promotional is ever sent while this is off.', 'povikvane-for-woocommerce' ) );
		Ui::field_close();

		Ui::field_open( __( 'Checkbox wording', 'povikvane-for-woocommerce' ), 'povikvane_consent_text', __( 'Shown next to the unticked checkbox at checkout and in My Account. The exact wording is kept with each agreement.', 'povikvane-for-woocommerce' ) );
		printf(
			'<textarea id="povikvane_consent_text" name="%1$s[consent_text]" rows="3" maxlength="400">%2$s</textarea>',
			esc_attr( $name ),
			esc_textarea( $this->settings->consent_text() )
		);
		Ui::field_close();

		Ui::card_close();

		Ui::card_open( __( 'When and how often', 'povikvane-for-woocommerce' ) );

		Ui::field_open( __( 'Sending hours', 'povikvane-for-woocommerce' ), '', __( 'Whole hours in the shop time zone. Promotional messages wait for the next opening. Order messages are not affected. Set both to the same hour to allow any time.', 'povikvane-for-woocommerce' ) );
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

		$count = $this->ledger->count_opted_in();

		Ui::card_open( __( 'Customers who agreed', 'povikvane-for-woocommerce' ) );
		Ui::field_open( __( 'Agreement list', 'povikvane-for-woocommerce' ), '', __( 'The file contains full phone numbers, ready to import as contacts in Povikvane for your own campaigns. Handle it as personal data.', 'povikvane-for-woocommerce' ) );
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

		Ui::field_open( __( 'Send to Povikvane', 'povikvane-for-woocommerce' ), '', __( 'Copies the customers who agreed into a group of their own in Povikvane, ready for your campaigns there. New customers are added each time you press the button.', 'povikvane-for-woocommerce' ) );

		if ( $this->sync->available() ) {
			echo '<p>';
			Layout::action_button( __( 'Sync customers to Povikvane', 'povikvane-for-woocommerce' ), 'povikvane_sync_contacts', array(), 'pvk-btn pvk-btn--primary', sprintf( /* translators: %s: group name. */ __( 'Send the customers who agreed to Povikvane, into the group "%s"?', 'povikvane-for-woocommerce' ), ContactSync::GROUP_NAME ) );
			echo '</p>';
		} else {
			echo '<p><button type="button" class="pvk-btn pvk-btn--primary" disabled>' . esc_html__( 'Sync customers to Povikvane', 'povikvane-for-woocommerce' ) . '</button></p><p class="pvk-field__hint">' . esc_html__( 'Syncing from here is coming soon. Until then, download the list above and import it in Povikvane.', 'povikvane-for-woocommerce' ) . '</p>';
		}

		Ui::field_close();
		Ui::card_close();

		$this->render_optout();
	}

	/**
	 * How customers can stop promotional messages, with the shortcode to copy.
	 */
	private function render_optout(): void {
		Ui::card_open( __( 'How customers can opt out', 'povikvane-for-woocommerce' ), __( 'Anyone can stop promotional messages at any time. There are three ways, and none of them needs a login.', 'povikvane-for-woocommerce' ) );

		Ui::field_open( __( 'In every message', 'povikvane-for-woocommerce' ) );
		echo '<p>' . esc_html__( 'Every promotional message contains an unsubscribe link. One tap and the customer is off the list. This is always on.', 'povikvane-for-woocommerce' ) . '</p>';
		Ui::field_close();

		Ui::field_open( __( 'In My Account', 'povikvane-for-woocommerce' ) );
		echo '<p>' . esc_html__( 'Customers with an account can untick the promotional messages box under My Account > Account details.', 'povikvane-for-woocommerce' ) . '</p>';
		Ui::field_close();

		Ui::field_open( __( 'On a page of your shop', 'povikvane-for-woocommerce' ), 'povikvane-optout-shortcode', __( 'For people without an account, or who lost the message: a page where they type their phone number.', 'povikvane-for-woocommerce' ) );
		printf(
			'<div class="pvk-inline"><input type="text" id="povikvane-optout-shortcode" class="pvk-mono" readonly value="%1$s"> <button type="button" class="pvk-btn" data-povikvane-copy="#povikvane-optout-shortcode" data-copied="%2$s">%3$s</button></div>',
			esc_attr( '[' . OptOutForm::SHORTCODE . ']' ),
			esc_attr__( 'Copied', 'povikvane-for-woocommerce' ),
			esc_html__( 'Copy', 'povikvane-for-woocommerce' )
		);
		echo '<ol style="margin:12px 0 0 18px;padding:0"><li>' . esc_html__( 'In WordPress, go to Pages > Add New and give the page a name, for example "Stop promotional SMS".', 'povikvane-for-woocommerce' ) . '</li><li>' . esc_html__( 'Add a "Shortcode" block and paste the code above into it.', 'povikvane-for-woocommerce' ) . '</li><li>' . esc_html__( 'Publish the page and link to it from your footer or privacy page.', 'povikvane-for-woocommerce' ) . '</li></ol>';
		echo '<p class="pvk-field__hint" style="margin-top:8px">' . esc_html__( 'The form answers the same whether or not the number was on your list, so it tells nobody who is. It works even when promotional messages are switched off.', 'povikvane-for-woocommerce' ) . '</p>';
		Ui::field_close();

		Ui::card_close();
	}

	/**
	 * The review-request card, on its own tab.
	 */
	public function render_reviews(): void {
		$all  = $this->settings->all();
		$name = MarketingSettings::OPTION;

		Ui::card_open( __( 'Review request', 'povikvane-for-woocommerce' ), __( 'Ask customers who agreed for feedback after their order is completed.', 'povikvane-for-woocommerce' ) );

		if ( ! $all['enabled'] ) {
			Ui::note( __( 'Review requests are promotional messages and only go to customers who agreed. Switch on promotional messages in the Promotions tab first.', 'povikvane-for-woocommerce' ), 'warn' );
		}

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
	}

	/**
	 * Copy the customers who agreed to a group in Povikvane.
	 */
	public function handle_sync(): void {
		check_admin_referer( 'povikvane_sync_contacts' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'povikvane-for-woocommerce' ), '', array( 'response' => 403 ) );
		}

		if ( ! $this->sync->available() ) {
			Flash::add( 'error', __( 'Syncing customers to Povikvane is not available yet.', 'povikvane-for-woocommerce' ) );
		} else {
			$sent = 0;
			$fail = false;

			for ( $offset = 0; $offset < self::EXPORT_MAX; $offset += self::PAGE_SIZE ) {
				$events   = $this->ledger->opted_in( self::PAGE_SIZE, $offset );
				$contacts = array();

				foreach ( $events as $event ) {
					$contacts[] = array(
						'phone'      => (string) $event->phone,
						'first_name' => $this->name_for( $event, 'first' ),
						'last_name'  => $this->name_for( $event, 'last' ),
						'agreed_at'  => $event->created_at,
					);
				}

				$done = array() === $contacts ? 0 : $this->sync->sync( $contacts );

				if ( $done < 0 ) {
					$fail = true;
					break;
				}

				$sent += $done;

				if ( count( $events ) < self::PAGE_SIZE ) {
					break;
				}
			}

			if ( $fail ) {
				Flash::add( 'error', __( 'Povikvane did not accept the customers. Try again in a moment.', 'povikvane-for-woocommerce' ) );
			} else {
				Flash::add(
					'success',
					sprintf(
						/* translators: 1: number of customers, 2: group name. */
						_n( '%1$d customer was sent to Povikvane, into the group "%2$s".', '%1$d customers were sent to Povikvane, into the group "%2$s".', $sent, 'povikvane-for-woocommerce' ),
						$sent,
						ContactSync::GROUP_NAME
					)
				);
			}
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . SettingsPage::SLUG . '&tab=promotions' ) );
		exit;
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
