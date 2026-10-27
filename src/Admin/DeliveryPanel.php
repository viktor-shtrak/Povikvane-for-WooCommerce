<?php
/**
 * Delivery tracking panel on the settings page.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Admin;

use Povikvane\WooCommerce\Account\AccountInfo;
use Povikvane\WooCommerce\Api\Capabilities;
use Povikvane\WooCommerce\Setup\WizardSteps;
use Povikvane\WooCommerce\Support\Dates;
use Povikvane\WooCommerce\Webhook\WebhookRegistration;
use Povikvane\WooCommerce\Webhook\WebhookSecret;
use Povikvane\WooCommerce\Log\Repository;
use Povikvane\WooCommerce\Webhook\SelfTest;
use Povikvane\WooCommerce\Webhook\Token;
use Povikvane\WooCommerce\Webhook\WebhookActivity;
use Povikvane\WooCommerce\Webhook\WebhookHandler;

defined( 'ABSPATH' ) || exit;

/**
 * Shows the webhook URL to paste into Povikvane, whether webhooks arrive, and
 * whether the message queue is keeping up.
 */
final class DeliveryPanel {

	private const CAPABILITY            = 'manage_woocommerce';
	private const QUEUE_WARNING_SECONDS = 600;

	/**
	 * Constructor.
	 *
	 * @param Token                    $token      The webhook secret.
	 * @param WebhookActivity          $activity   Last webhook arrival.
	 * @param Repository               $repository Message log.
	 * @param WebhookRegistration|null $registration Sets the address in Povikvane.
	 * @param callable|null            $probe      Runs a connection check, so the account answer is fresh afterwards.
	 * @param WebhookSecret|null       $secret     The stored signing secret.
	 */
	public function __construct(
		private readonly Token $token,
		private readonly WebhookActivity $activity,
		private readonly Repository $repository,
		private readonly ?WebhookRegistration $registration = null,
		private readonly mixed $probe = null,
		private readonly ?WebhookSecret $secret = null
	) {}

	/**
	 * Register the action that regenerates the secret.
	 */
	public function register(): void {
		add_action( 'admin_post_povikvane_regenerate_token', array( $this, 'handle_regenerate' ) );
		add_action( 'admin_post_povikvane_test_webhook', array( $this, 'handle_test' ) );
		add_action( 'admin_post_povikvane_register_webhook', array( $this, 'handle_register' ) );
		add_action( 'admin_post_povikvane_rotate_secret', array( $this, 'handle_rotate_secret' ) );
		add_action( 'admin_post_povikvane_remote_test_webhook', array( $this, 'handle_remote_test' ) );
	}

	/**
	 * Print the panel as a card. Must be called inside the settings form.
	 */
	public function render(): void {
		Ui::card_open( __( 'Delivery reports', 'povikvane-for-woocommerce' ), __( 'Povikvane tells this shop the moment a message is delivered. This is set up for you when you connect. Without it the plugin still finds out by checking regularly, only a little later. After a test message is delivered, "Last report received" shows that it works.', 'povikvane-for-woocommerce' ) );

		Ui::field_open( __( 'Delivery report address', 'povikvane-for-woocommerce' ), 'povikvane-webhook-url' );
		printf(
			'<div class="pvk-inline"><input type="text" id="povikvane-webhook-url" class="pvk-mono" readonly value="%1$s"> <button type="button" class="pvk-btn" data-povikvane-copy="#povikvane-webhook-url" data-copied="%2$s">%3$s</button></div>',
			esc_attr( $this->token->url() ),
			esc_attr__( 'Copied', 'povikvane-for-woocommerce' ),
			esc_html__( 'Copy', 'povikvane-for-woocommerce' )
		);
		Ui::field_close();

		$this->render_registration();

		$last = $this->activity->last();

		Ui::field_open( __( 'Last report received', 'povikvane-for-woocommerce' ) );

		if ( null === $last ) {
			echo Ui::pill( __( 'Never', 'povikvane-for-woocommerce' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by Ui::pill().
		} else {
			echo esc_html(
				sprintf(
					/* translators: 1: date and time, 2: result of handling it. */
					__( '%1$s (%2$s)', 'povikvane-for-woocommerce' ),
					Dates::format( $last['time'], 'Y-m-d H:i' ),
					$this->result_label( $last['result'] )
				)
			);
		}

		Ui::field_close();

		$overdue = $this->repository->oldest_overdue( time() );
		$late    = null === $overdue ? 0 : time() - $overdue;

		Ui::field_open( __( 'Message queue', 'povikvane-for-woocommerce' ) );

		if ( $late > self::QUEUE_WARNING_SECONDS ) {
			echo esc_html(
				sprintf(
					/* translators: %d: minutes. */
					__( 'Running late: the oldest waiting message is %d minutes overdue. WordPress may not be running its scheduled tasks; ask your host to run WP-Cron regularly.', 'povikvane-for-woocommerce' ),
					(int) floor( $late / 60 )
				)
			);
		} else {
			echo Ui::pill( __( 'On time', 'povikvane-for-woocommerce' ), 'ok' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by Ui::pill().
		}

		Ui::field_close();

		Ui::card_close( $this->registration_buttons() . $this->test_button() . ' ' . esc_html__( 'Need a different address?', 'povikvane-for-woocommerce' ) . ' ' . $this->regenerate_button() );
	}

	/**
	 * Stored capabilities.
	 *
	 * @return array<mixed>
	 */
	private function caps(): array {
		$caps = get_option( Capabilities::OPTION, array() );

		return is_array( $caps ) ? $caps : array();
	}

	/**
	 * Whether the address can be managed from here.
	 */
	private function manageable(): bool {
		return null !== $this->registration && WebhookRegistration::supported( $this->caps() );
	}

	/**
	 * Where this site's address stands in Povikvane: not set, set to this site, or set to something else.
	 *
	 * @return string "none", "ours" or "other".
	 */
	private function registration_state(): string {
		$webhook = AccountInfo::webhook( $this->caps() );

		if ( null === $webhook || null === $webhook['url'] ) {
			return 'none';
		}

		return $webhook['url'] === $this->token->url() ? 'ours' : 'other';
	}

	/**
	 * The "Registered in Povikvane" row.
	 */
	private function render_registration(): void {
		if ( ! $this->manageable() ) {
			return;
		}

		$state   = $this->registration_state();
		$webhook = AccountInfo::webhook( $this->caps() );

		Ui::field_open( __( 'In Povikvane', 'povikvane-for-woocommerce' ) );

		if ( 'ours' === $state ) {
			echo Ui::pill( __( 'Registered', 'povikvane-for-woocommerce' ), 'ok' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by Ui::pill().

			if ( null !== $webhook && null !== $webhook['last_delivery_at'] ) {
				$time = strtotime( $webhook['last_delivery_at'] );

				echo ' ' . esc_html(
					'delivered' === $webhook['last_status']
						? sprintf(
							/* translators: %s: date and time. */
							__( 'Last delivery worked (%s).', 'povikvane-for-woocommerce' ),
							false === $time ? $webhook['last_delivery_at'] : Dates::format( $time, 'Y-m-d H:i' )
						)
						: sprintf(
							/* translators: %s: reason. */
							__( 'Last delivery failed: %s', 'povikvane-for-woocommerce' ),
							WebhookRegistration::error_text( (string) $webhook['last_error'] )
						)
				);
			}
			echo '<br>' . $this->signing_line(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.

			if ( null !== $webhook && $webhook['consecutive_failures'] > 0 ) {
				echo '<br>' . esc_html(
					sprintf(
						/* translators: %d: number of failed deliveries in a row. */
						_n( 'Povikvane could not reach your site on its last %d attempt.', 'Povikvane could not reach your site on its last %d attempts.', $webhook['consecutive_failures'], 'povikvane-for-woocommerce' ),
						$webhook['consecutive_failures']
					)
				);
			}
		} elseif ( 'other' === $state ) {
			echo Ui::pill( __( 'Another address is set', 'povikvane-for-woocommerce' ), 'warn' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by Ui::pill().
			echo ' ' . esc_html__( 'This key sends its reports somewhere else. Use the button below to send them to this site instead.', 'povikvane-for-woocommerce' );
		} else {
			echo Ui::pill( __( 'Not registered', 'povikvane-for-woocommerce' ), 'warn' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by Ui::pill().
		}

		Ui::field_close();
	}

	/**
	 * Whether deliveries are checked for a signature, as HTML.
	 */
	private function signing_line(): string {
		if ( null !== $this->secret && $this->secret->exists() ) {
			return Ui::pill( __( 'Signed', 'povikvane-for-woocommerce' ), 'ok' ) . ' ' . esc_html__( 'Deliveries are checked with a secret only Povikvane and this site know.', 'povikvane-for-woocommerce' );
		}

		return Ui::pill( __( 'Not signed', 'povikvane-for-woocommerce' ), 'warn' ) . ' ' . esc_html__( 'Create a secret so that nobody else can send reports to this address.', 'povikvane-for-woocommerce' );
	}

	/**
	 * Buttons that register the address and ask Povikvane to test it, as HTML.
	 */
	private function registration_buttons(): string {
		if ( ! $this->manageable() ) {
			return '';
		}

		ob_start();

		if ( 'ours' !== $this->registration_state() ) {
			Layout::action_button( __( 'Register this address in Povikvane', 'povikvane-for-woocommerce' ), 'povikvane_register_webhook', array(), 'pvk-btn pvk-btn--primary', 'other' === $this->registration_state() ? __( 'Another address is already set for this key. Replace it with this site?', 'povikvane-for-woocommerce' ) : '' );
		} else {
			Layout::action_button( __( 'Ask Povikvane to test it', 'povikvane-for-woocommerce' ), 'povikvane_remote_test_webhook', array(), 'pvk-btn', '', __( 'Testing...', 'povikvane-for-woocommerce' ) );
			echo ' ';
			$has = null !== $this->secret && $this->secret->exists();
			Layout::action_button( $has ? __( 'Create a new secret', 'povikvane-for-woocommerce' ) : __( 'Create a secret', 'povikvane-for-woocommerce' ), 'povikvane_rotate_secret', array(), 'pvk-btn pvk-btn--link', $has ? __( 'Create a new secret? The old one keeps working for 24 hours, then only the new one is accepted.', 'povikvane-for-woocommerce' ) : '' );
		}

		return (string) ob_get_clean() . ' ';
	}

	/**
	 * Register this site's address in Povikvane.
	 */
	public function handle_register(): void {
		check_admin_referer( 'povikvane_register_webhook' );

		if ( ! current_user_can( self::CAPABILITY ) || null === $this->registration ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'povikvane-for-woocommerce' ), '', array( 'response' => 403 ) );
		}

		$outcome = $this->registration->register( $this->token->url() );

		if ( WebhookRegistration::REGISTERED === $outcome['result'] ) {
			$this->refresh();
			Flash::add( 'success', __( 'The address was registered in Povikvane.', 'povikvane-for-woocommerce' ) );
		} else {
			Flash::add( 'error', $outcome['reason'] );
		}

		$this->back();
	}

	/**
	 * Create or replace the signing secret.
	 */
	public function handle_rotate_secret(): void {
		check_admin_referer( 'povikvane_rotate_secret' );

		if ( ! current_user_can( self::CAPABILITY ) || null === $this->registration ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'povikvane-for-woocommerce' ), '', array( 'response' => 403 ) );
		}

		if ( $this->registration->rotate_secret() ) {
			$this->refresh();
			Flash::add( 'success', __( 'A new secret was created. Deliveries from Povikvane are now checked with it.', 'povikvane-for-woocommerce' ) );
		} else {
			Flash::add( 'error', __( 'Povikvane did not create a secret. Try again in a moment.', 'povikvane-for-woocommerce' ) );
		}

		$this->back();
	}

	/**
	 * Ask Povikvane to call the registered address.
	 */
	public function handle_remote_test(): void {
		check_admin_referer( 'povikvane_remote_test_webhook' );

		if ( ! current_user_can( self::CAPABILITY ) || null === $this->registration ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'povikvane-for-woocommerce' ), '', array( 'response' => 403 ) );
		}

		$outcome = $this->registration->test();

		if ( $outcome['ok'] ) {
			update_option( WebhookRegistration::VERIFIED_OPTION, time(), false );
		}

		$this->refresh();
		Flash::add( $outcome['ok'] ? 'success' : 'error', $outcome['message'] );

		$this->back();
	}

	/**
	 * Return to the settings tab, or to the setup wizard when the button was pressed there.
	 */
	private function back(): never {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The calling handler has checked the nonce.
		$wizard = isset( $_POST['return'] ) && 'wizard' === sanitize_key( wp_unslash( $_POST['return'] ) );

		wp_safe_redirect( admin_url( 'admin.php?page=' . ( $wizard ? SetupWizard::SLUG . '&step=' . WizardSteps::REPORTS : SettingsPage::SLUG . '&tab=settings' ) ) );
		exit;
	}

	/**
	 * Read the account again so the status shown is current.
	 */
	private function refresh(): void {
		if ( is_callable( $this->probe ) ) {
			( $this->probe )();
		}
	}

	/**
	 * The button that tests the webhook address from this site, as HTML.
	 */
	private function test_button(): string {
		ob_start();
		Layout::action_button( __( 'Test the address', 'povikvane-for-woocommerce' ), 'povikvane_test_webhook', array(), 'pvk-btn' );

		return (string) ob_get_clean();
	}

	/**
	 * Call the webhook address from this site and say what happened.
	 */
	public function handle_test(): void {
		check_admin_referer( 'povikvane_test_webhook' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'povikvane-for-woocommerce' ), '', array( 'response' => 403 ) );
		}

		$result = SelfTest::run( $this->token->url() );

		Flash::add( SelfTest::OK === $result ? 'success' : 'error', SelfTest::message( $result ) );

		wp_safe_redirect( admin_url( 'admin.php?page=' . SettingsPage::SLUG . '&tab=settings' ) );
		exit;
	}

	/**
	 * The button that creates a new webhook address, as HTML.
	 */
	private function regenerate_button(): string {
		ob_start();
		Layout::action_button( __( 'Create a new address', 'povikvane-for-woocommerce' ), 'povikvane_regenerate_token', array(), 'pvk-btn pvk-btn--link', __( 'Create a new address? The current one stops working and must be updated in Povikvane.', 'povikvane-for-woocommerce' ) );

		return (string) ob_get_clean();
	}

	/**
	 * Replace the webhook secret.
	 */
	public function handle_regenerate(): void {
		check_admin_referer( 'povikvane_regenerate_token' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'povikvane-for-woocommerce' ), '', array( 'response' => 403 ) );
		}

		$this->token->regenerate();
		Flash::add( 'success', __( 'A new address was created. Update it in Povikvane.', 'povikvane-for-woocommerce' ) );

		wp_safe_redirect( admin_url( 'admin.php?page=' . SettingsPage::SLUG ) );
		exit;
	}

	/**
	 * Human label for a webhook result.
	 *
	 * @param string $result A WebhookHandler result code.
	 */
	private function result_label( string $result ): string {
		return match ( $result ) {
			WebhookHandler::RESULT_UPDATED => __( 'updated a message', 'povikvane-for-woocommerce' ),
			WebhookHandler::RESULT_DUPLICATE => __( 'a repeat of a report already handled', 'povikvane-for-woocommerce' ),
			WebhookHandler::RESULT_TEMPLATE => __( 'a template changed', 'povikvane-for-woocommerce' ),
			WebhookHandler::RESULT_OPTED_OUT => __( 'a customer opted out', 'povikvane-for-woocommerce' ),
			WebhookHandler::RESULT_IGNORED => __( 'already known', 'povikvane-for-woocommerce' ),
			WebhookHandler::RESULT_UNKNOWN => __( 'not one of this plugin\'s messages', 'povikvane-for-woocommerce' ),
			default                        => __( 'not understood', 'povikvane-for-woocommerce' ),
		};
	}
}
