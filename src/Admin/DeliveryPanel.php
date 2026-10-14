<?php
/**
 * Delivery tracking panel on the settings page.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Admin;

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
	 * Print the panel.
	 */
	public function render(): void {
		echo '<h2>' . esc_html__( 'Delivery reports', 'povikvane-for-woocommerce' ) . '</h2>';
		echo '<p>' . esc_html__( 'To see whether messages were delivered, paste this address as the Webhook URL of your API key in Povikvane (Settings > API). Without it the plugin still finds out by checking regularly, only a little later.', 'povikvane-for-woocommerce' ) . '</p>';

		printf(
			'<p><input type="text" id="povikvane-webhook-url" class="large-text code" readonly value="%1$s"> <button type="button" class="button" data-povikvane-copy="#povikvane-webhook-url" data-copied="%2$s">%3$s</button></p>',
			esc_attr( $this->token->url() ),
			esc_attr__( 'Copied', 'povikvane-for-woocommerce' ),
			esc_html__( 'Copy', 'povikvane-for-woocommerce' )
		);

		echo '<table class="widefat striped" style="max-width:640px"><tbody>';

		$last = $this->activity->last();
		$this->row(
			__( 'Last delivery report received', 'povikvane-for-woocommerce' ),
			null === $last
				? __( 'Never', 'povikvane-for-woocommerce' )
				: sprintf(
					/* translators: 1: date and time, 2: result of handling it. */
					__( '%1$s (%2$s)', 'povikvane-for-woocommerce' ),
					wp_date( 'Y-m-d H:i', $last['time'] ),
					$this->result_label( $last['result'] )
				)
		);

		$overdue = $this->repository->oldest_overdue( time() );
		$late    = null === $overdue ? 0 : time() - $overdue;

		$this->row(
			__( 'Message queue', 'povikvane-for-woocommerce' ),
			$late > self::QUEUE_WARNING_SECONDS
				? sprintf(
					/* translators: %d: minutes. */
					__( 'Running late: the oldest waiting message is %d minutes overdue. WordPress may not be running its scheduled tasks; ask your host to run WP-Cron regularly.', 'povikvane-for-woocommerce' ),
					(int) floor( $late / 60 )
				)
				: __( 'On time', 'povikvane-for-woocommerce' )
		);

		echo '</tbody></table>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:8px">';
		echo '<input type="hidden" name="action" value="povikvane_regenerate_token">';
		wp_nonce_field( 'povikvane_regenerate_token' );
		printf(
			'<button type="submit" class="button button-link-delete" data-povikvane-confirm="%1$s">%2$s</button>',
			esc_attr__( 'Create a new webhook address? The current one stops working and must be updated in Povikvane.', 'povikvane-for-woocommerce' ),
			esc_html__( 'Create a new webhook address', 'povikvane-for-woocommerce' )
		);
		echo '</form>';
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
	 * One table row.
	 *
	 * @param string $label Label.
	 * @param string $value Value.
	 */
	private function row( string $label, string $value ): void {
		printf( '<tr><th scope="row" style="width:40%%">%1$s</th><td>%2$s</td></tr>', esc_html( $label ), esc_html( $value ) );
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
