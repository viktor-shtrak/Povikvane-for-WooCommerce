<?php
/**
 * Low-balance email.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Balance;

defined( 'ABSPATH' ) || exit;

/**
 * Sends the low-balance warning to the shop administrator.
 */
interface LowBalanceMailer {

	/**
	 * Send the warning.
	 *
	 * @param float $available Credits left.
	 * @param int   $threshold The threshold that was crossed.
	 */
	public function send( float $available, int $threshold ): void;
}
