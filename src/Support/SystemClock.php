<?php
/**
 * Real clock.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Uses the system time.
 */
final class SystemClock implements Clock {

	/**
	 * {@inheritDoc}
	 */
	public function now(): int {
		return time();
	}
}
