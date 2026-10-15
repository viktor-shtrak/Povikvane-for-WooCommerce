<?php
/**
 * How long a dismissed notice stays hidden.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Support;

defined( 'ABSPATH' ) || exit;

/**
 * A dismissed warning comes back after a while, so a problem that is still there
 * is not forgotten for good.
 */
final class Dismissal {

	public const DEFAULT_DAYS = 7;

	/**
	 * Whether a notice dismissed at a time is still hidden.
	 *
	 * @param mixed $dismissed_at Stored Unix time, or anything else when never dismissed.
	 * @param int   $now          Current time.
	 * @param int   $days         Days it stays hidden.
	 */
	public static function hides( mixed $dismissed_at, int $now, int $days = self::DEFAULT_DAYS ): bool {
		$time = is_numeric( $dismissed_at ) ? (int) $dismissed_at : 0;

		return $time > 0 && $now - $time < $days * 86400;
	}
}
