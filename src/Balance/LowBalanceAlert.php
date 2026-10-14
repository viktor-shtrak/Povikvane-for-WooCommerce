<?php
/**
 * Low-balance alert rules.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Balance;

use Povikvane\WooCommerce\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Emails the administrator when credits drop below the threshold, at most once a
 * day, and starts over once the balance recovers.
 */
final class LowBalanceAlert {

	/**
	 * Constructor.
	 *
	 * @param BalanceStore     $store     Balance store.
	 * @param LowBalanceMailer $mailer   Sends the email.
	 * @param Clock            $clock     Time source.
	 * @param int              $threshold Credits below which to warn (0 = off).
	 * @param int              $interval  Minimum seconds between emails.
	 */
	public function __construct(
		private readonly BalanceStore $store,
		private readonly LowBalanceMailer $mailer,
		private readonly Clock $clock,
		private readonly int $threshold,
		private readonly int $interval = 86400
	) {}

	/**
	 * Evaluate a fresh balance.
	 *
	 * @param Balance $balance Balance just reported by the service.
	 */
	public function check( Balance $balance ): void {
		if ( $this->threshold <= 0 ) {
			return;
		}

		if ( $balance->available >= $this->threshold ) {
			$this->store->clear_alert();
			return;
		}

		$last = $this->store->alert_sent_at();
		$now  = $this->clock->now();

		if ( null !== $last && $now - $last < $this->interval ) {
			return;
		}

		$this->mailer->send( $balance->available, $this->threshold );
		$this->store->mark_alert_sent( $now );
	}
}
