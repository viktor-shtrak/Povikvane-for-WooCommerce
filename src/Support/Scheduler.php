<?php
/**
 * Background job scheduling.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Schedules send jobs. Implemented with Action Scheduler in production.
 */
interface Scheduler {

	/**
	 * Run the send job for a message after a delay.
	 *
	 * @param int $message_id    Row ID.
	 * @param int $delay_seconds Seconds from now; 0 means as soon as possible.
	 */
	public function schedule_send( int $message_id, int $delay_seconds = 0 ): void;
}
