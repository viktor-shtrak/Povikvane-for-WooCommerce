<?php
/**
 * Stores a message and schedules its send job.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Messages;

use Povikvane\WooCommerce\Log\Message;
use Povikvane\WooCommerce\Log\MessageStore;
use Povikvane\WooCommerce\Support\Clock;
use Povikvane\WooCommerce\Support\Scheduler;

defined( 'ABSPATH' ) || exit;

/**
 * The single entry point for turning a prepared message into a queued send.
 * The reference is fixed before anything is stored, and a repeated reference is
 * refused, which is what stops duplicate messages for the same order event.
 */
final class Enqueuer {

	/**
	 * Constructor.
	 *
	 * @param MessageStore $store     Message log.
	 * @param Scheduler    $scheduler Job scheduler.
	 * @param Clock        $clock     Time source.
	 */
	public function __construct(
		private readonly MessageStore $store,
		private readonly Scheduler $scheduler,
		private readonly Clock $clock
	) {}

	/**
	 * Store and schedule a message.
	 *
	 * @param Message $message       Prepared message (recipient, body, reference set).
	 * @param int     $delay_seconds Seconds to wait before the first send attempt.
	 * @return int|null Row ID, or null if the reference already exists.
	 */
	public function enqueue( Message $message, int $delay_seconds = 0 ): ?int {
		$now                 = $this->clock->now();
		$message->status     = Status::Pending;
		$message->created_at = $now;
		$message->updated_at = $now;

		// A delayed message is not overdue until the delay has passed.
		$message->next_attempt_at = $delay_seconds > 0 ? $now + $delay_seconds : null;

		$id = $this->store->create( $message );
		if ( null === $id ) {
			return null;
		}

		$this->scheduler->schedule_send( $id, max( 0, $delay_seconds ) );

		return $id;
	}

	/**
	 * Record that a message was deliberately not sent. The reference is still
	 * used up, so the same order event is never reconsidered by accident.
	 *
	 * @param Message $message Prepared message.
	 * @param string  $reason  A SkipReason constant.
	 * @return int|null Row ID, or null if the reference already exists.
	 */
	public function record_skipped( Message $message, string $reason ): ?int {
		$now                  = $this->clock->now();
		$message->status      = Status::Skipped;
		$message->skip_reason = $reason;
		$message->created_at  = $now;
		$message->updated_at  = $now;
		$message->final_at    = $now;

		return $this->store->create( $message );
	}
}
