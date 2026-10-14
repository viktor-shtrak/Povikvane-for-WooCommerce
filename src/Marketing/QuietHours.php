<?php
/**
 * Hours when promotional messages may be sent.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Marketing;

use DateTimeImmutable;
use DateTimeZone;

defined( 'ABSPATH' ) || exit;

/**
 * An allowed window in the shop's time zone, for example 09:00 to 20:00.
 * Outside it, promotional messages wait for the next opening.
 */
final class QuietHours {

	/**
	 * Constructor.
	 *
	 * @param int          $open_hour  First allowed hour (0-23).
	 * @param int          $close_hour Hour sending stops (0-23); equal to the open hour means no limit.
	 * @param DateTimeZone $timezone   Shop time zone.
	 */
	public function __construct(
		private readonly int $open_hour,
		private readonly int $close_hour,
		private readonly DateTimeZone $timezone
	) {}

	/**
	 * Seconds until sending is allowed (0 when it is allowed now).
	 *
	 * @param int $now Unix time.
	 */
	public function seconds_until_open( int $now ): int {
		if ( $this->open_hour === $this->close_hour ) {
			return 0;
		}

		$local = ( new DateTimeImmutable( '@' . $now ) )->setTimezone( $this->timezone );
		$hour  = (int) $local->format( 'G' );

		if ( $this->is_open( $hour ) ) {
			return 0;
		}

		$opening = $local->setTime( $this->open_hour, 0 );

		if ( $opening <= $local ) {
			$opening = $opening->modify( '+1 day' );
		}

		return max( 1, $opening->getTimestamp() - $now );
	}

	/**
	 * Whether an hour of the day is inside the window.
	 *
	 * @param int $hour Hour (0-23).
	 */
	private function is_open( int $hour ): bool {
		return $this->open_hour < $this->close_hour
			? $hour >= $this->open_hour && $hour < $this->close_hour
			: $hour >= $this->open_hour || $hour < $this->close_hour;
	}
}
