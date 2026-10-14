<?php
/**
 * Persistence for the balance.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Balance;

defined( 'ABSPATH' ) || exit;

/**
 * Stores the last known balance and when a low-balance alert last went out.
 */
interface BalanceStore {

	/**
	 * Last known balance, if any.
	 */
	public function get(): ?Balance;

	/**
	 * Save a balance.
	 *
	 * @param Balance $balance Balance.
	 */
	public function save( Balance $balance ): void;

	/**
	 * When a low-balance alert was last sent.
	 */
	public function alert_sent_at(): ?int;

	/**
	 * Remember that an alert was sent.
	 *
	 * @param int $time Unix time.
	 */
	public function mark_alert_sent( int $time ): void;

	/**
	 * Forget the last alert, so the next dip alerts immediately.
	 */
	public function clear_alert(): void;
}
