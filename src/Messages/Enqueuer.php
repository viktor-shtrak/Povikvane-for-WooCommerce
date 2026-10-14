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
	 * @param Message $message Prepared message (recipient, body, reference set).
	 * @return int|null Row ID, or null if the reference already exists.
	 */
	public function enqueue( Message $message ): ?int {
		$now                 = $this->clock->now();
		$message->status     = Status::Pending;
		$message->created_at = $now;
		$message->updated_at = $now;

		$id = $this->store->create( $message );
		if ( null === $id ) {
			return null;
		}

		$this->scheduler->schedule_send( $id );

		return $id;
	}
}
