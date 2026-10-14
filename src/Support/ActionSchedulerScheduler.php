<?php
/**
 * Action Scheduler backed job scheduling.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Runs send jobs through Action Scheduler (bundled with WooCommerce), so no work
 * happens in the customer's request.
 */
final class ActionSchedulerScheduler implements Scheduler {

	public const SEND_HOOK    = 'povikvane_send_message';
	public const PROBE_HOOK   = 'povikvane_probe_capabilities';
	public const BALANCE_HOOK = 'povikvane_refresh_balance';
	public const GROUP        = 'povikvane';

	/**
	 * {@inheritDoc}
	 *
	 * @param int $message_id    Row ID.
	 * @param int $delay_seconds Seconds from now.
	 */
	public function schedule_send( int $message_id, int $delay_seconds = 0 ): void {
		if ( $delay_seconds <= 0 ) {
			as_enqueue_async_action( self::SEND_HOOK, array( $message_id ), self::GROUP );
			return;
		}

		as_schedule_single_action( time() + $delay_seconds, self::SEND_HOOK, array( $message_id ), self::GROUP );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $delay_seconds Seconds from now.
	 */
	public function schedule_balance_refresh( int $delay_seconds ): void {
		// The last argument makes the action unique: a refresh already waiting is enough.
		as_schedule_single_action( time() + max( 0, $delay_seconds ), self::BALANCE_HOOK, array(), self::GROUP, true );
	}
}
