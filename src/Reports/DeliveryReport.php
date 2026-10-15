<?php
/**
 * Results of a period, for the Overview.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Reports;

defined( 'ABSPATH' ) || exit;

/**
 * How many messages were delivered, failed or are still on their way, what they cost
 * and how much Viber was used. Built from the grouped counts of the message log.
 */
final class DeliveryReport {

	/**
	 * Constructor.
	 *
	 * @param int   $delivered        Delivered messages.
	 * @param int   $failed           Failed messages.
	 * @param int   $unconfirmed      Messages whose delivery could not be confirmed.
	 * @param int   $in_flight        Waiting, being sent, accepted or on hold.
	 * @param int   $skipped          Messages deliberately not sent.
	 * @param int   $expired          Messages dropped for waiting too long.
	 * @param float $credits          Credits charged.
	 * @param int   $viber_sent       Messages sent over Viber (not skipped).
	 * @param int   $viber_delivered  Delivered by Viber.
	 * @param int   $fallbacks        Viber messages delivered by the SMS fallback.
	 */
	public function __construct(
		public readonly int $delivered,
		public readonly int $failed,
		public readonly int $unconfirmed,
		public readonly int $in_flight,
		public readonly int $skipped,
		public readonly int $expired,
		public readonly float $credits,
		public readonly int $viber_sent,
		public readonly int $viber_delivered,
		public readonly int $fallbacks
	) {}

	/**
	 * Build from grouped counts.
	 *
	 * @param array<int,array{status:string,channel:string,delivered_via:?string,count:int,credits:float}> $rows Rows from ReportSource::summary().
	 */
	public static function from_rows( array $rows ): self {
		$count   = array();
		$credits = 0.0;
		$viber   = 0;
		$viber_d = 0;
		$fall    = 0;

		foreach ( $rows as $row ) {
			$status           = $row['status'];
			$count[ $status ] = ( $count[ $status ] ?? 0 ) + $row['count'];

			if ( 'skipped' !== $status ) {
				$credits += $row['credits'];
			}

			if ( 'viber' === $row['channel'] && 'skipped' !== $status ) {
				$viber += $row['count'];

				if ( 'delivered' === $status ) {
					'sms' === $row['delivered_via'] ? $fall += $row['count'] : $viber_d += $row['count'];
				}
			}
		}

		return new self(
			$count['delivered'] ?? 0,
			$count['failed'] ?? 0,
			$count['unconfirmed'] ?? 0,
			( $count['pending'] ?? 0 ) + ( $count['sending'] ?? 0 ) + ( $count['queued'] ?? 0 ) + ( $count['held_credits'] ?? 0 ),
			$count['skipped'] ?? 0,
			$count['expired'] ?? 0,
			round( $credits, 2 ),
			$viber,
			$viber_d,
			$fall
		);
	}

	/**
	 * Messages with a known outcome.
	 */
	public function finished(): int {
		return $this->delivered + $this->failed + $this->unconfirmed;
	}

	/**
	 * Share of finished messages that were delivered, 0 to 100, or null when nothing finished yet.
	 */
	public function rate(): ?float {
		$finished = $this->finished();

		return 0 === $finished ? null : round( 100 * $this->delivered / $finished, 1 );
	}

	/**
	 * Messages that were sent in the period.
	 */
	public function sent(): int {
		return $this->finished() + $this->in_flight;
	}
}
