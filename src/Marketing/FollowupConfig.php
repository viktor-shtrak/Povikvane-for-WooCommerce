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

	/**
	 * Constructor.
	 *
	 * @param bool   $enabled       Whether the message is sent at all.
	 * @param int    $days          Days after the order is completed.
	 * @param string $template      Message template.
	 * @param string $optout_label  Text placed before the opt-out link when the template has none.
	 */
	public function __construct(
		public readonly bool $enabled,
		public readonly int $days,
		public readonly string $template,
		public readonly string $optout_label
	) {}
}
