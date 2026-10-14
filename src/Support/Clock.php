<?php
/**
 * Time source.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Injectable clock so time-dependent logic is testable.
 */
interface Clock {

	/**
	 * Current Unix timestamp.
	 */
	public function now(): int;
}
