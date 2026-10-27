<?php
/**
 * Low-credits admin notice.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Admin;

use Povikvane\WooCommerce\Balance\BalanceStore;
use Povikvane\WooCommerce\Support\SendingState;
use Povikvane\WooCommerce\Support\Dismissal;
use Povikvane\WooCommerce\Support\Settings;

use const Povikvane\WooCommerce\PLUGIN_FILE;
use const Povikvane\WooCommerce\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Warns on admin pages when credits are below the configured level. Stays quiet
 * when sending is already paused for lack of credits, which has its own notice.
 */
final class BalanceNotice {

	/**
	 * Constructor.
	 *
	 * @param BalanceStore $balances Balance store.
	 * @param Settings     $settings Settings.
	 * @param SendingState $state    Pause switch.
	 */
	public function __construct(
		private readonly BalanceStore $balances,
		private readonly Settings $settings,
		private readonly SendingState $state
	) {}

	public const ACTION   = 'povikvane_dismiss_balance';
	public const NONCE    = 'povikvane_dismiss_balance';
	public const USERMETA = 'povikvane_balance_notice_dismissed';

	/**
	 * Register the hooks.
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'handle_dismiss' ) );
	}

	/**
	 * Remember that this user dismissed the warning.
	 */
	public function handle_dismiss(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array(), 403 );
		}

		update_user_meta( get_current_user_id(), self::USERMETA, time() );
		wp_send_json_success();
	}

	/**
	 * Show the notice when credits are low.
	 */
	public function render(): void {
		// Setup has its own flow; a credits reminder does not belong on its first screen.
		if ( SetupWizard::is_current_page() ) {
			return;
		}

		$threshold = $this->settings->low_balance_threshold();
		$balance   = $this->balances->get();

		if ( $threshold <= 0 || null === $balance || $balance->available >= $threshold ) {
			return;
		}

		if ( SendingState::REASON_CREDITS === $this->state->paused_reason() || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		if ( Dismissal::hides( get_user_meta( get_current_user_id(), self::USERMETA, true ), time() ) ) {
			return;
		}

		wp_enqueue_script( 'povikvane-notice', plugins_url( 'assets/js/notice.js', PLUGIN_FILE ), array(), VERSION, true );

		printf(
			'<div class="notice notice-warning is-dismissible povikvane-dismissible" data-action="%5$s" data-nonce="%6$s"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
			esc_html__( 'Povikvane', 'povikvane-for-woocommerce' ),
			esc_html(
				sprintf(
					/* translators: %s: credits left. */
					__( 'Only %s credits are left. Top up soon so customer messages keep going out.', 'povikvane-for-woocommerce' ),
					number_format_i18n( $balance->available, 1 )
				)
			),
			esc_url( admin_url( 'admin.php?page=' . SettingsPage::SLUG ) ),
			esc_html__( 'Open settings', 'povikvane-for-woocommerce' ),
			esc_attr( self::ACTION ),
			esc_attr( wp_create_nonce( self::NONCE ) )
		);
	}
}
