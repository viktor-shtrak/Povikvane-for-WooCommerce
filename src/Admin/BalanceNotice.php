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
use Povikvane\WooCommerce\Support\Settings;

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

	/**
	 * Register the hook.
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
	}

	/**
	 * Show the notice when credits are low.
	 */
	public function render(): void {
		$threshold = $this->settings->low_balance_threshold();
		$balance   = $this->balances->get();

		if ( $threshold <= 0 || null === $balance || $balance->available >= $threshold ) {
			return;
		}

		if ( SendingState::REASON_CREDITS === $this->state->paused_reason() || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
			esc_html__( 'Povikvane SMS', 'povikvane-for-woocommerce' ),
			esc_html(
				sprintf(
					/* translators: 1: credits left, 2: warning level. */
					__( 'Only %1$s credits are left (warning level: %2$d). Top up soon so customer messages keep going out.', 'povikvane-for-woocommerce' ),
					number_format_i18n( $balance->available, 1 ),
					$threshold
				)
			),
			esc_url( admin_url( 'admin.php?page=' . SettingsPage::SLUG ) ),
			esc_html__( 'Open settings', 'povikvane-for-woocommerce' )
		);
	}
}
