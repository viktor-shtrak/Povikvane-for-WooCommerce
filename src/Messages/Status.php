<?php
/**
 * Message lifecycle status.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Messages;

defined( 'ABSPATH' ) || exit;

/**
 * Where a message is in its life. `Unconfirmed` means we cannot tell whether the
 * message reached the handset (never treat it as failed, never resend automatically).
 */
enum Status: string {
	case Pending     = 'pending';
	case Sending     = 'sending';
	case Queued      = 'queued';
	case Delivered   = 'delivered';
	case Failed      = 'failed';
	case Unconfirmed = 'unconfirmed';
	case Skipped     = 'skipped';
	case HeldCredits = 'held_credits';
	case Expired     = 'expired';

	/**
	 * Whether no further automatic processing will happen.
	 */
	public function is_final(): bool {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- False positive: this is an enum method.
		return match ( $this ) {
			self::Delivered, self::Failed, self::Skipped, self::Expired => true,
			default => false,
		};
	}
}
