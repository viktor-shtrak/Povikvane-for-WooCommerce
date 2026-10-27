<?php
/**
 * The "Connect with Povikvane" button and what happens around it.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Admin;

use Povikvane\WooCommerce\Connect\ConnectFlow;
use Povikvane\WooCommerce\Connect\ConnectionStore;
use Povikvane\WooCommerce\Connect\ConnectResult;
use Povikvane\WooCommerce\Setup\WizardSteps;
use Povikvane\WooCommerce\Support\Settings;
use Povikvane\WooCommerce\Support\SendingState;
use Povikvane\WooCommerce\Webhook\Token;
use Povikvane\WooCommerce\Webhook\WebhookRegistration;

defined( 'ABSPATH' ) || exit;

/**
 * Shown at the top of the connection settings and in the setup wizard. The key typed in
 * by hand keeps working; this is the shorter way.
 */
final class ConnectPanel {

	public const START      = 'povikvane_connect_start';
	public const CALLBACK   = 'povikvane_connect_callback';
	public const DISCONNECT = 'povikvane_connect_disconnect';

	private const CAPABILITY = 'manage_woocommerce';

	/**
	 * Called after a successful connection to refresh the account answer.
	 *
	 * @var callable
	 */
	private $probe;

	/**
	 * Builds the webhook helper once the new credentials are stored.
	 *
	 * @var callable
	 */
	private $registration;

	/**
	 * Constructor.
	 *
	 * @param ConnectFlow     $flow         The connection flow.
	 * @param ConnectionStore $store        What is known about the connection.
	 * @param Settings        $settings     Settings (for the key source).
	 * @param Token           $token        This site's webhook address.
	 * @param SendingState    $state        Sending state, resumed after connecting.
	 * @param callable        $probe        Runs a connection check.
	 * @param callable        $registration Returns a WebhookRegistration built with the credentials in force at that moment.
	 */
	public function __construct(
		private readonly ConnectFlow $flow,
		private readonly ConnectionStore $store,
		private readonly Settings $settings,
		private readonly Token $token,
		private readonly SendingState $state,
		callable $probe,
		callable $registration
	) {
		$this->probe        = $probe;
		$this->registration = $registration;
	}

	/**
	 * Register the handlers.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::START, array( $this, 'handle_start' ) );
		add_action( 'admin_post_' . self::CALLBACK, array( $this, 'handle_callback' ) );
		add_action( 'admin_post_' . self::DISCONNECT, array( $this, 'handle_disconnect' ) );
	}

	/**
	 * The address Povikvane sends the owner back to.
	 */
	private function callback_url(): string {
		return admin_url( 'admin-post.php?action=' . self::CALLBACK );
	}

	/**
	 * Print the block.
	 *
	 * @param string $origin "settings" or "wizard".
	 */
	public function render( string $origin ): void {
		if ( Settings::KEY_SOURCE_CONSTANT === $this->settings->api_key_source() ) {
			return;
		}

		$info    = $this->store->info();
		$revoked = $this->store->revoked();

		echo '<div class="pvk-connect">';

		if ( $revoked ) {
			Ui::note( __( 'Disconnected. This shop was disconnected in Povikvane, so no messages are sent. Connect again to continue.', 'povikvane-for-woocommerce' ), 'bad' );
		} elseif ( null !== $info && Settings::KEY_SOURCE_STORED === $this->settings->api_key_source() ) {
			Ui::note(
				'' !== $info['company']
					? sprintf(
						/* translators: 1: shop name, 2: company name in Povikvane. */
						__( 'Connected as "%1$s" to %2$s in Povikvane.', 'povikvane-for-woocommerce' ),
						$info['site_name'],
						$info['company']
					)
					: sprintf(
						/* translators: %s: shop name. */
						__( 'Connected as "%s" in Povikvane.', 'povikvane-for-woocommerce' ),
						$info['site_name']
					),
				'ok'
			);
		}

		$problem = ConnectFlow::problem( site_url(), $this->callback_url() );

		if ( null !== $problem ) {
			Ui::note( self::problem_text( $problem ), 'warn' );
			echo '<p><button type="button" class="pvk-btn pvk-btn--primary" disabled>' . esc_html__( 'Connect with Povikvane', 'povikvane-for-woocommerce' ) . '</button></p>';
		} else {
			echo '<p>';
			Layout::action_button( null === $info || $revoked ? __( 'Connect with Povikvane', 'povikvane-for-woocommerce' ) : __( 'Connect again', 'povikvane-for-woocommerce' ), self::START, array( 'return' => $origin ), 'pvk-btn pvk-btn--primary' );

			if ( null !== $info && ! $revoked ) {
				echo ' ';
				Layout::action_button( __( 'Disconnect', 'povikvane-for-woocommerce' ), self::DISCONNECT, array( 'return' => $origin ), 'pvk-btn pvk-btn--link', __( 'Disconnect this shop? The key is removed from this site and no messages are sent until you connect again. Your message history stays.', 'povikvane-for-woocommerce' ) );
			}

			echo '</p>';

			if ( null === $info ) {
				echo '<p class="pvk-field__hint">' . esc_html__( 'You will log in to Povikvane and approve this shop. No key to copy.', 'povikvane-for-woocommerce' ) . '</p>';
			}
		}

		echo '</div>';
	}

	/**
	 * Why the button is off.
	 *
	 * @param string $problem A ConnectFlow::PROBLEM_* constant.
	 */
	public static function problem_text( string $problem ): string {
		return match ( $problem ) {
			ConnectFlow::PROBLEM_SITE_NOT_HTTPS => __( 'Connecting with one click needs this site to use https. Use the API key below instead, or switch the site to https.', 'povikvane-for-woocommerce' ),
			ConnectFlow::PROBLEM_HOST_MISMATCH  => __( 'Connecting with one click needs the site and admin addresses to be the same. Use the API key below instead.', 'povikvane-for-woocommerce' ),
			default                             => __( 'This site\'s address could not be used to connect. Use the API key below instead.', 'povikvane-for-woocommerce' ),
		};
	}

	/**
	 * Send the owner to Povikvane to approve this shop.
	 */
	public function handle_start(): void {
		check_admin_referer( self::START );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'povikvane-for-woocommerce' ), '', array( 'response' => 403 ) );
		}

		$origin   = isset( $_POST['return'] ) && 'wizard' === sanitize_key( wp_unslash( $_POST['return'] ) ) ? 'wizard' : 'settings';
		$site_url = site_url();
		$redirect = $this->callback_url();

		if ( null !== ConnectFlow::problem( $site_url, $redirect ) ) {
			Flash::add( 'error', __( 'This site cannot be connected with one click. Use the API key instead.', 'povikvane-for-woocommerce' ) );
			$this->back( $origin, false );
		}

		// Povikvane only calls a public https address on the same host as the shop.
		$webhook      = $this->token->url();
		$webhook_host = strtolower( (string) wp_parse_url( $webhook, PHP_URL_HOST ) );
		$site_host    = strtolower( (string) wp_parse_url( $site_url, PHP_URL_HOST ) );
		$use_webhook  = WebhookRegistration::looks_public( $webhook ) && $webhook_host === $site_host;

		$url = $this->flow->begin( get_current_user_id(), $origin, $site_url, wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $redirect, $use_webhook ? $webhook : null );

		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- The approval page of the configured Povikvane address.
		exit;
	}

	/**
	 * The owner is back from Povikvane.
	 */
	public function handle_callback(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'povikvane-for-woocommerce' ), '', array( 'response' => 403 ) );
		}

		// The one-time state is the check here: it is single use and tied to this administrator.
		$query = array();

		foreach ( array( 'code', 'state', 'error' ) as $name ) {
			if ( isset( $_GET[ $name ] ) && is_string( $_GET[ $name ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Single-use state checked in ConnectFlow::complete().
				$query[ $name ] = sanitize_text_field( wp_unslash( $_GET[ $name ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- As above.
			}
		}

		$result = $this->flow->complete( $query, get_current_user_id() );

		if ( ConnectResult::CONNECTED === $result->status ) {
			$this->state->resume();
			( $this->probe )();

			$message = sprintf(
				/* translators: %s: shop name. */
				__( 'Connected as "%s".', 'povikvane-for-woocommerce' ),
				$result->site_name
			);

			if ( $result->webhook ) {
				$test     = ( $this->registration )()->test();
				$message .= ' ' . $test['message'];
				Flash::add( 'success', $message );
			} else {
				Flash::add( 'success', $message );
			}
		} elseif ( ConnectResult::DECLINED === $result->status ) {
			Flash::add( 'error', __( 'The connection was not approved. Nothing was changed.', 'povikvane-for-woocommerce' ) );
		} else {
			Flash::add( 'error', __( 'The connection did not work. Press "Connect with Povikvane" to start again.', 'povikvane-for-woocommerce' ) );
		}

		$this->back( $result->origin, ConnectResult::CONNECTED === $result->status );
	}

	/**
	 * Forget the credentials on this site.
	 */
	public function handle_disconnect(): void {
		check_admin_referer( self::DISCONNECT );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'povikvane-for-woocommerce' ), '', array( 'response' => 403 ) );
		}

		$this->store->disconnect();
		( $this->probe )();
		Flash::add( 'success', __( 'This shop is disconnected. Connect again whenever you want to send messages.', 'povikvane-for-woocommerce' ) );

		$this->back( isset( $_POST['return'] ) && 'wizard' === sanitize_key( wp_unslash( $_POST['return'] ) ) ? 'wizard' : 'settings', false );
	}

	/**
	 * Go back to where the owner started, on a clean address.
	 *
	 * @param string $origin    "settings" or "wizard".
	 * @param bool   $connected Whether a connection was just made.
	 */
	private function back( string $origin, bool $connected ): never {
		if ( 'wizard' === $origin ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . SetupWizard::SLUG . '&step=' . ( $connected ? WizardSteps::MESSAGES : WizardSteps::CONNECT ) ) );
		} else {
			wp_safe_redirect( admin_url( 'admin.php?page=' . SettingsPage::SLUG . '&tab=settings' ) );
		}

		exit;
	}
}
