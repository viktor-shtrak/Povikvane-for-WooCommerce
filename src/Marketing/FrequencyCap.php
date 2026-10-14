<?php
/**
 * Limits how often a number gets promotional messages.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Marketing;

use Povikvane\WooCommerce\Log\MessageStore;

defined( 'ABSPATH' ) || exit;

/**
 * At most N promotional messages per number in a period (default 1 per 7 days).
 */
final class FrequencyCap {

	/**
	 * Constructor.
	 *
	 * @param MessageStore $store Message log.
	 * @param int          $max   Messages allowed in the period (0 = no limit).
	 * @param int          $days  Length of the period in days.
	 */
	public function __construct(
		private readonly MessageStore $store,
		private readonly int $max,
		private readonly int $days
	) {}

	/**
	 * Whether the number already reached the limit.
	 *
	 * @param string $recipient Number in E.164.
	 * @param int    $now       Unix time.
	 */
	public function reached( string $recipient, int $now ): bool {
		if ( $this->max <= 0 || $this->days <= 0 ) {
			return false;
		}

		return $this->store->count_marketing_since( $recipient, $now - $this->days * 86400 ) >= $this->max;
	}
}
