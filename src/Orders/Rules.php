<?php
/**
 * Eligibility rules for automatic messages.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Orders;

defined( 'ABSPATH' ) || exit;

/**
 * Settings that decide whether an order may receive an automatic message.
 */
final class Rules {

	/**
	 * Constructor.
	 *
	 * @param int  $max_age_days       Orders older than this get no automatic message (0 = no limit).
	 * @param bool $skip_admin_created Skip orders created by hand in the admin.
	 * @param bool $mobile_only        Skip numbers known not to be mobile (checked for Bulgaria).
	 */
	public function __construct(
		public readonly int $max_age_days = 7,
		public readonly bool $skip_admin_created = false,
		public readonly bool $mobile_only = true
	) {}
}
