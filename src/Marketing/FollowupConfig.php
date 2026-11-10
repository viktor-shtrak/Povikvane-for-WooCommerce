<?php
/**
 * Settings of the review-request message.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Marketing;

defined( 'ABSPATH' ) || exit;

/**
 * Whether a review request follows a completed order, when, and what it says.
 */
final class FollowupConfig {

	public const DAYS      = 'days';
	public const HOURS     = 'hours';
	public const MAX_DAYS  = 60;
	public const MAX_HOURS = 72;

	/**
	 * Constructor.
	 *
	 * @param bool   $enabled       Whether the message is sent at all.
	 * @param int    $days          How long after the order is completed, counted in the unit below.
	 * @param string $template      Message template.
	 * @param string $optout_label  Text placed before the opt-out link when the template has none.
	 * @param string $unit          'days' or 'hours'.
	 */
	public function __construct(
		public readonly bool $enabled,
		public readonly int $days,
		public readonly string $template,
		public readonly string $optout_label,
		public readonly string $unit = self::DAYS
	) {}

	/**
	 * The wait between the completed order and the message, in seconds.
	 */
	public function delay(): int {
		if ( self::HOURS === $this->unit ) {
			return max( 1, min( self::MAX_HOURS, $this->days ) ) * HOUR_IN_SECONDS;
		}

		return max( 1, min( self::MAX_DAYS, $this->days ) ) * DAY_IN_SECONDS;
	}
}
