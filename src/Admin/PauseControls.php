<?php
/**
 * Notices and actions for when sending is paused.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Admin;

use Povikvane\WooCommerce\Log\Repository;
use Povikvane\WooCommerce\Messages\SkipReason;
use Povikvane\WooCommerce\Messages\Status;
use Povikvane\WooCommerce\Safety\SiteGuard;
use Povikvane\WooCommerce\Support\SendingState;

defined( 'ABSPATH' ) || exit;

/**
 * Shows why sending is paused on every admin page and offers the right way out:
 * fix credentials, confirm the live site, or release/discard a burst of messages.
 */
final class PauseControls {

	private const CAPABILITY = 'manage_woocommerce';

	/**
	 * Constructor.
	 *
	 * @param SendingState $state      Pause switch.
	 * @param SiteGuard    $site_guard Site address guard.
	 * @param Repository   $repository Message log.
	 */
	public function __construct(
		private readonly SendingState $state,
		private readonly SiteGuard $site_guard,
		private readonly Repository $repository
	) {}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
		add_action( 'admin_post_povikvane_resume', array( $this, 'handle_resume' ) );
		add_action( 'admin_post_povikvane_confirm_site', array( $this, 'handle_confirm_site' ) );
		add_action( 'admin_post_povikvane_discard_pending', array( $this, 'handle_discard_pending' ) );
	}

	/**
	 * Show the notice for the current pause reason.
	 */
	public function render_notice(): void {
		$reason = $this->state->paused_reason();

		if ( null === $reason || ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Povikvane', 'povikvane-for-woocommerce' ) . '</strong> ' . esc_html( $this->text( $reason ) ) . '</p><p>';

		switch ( $reason ) {
			case SendingState::REASON_SITE:
				$this->button( 'povikvane_confirm_site', __( 'This is the live site', 'povikvane-for-woocommerce' ), true );
				break;

			case SendingState::REASON_STORM:
				$this->button( 'povikvane_resume', __( 'Send these messages', 'povikvane-for-woocommerce' ), true );
				$this->button( 'povikvane_discard_pending', __( 'Discard waiting messages', 'povikvane-for-woocommerce' ), false );
				break;

			default:
				printf( '<a class="button" href="%1$s">%2$s</a>', esc_url( admin_url( 'admin.php?page=' . SettingsPage::SLUG ) ), esc_html__( 'Open settings', 'povikvane-for-woocommerce' ) );
		}

		echo '</p></div>';
	}

	/**
	 * Resume sending.
	 */
	public function handle_resume(): void {
		check_admin_referer( 'povikvane_resume' );
		$this->require_capability();

		$this->state->resume();
		Flash::add( 'success', __( 'Sending resumed.', 'povikvane-for-woocommerce' ) );
		$this->back();
	}

	/**
	 * The site is the real one: accept its address.
	 */
	public function handle_confirm_site(): void {
		check_admin_referer( 'povikvane_confirm_site' );
		$this->require_capability();

		$this->site_guard->confirm_live();
		Flash::add( 'success', __( 'Thanks. This site is now confirmed and sending is active.', 'povikvane-for-woocommerce' ) );
		$this->back();
	}

	/**
	 * Discard every waiting message and resume.
	 */
	public function handle_discard_pending(): void {
		check_admin_referer( 'povikvane_discard_pending' );
		$this->require_capability();

		$count = $this->repository->discard_pending( SkipReason::DISCARDED, time() );
		$this->state->resume();

		Flash::add(
			'success',
			sprintf(
				/* translators: %d: number of messages. */
				_n( '%d waiting message was discarded. Sending resumed.', '%d waiting messages were discarded. Sending resumed.', $count, 'povikvane-for-woocommerce' ),
				$count
			)
		);
		$this->back();
	}

	/**
	 * Explanation for a pause reason.
	 *
	 * @param string $reason Pause reason.
	 */
	private function text( string $reason ): string {
		switch ( $reason ) {
			case SendingState::REASON_CREDITS:
				return __( 'The account has no credits, so messages are on hold. Top up your credits; held messages are sent automatically once credits are available, unless they become too old.', 'povikvane-for-woocommerce' );

			case SendingState::REASON_SITE:
				return __( 'This site\'s address is different from the one the plugin was set up on (for example a staging copy of the shop). Sending is off so customers are not messaged by mistake, and orders are skipped. If this is the real shop, confirm it below.', 'povikvane-for-woocommerce' );

			case SendingState::REASON_STORM:
				$counts  = $this->repository->counts_by_status();
				$waiting = ( $counts[ Status::Pending->value ] ?? 0 ) + ( $counts[ Status::HeldCredits->value ] ?? 0 );

				return sprintf(
					/* translators: %d: number of waiting messages. */
					_n( 'Many messages were created at once, so sending was paused to protect against accidental mass messaging. %d message is waiting. Review the list under WooCommerce > Povikvane - Viber & SMS, then send or discard it.', 'Many messages were created at once, so sending was paused to protect against accidental mass messaging. %d messages are waiting. Review the list under WooCommerce > Povikvane - Viber & SMS, then send or discard them.', $waiting, 'povikvane-for-woocommerce' ),
					$waiting
				);

			default:
				return __( 'The API key or service ID was rejected, so no messages are being sent. Check the connection settings.', 'povikvane-for-woocommerce' );
		}
	}

	/**
	 * A one-button POST form.
	 *
	 * @param string $action  Admin-post action and nonce action.
	 * @param string $label   Button label.
	 * @param bool   $primary Whether it is the main action.
	 */
	private function button( string $action, string $label, bool $primary ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:8px">';
		printf( '<input type="hidden" name="action" value="%s">', esc_attr( $action ) );
		wp_nonce_field( $action );
		submit_button( $label, $primary ? 'primary' : 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * Stop unless the user may manage the shop.
	 */
	private function require_capability(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'povikvane-for-woocommerce' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Return to the page the user came from.
	 */
	private function back(): never {
		wp_safe_redirect( wp_get_referer() ? (string) wp_get_referer() : admin_url( 'admin.php?page=' . SettingsPage::SLUG ) );
		exit;
	}
}
