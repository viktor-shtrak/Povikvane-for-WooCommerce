<?php
/**
 * Credit balance snapshot.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Balance;

defined( 'ABSPATH' ) || exit;

/**
 * Credits as last reported by the service, or as estimated since then.
 */
final class Balance {

	/**
	 * Constructor.
	 *
	 * @param float    $available  Credits available to send.
	 * @param float    $locked     Credits reserved for scheduled campaigns.
	 * @param string[] $channels   Channels with a configured sender.
	 * @param int      $fetched_at When the service last reported this balance.
	 * @param bool     $estimated  True when adjusted locally since the last report.
	 */
	public function __construct(
		public readonly float $available,
		public readonly float $locked,
		public readonly array $channels,
		public readonly int $fetched_at,
		public readonly bool $estimated = false
	) {}

	/**
	 * Balance after spending credits locally; never below zero.
	 *
	 * @param float $credits Credits charged.
	 */
	public function spend( float $credits ): self {
		return new self( max( 0.0, round( $this->available - $credits, 2 ) ), $this->locked, $this->channels, $this->fetched_at, true );
	}

	/**
	 * Balance after the service said there are no credits left.
	 */
	public function exhausted(): self {
		return new self( 0.0, $this->locked, $this->channels, $this->fetched_at, true );
	}
}
