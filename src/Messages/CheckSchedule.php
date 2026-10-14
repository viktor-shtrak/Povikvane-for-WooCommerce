<?php
/**
 * When to check a message's delivery status.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Messages;

defined( 'ABSPATH' ) || exit;

/**
 * Back-off for delivery checks: 5 minutes after acceptance, then 30 minutes,
 * 2 hours, 6 hours and 24 hours. After that the plugin stops asking.
 */
final class CheckSchedule {

	private const DELAYS = array( 300, 1800, 7200, 21600, 86400 );

	/**
	 * Seconds until the next check, given how many have been made.
	 *
	 * @param int $checks_done Checks made so far.
	 * @return int|null Delay in seconds, or null to stop checking.
	 */
	public static function delay_after( int $checks_done ): ?int {
		return self::DELAYS[ $checks_done ] ?? null;
	}
}
