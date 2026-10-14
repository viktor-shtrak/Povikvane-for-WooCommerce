<?php
/**
 * Live balance refresh for the settings page.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Admin;

use Povikvane\WooCommerce\Balance\Balance;
use Povikvane\WooCommerce\Balance\BalanceStore;

defined( 'ABSPATH' ) || exit;

/**
 * Lets the settings page update the balance in the background when it is stale.
 * The service is asked at most once a minute, however often the page is opened.
 */
final class BalanceAjax {

	public const NONCE = 'povikvane_refresh_balance';

	private const MIN_AGE_SECONDS = 60;

	/**
	 * Fetches a fresh balance from the service.
	 *
	 * @var callable(): ?Balance
	 */
	private $refresh;

	/**
	 * Constructor.
	 *
	 * @param BalanceStore $balances Balance store.
	 * @param callable     $refresh  Fetches a fresh balance.
	 */
	public function __construct( private readonly BalanceStore $balances, callable $refresh ) {
		$this->refresh = $refresh;
	}

	/**
	 * Register the AJAX action.
	 */
	public function register(): void {
		add_action( 'wp_ajax_povikvane_refresh_balance', array( $this, 'handle' ) );
	}

	/**
	 * AJAX: return the balance text, refreshing it when stale.
	 */
	public function handle(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array(), 403 );
		}

		$balance = $this->balances->get();

		if ( null === $balance || $balance->estimated || time() - $balance->fetched_at >= self::MIN_AGE_SECONDS ) {
			$balance = ( $this->refresh )() ?? $balance;
		}

		wp_send_json_success( array( 'text' => BalanceView::text( $balance ) ) );
	}
}
