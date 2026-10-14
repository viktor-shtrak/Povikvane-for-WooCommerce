<?php
/**
 * Keeps the balance current between refreshes.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Balance;

use Povikvane\WooCommerce\Support\Scheduler;

defined( 'ABSPATH' ) || exit;

/**
 * After each accepted message the stored balance is reduced locally, so the
 * screen is right straight away, and one real refresh is scheduled shortly after
 * to correct any drift. Many sends in a burst share a single refresh.
 */
final class BalanceTracker {

	/**
	 * Constructor.
	 *
	 * @param BalanceStore $store         Balance store.
	 * @param Scheduler    $scheduler     Job scheduler.
	 * @param int          $refresh_delay Seconds to wait before the corrective refresh.
	 */
	public function __construct(
		private readonly BalanceStore $store,
		private readonly Scheduler $scheduler,
		private readonly int $refresh_delay = 60
	) {}

	/**
	 * A message was accepted and charged.
	 *
	 * @param float $credits Credits charged.
	 */
	public function record_spent( float $credits ): void {
		$balance = $this->store->get();

		if ( null !== $balance && $credits > 0 ) {
			$this->store->save( $balance->spend( $credits ) );
		}

		$this->scheduler->schedule_balance_refresh( $this->refresh_delay );
	}

	/**
	 * The service refused a message because there are no credits.
	 */
	public function record_exhausted(): void {
		$balance = $this->store->get();

		if ( null !== $balance ) {
			$this->store->save( $balance->exhausted() );
		}

		$this->scheduler->schedule_balance_refresh( $this->refresh_delay );
	}
}
