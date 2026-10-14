<?php
/**
 * Recovers messages interrupted mid-send.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Messages;

use Povikvane\WooCommerce\Api\Gateway;
use Povikvane\WooCommerce\Api\Outcome;
use Povikvane\WooCommerce\Log\Message;
use Povikvane\WooCommerce\Log\MessageStore;
use Povikvane\WooCommerce\Support\Clock;
use Povikvane\WooCommerce\Support\Logger;
use Povikvane\WooCommerce\Support\NullLogger;
use Povikvane\WooCommerce\Support\Notifier;
use Povikvane\WooCommerce\Support\Scheduler;

defined( 'ABSPATH' ) || exit;

/**
 * If the server stops between sending a request and saving the answer, the
 * message stays "sending" with no ID. This finds out what really happened:
 *
 * - With lookup by reference available, ask the service. If it has the message,
 *   adopt its ID; if not, the request never arrived and it is safe to send again.
 * - Without lookup, sending again with the same idempotency key is safe for 24
 *   hours (the service returns the original result). After that it is not, so
 *   the message is left unconfirmed rather than risking a duplicate.
 */
final class StuckRecovery {

	/**
	 * The idempotency window is 24 hours; stay well inside it.
	 */
	private const SAFE_RESEND_SECONDS = 82800;

	/**
	 * Constructor.
	 *
	 * @param MessageStore $store     Message log.
	 * @param Gateway      $gateway   API gateway.
	 * @param Scheduler    $scheduler Job scheduler.
	 * @param Notifier     $notifier  Order note writer.
	 * @param Clock        $clock     Time source.
	 * @param Logger       $logger    Diagnostic logger.
	 */
	public function __construct(
		private readonly MessageStore $store,
		private readonly Gateway $gateway,
		private readonly Scheduler $scheduler,
		private readonly Notifier $notifier,
		private readonly Clock $clock,
		private readonly Logger $logger = new NullLogger()
	) {}

	/**
	 * Resolve messages stuck in the sending state.
	 *
	 * @param int $stuck_after Seconds in "sending" before a message counts as stuck.
	 * @return int Messages handled.
	 */
	public function run( int $stuck_after = 120 ): int {
		$now     = $this->clock->now();
		$handled = 0;

		foreach ( $this->store->stuck_sending( $now - $stuck_after, 20 ) as $message ) {
			$result = $this->gateway->find_by_reference( $message->reference );

			if ( Outcome::Success === $result->outcome ) {
				$found = $result->data[0] ?? null;

				if ( is_array( $found ) && isset( $found['id'] ) && is_string( $found['id'] ) ) {
					$this->adopt( $message, $found, $now );
				} else {
					$this->send_again( $message, $now );
				}

				++$handled;
				continue;
			}

			if ( Outcome::NotFound === $result->outcome ) {
				if ( $now - $message->created_at > self::SAFE_RESEND_SECONDS ) {
					$this->give_up( $message, $now );
				} else {
					$this->send_again( $message, $now );
				}

				++$handled;
			}
			// Any other outcome: try again on the next run.
		}

		return $handled;
	}

	/**
	 * The service already has the message: record its ID and let delivery tracking take over.
	 *
	 * @param Message             $message Message.
	 * @param array<string,mixed> $found   The matching resource.
	 * @param int                 $now     Current time.
	 */
	private function adopt( Message $message, array $found, int $now ): void {
		$attributes = is_array( $found['attributes'] ?? null ) ? $found['attributes'] : array();

		$message->api_message_id  = (string) $found['id'];
		$message->status          = Status::Queued;
		$message->segments        = isset( $attributes['segments'] ) ? (float) $attributes['segments'] : null;
		$message->credits         = isset( $attributes['credits-charged'] ) ? (float) $attributes['credits-charged'] : null;
		$message->checks          = 0;
		$message->next_attempt_at = $now + 60;
		$message->updated_at      = $now;
		$this->store->save( $message );

		$this->notifier->note( $message, Notifier::NOTE_QUEUED, array( 'credits' => $message->credits ) );
		$this->logger->log( Logger::WARNING, 'Recovered an interrupted send by reference', array( 'reference' => $message->reference ) );
	}

	/**
	 * The request never arrived: send again with the same reference and key.
	 *
	 * @param Message $message Message.
	 * @param int     $now     Current time.
	 */
	private function send_again( Message $message, int $now ): void {
		$message->status          = Status::Pending;
		$message->next_attempt_at = $now;
		$message->updated_at      = $now;
		$this->store->save( $message );
		$this->scheduler->schedule_send( (int) $message->id );

		$this->logger->log( Logger::WARNING, 'Re-sending an interrupted message', array( 'reference' => $message->reference ) );
	}

	/**
	 * Too old to resend safely, and no way to look it up.
	 *
	 * @param Message $message Message.
	 * @param int     $now     Current time.
	 */
	private function give_up( Message $message, int $now ): void {
		$message->status          = Status::Unconfirmed;
		$message->error_code      = 'interrupted';
		$message->error_detail    = 'The send was interrupted and cannot be checked or repeated safely.';
		$message->next_attempt_at = null;
		$message->updated_at      = $now;
		$this->store->save( $message );

		$this->notifier->note( $message, Notifier::NOTE_UNCONFIRMED, array( 'message_id' => null ) );
		$this->logger->log( Logger::WARNING, 'Interrupted message left unconfirmed', array( 'reference' => $message->reference ) );
	}
}
