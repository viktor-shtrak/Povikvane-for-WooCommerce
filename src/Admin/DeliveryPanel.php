<?php
/**
 * Delivery tracking panel on the settings page.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Admin;

use Povikvane\WooCommerce\Support\Dates;
use Povikvane\WooCommerce\Log\Repository;
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
	 * @param Token           $token      The webhook secret.
	 * @param WebhookActivity $activity   Last webhook arrival.
	 * @param Repository      $repository Message log.
	 */
	public function __construct(
		private readonly Token $token,
		private readonly WebhookActivity $activity,
		private readonly Repository $repository
	) {}

	/**
	 * Register the action that regenerates the secret.
	 */
	public function register(): void {
		add_action( 'admin_post_povikvane_regenerate_token', array( $this, 'handle_regenerate' ) );
	}

	/**
	 * Print the panel as a card. Must be called inside the settings form.
	 */
	public function render(): void {
		Ui::card_open( __( 'Delivery reports', 'povikvane-for-woocommerce' ), __( 'To see whether messages were delivered the moment it happens, paste this address as the Webhook URL of your API key in Povikvane (Settings > API). Without it the plugin still finds out by checking regularly, only a little later.', 'povikvane-for-woocommerce' ) );

		Ui::field_open( __( 'Webhook address', 'povikvane-for-woocommerce' ), 'povikvane-webhook-url' );
		printf(
			'<div class="pvk-inline"><input type="text" id="povikvane-webhook-url" class="pvk-mono" readonly value="%1$s"> <button type="button" class="pvk-btn" data-povikvane-copy="#povikvane-webhook-url" data-copied="%2$s">%3$s</button></div>',
			esc_attr( $this->token->url() ),
			esc_attr__( 'Copied', 'povikvane-for-woocommerce' ),
			esc_html__( 'Copy', 'povikvane-for-woocommerce' )
		);
		Ui::field_close();

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

		Ui::card_close(
			esc_html__( 'Need a different address?', 'povikvane-for-woocommerce' ) . ' ' . $this->regenerate_button()
		);
	}

	/**
	 * The button that creates a new webhook address, as HTML.
	 */
	private function regenerate_button(): string {
		ob_start();
		Layout::action_button( __( 'Create a new webhook address', 'povikvane-for-woocommerce' ), 'povikvane_regenerate_token', array(), 'pvk-btn pvk-btn--link', __( 'Create a new webhook address? The current one stops working and must be updated in Povikvane.', 'povikvane-for-woocommerce' ) );

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
		Flash::add( 'success', __( 'A new webhook address was created. Update it in Povikvane.', 'povikvane-for-woocommerce' ) );

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
			WebhookHandler::RESULT_IGNORED => __( 'already known', 'povikvane-for-woocommerce' ),
			WebhookHandler::RESULT_UNKNOWN => __( 'not one of this plugin\'s messages', 'povikvane-for-woocommerce' ),
			default                        => __( 'not understood', 'povikvane-for-woocommerce' ),
		};
	}
}
