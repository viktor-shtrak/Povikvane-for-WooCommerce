<?php
/**
 * Sends logged messages and applies the response matrix.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Messages;

use Povikvane\WooCommerce\Api\ApiResult;
use Povikvane\WooCommerce\Api\Gateway;
use Povikvane\WooCommerce\Api\Outcome;
use Povikvane\WooCommerce\Api\SendRequest;
use Povikvane\WooCommerce\Log\Message;
use Povikvane\WooCommerce\Log\MessageStore;
use Povikvane\WooCommerce\Support\Clock;
use Povikvane\WooCommerce\Support\Logger;
use Povikvane\WooCommerce\Support\NullLogger;
use Povikvane\WooCommerce\Support\Notifier;
use Povikvane\WooCommerce\Support\Scheduler;
use Povikvane\WooCommerce\Support\SendingState;

defined( 'ABSPATH' ) || exit;

/**
 * Handles one send job. The message's reference is both the API reference and
 * the idempotency key, so every retry of a message is safe to repeat.
 *
 * A 504 is never retried: the API has already charged and recorded the message,
 * and replays the same answer for the same key.
 */
final class Dispatcher {

	public const MAX_ATTEMPTS = 5;

	/**
	 * Seconds to wait after failed attempt 1, 2, 3, 4.
	 */
	private const BACKOFF = array( 30, 120, 600, 3600 );

	private const AUTH_RECHECK_SECONDS    = 900;
	private const CREDITS_RECHECK_SECONDS = 600;
	private const DEFAULT_RATE_LIMIT_WAIT = 60;

	/**
	 * Constructor.
	 *
	 * @param MessageStore $store           Message log.
	 * @param Gateway      $gateway         API gateway.
	 * @param Scheduler    $scheduler       Job scheduler.
	 * @param Clock        $clock           Time source.
	 * @param Notifier     $notifier        Order note writer.
	 * @param SendingState $state           Global pause switch.
	 * @param int          $max_age_seconds Messages older than this are dropped, not sent late.
	 * @param Logger       $logger          Diagnostic logger.
	 */
	public function __construct(
		private readonly MessageStore $store,
		private readonly Gateway $gateway,
		private readonly Scheduler $scheduler,
		private readonly Clock $clock,
		private readonly Notifier $notifier,
		private readonly SendingState $state,
		private readonly int $max_age_seconds = 86400,
		private readonly Logger $logger = new NullLogger()
	) {}

	/**
	 * Process one message.
	 *
	 * @param int $message_id Row ID.
	 */
	public function handle( int $message_id ): void {
		$message = $this->store->find( $message_id );

		if ( null === $message || ! in_array( $message->status, array( Status::Pending, Status::HeldCredits ), true ) ) {
			return;
		}

		$now = $this->clock->now();

		if ( $now - $message->created_at > $this->max_age_seconds ) {
			$this->finish( $message, Status::Expired, $now );
			$this->notifier->note( $message, Notifier::NOTE_EXPIRED );
			$this->logger->log( Logger::WARNING, 'Message ' . $message->id . ' expired before it could be sent', array( 'reference' => $message->reference ) );
			return;
		}

		$paused = $this->state->paused_reason();
		if ( null !== $paused ) {
			$this->defer_while_paused( $message, $paused, $now );
			$this->logger->log( Logger::INFO, 'Message ' . $message->id . ' waiting: sending is paused', array( 'reason' => $paused ) );
			return;
		}

		$message->status     = Status::Sending;
		$message->updated_at = $now;
		++$message->attempts;
		$this->store->save( $message );

		$result = $this->gateway->send(
			new SendRequest( $message->recipient, $message->body, $message->channel, $message->reference, $message->reference )
		);

		$this->apply( $message, $result, $now );

		$this->logger->log(
			in_array( $message->status, array( Status::Failed, Status::Unconfirmed ), true ) ? Logger::WARNING : Logger::INFO,
			'Message ' . $message->id . ' -> ' . $message->status->value,
			array(
				'reference' => $message->reference,
				'attempt'   => $message->attempts,
				'outcome'   => $result->outcome->value,
				'http'      => $result->http_status,
				'error'     => $message->error_code,
			)
		);
	}

	/**
	 * Keep a message waiting while sending is paused.
	 *
	 * @param Message $message Message.
	 * @param string  $reason  Pause reason.
	 * @param int     $now     Current time.
	 */
	private function defer_while_paused( Message $message, string $reason, int $now ): void {
		if ( SendingState::REASON_CREDITS === $reason ) {
			$this->hold_for_credits( $message, $now );
			return;
		}

		$this->reschedule( $message, self::AUTH_RECHECK_SECONDS, $now );
	}

	/**
	 * Hold a message until credits are available; note it only the first time.
	 *
	 * @param Message $message Message.
	 * @param int     $now     Current time.
	 */
	private function hold_for_credits( Message $message, int $now ): void {
		$first = Status::HeldCredits !== $message->status;

		$message->status = Status::HeldCredits;
		$this->reschedule( $message, self::CREDITS_RECHECK_SECONDS, $now );

		if ( $first ) {
			$this->notifier->note( $message, Notifier::NOTE_HELD_CREDITS );
		}
	}

	/**
	 * Apply an API result to the message.
	 *
	 * @param Message   $message Message that was sent.
	 * @param ApiResult $result  API result.
	 * @param int       $now     Current time.
	 */
	private function apply( Message $message, ApiResult $result, int $now ): void {
		switch ( $result->outcome ) {
			case Outcome::Success:
				$attributes              = $result->attributes();
				$message->api_message_id = $result->message_id;
				$message->segments       = isset( $attributes['segments'] ) ? (float) $attributes['segments'] : null;
				$message->credits        = isset( $attributes['credits-charged'] ) ? (float) $attributes['credits-charged'] : null;
				$message->error_code     = null;
				$message->error_detail   = null;
				$this->finish( $message, Status::Queued, $now );
				$this->notifier->note(
					$message,
					Notifier::NOTE_QUEUED,
					array(
						'credits'  => $message->credits,
						'segments' => $message->segments,
					)
				);
				return;

			case Outcome::AuthFailure:
				$this->state->pause( SendingState::REASON_AUTH );
				$this->uncount_attempt( $message );
				$this->reschedule( $message, self::AUTH_RECHECK_SECONDS, $now );
				return;

			case Outcome::InsufficientCredits:
				$this->state->pause( SendingState::REASON_CREDITS );
				$this->uncount_attempt( $message );
				$message->status = Status::Sending; // So the hold below counts as the first note.
				$this->hold_for_credits( $message, $now );
				return;

			case Outcome::RateLimited:
				$this->uncount_attempt( $message );
				$this->reschedule( $message, max( 1, $result->retry_after ?? self::DEFAULT_RATE_LIMIT_WAIT ), $now );
				return;

			case Outcome::InFlight:
				$this->uncount_attempt( $message );
				$this->reschedule( $message, max( 1, $result->retry_after ?? 1 ), $now );
				return;

			case Outcome::Retryable:
				$this->retry_or_give_up( $message, $result, $now );
				return;

			case Outcome::Uncertain:
				$message->api_message_id = $result->message_id;
				$message->error_code     = 'http_504';
				$message->error_detail   = $result->detail;
				$this->finish( $message, Status::Unconfirmed, $now );
				$this->notifier->note( $message, Notifier::NOTE_UNCONFIRMED, array( 'message_id' => $result->message_id ) );
				return;

			case Outcome::IdempotencyMismatch:
				$this->fail( $message, 'idempotency_mismatch', $result->detail, $now );
				return;

			case Outcome::NotFound:
			case Outcome::PermanentFailure:
			default:
				$this->fail( $message, 'http_' . $result->http_status, $result->detail, $now );
		}
	}

	/**
	 * Retry with backoff, or stop after too many attempts.
	 *
	 * A 502 means the carrier explicitly rejected the message and the API refunded
	 * it, so giving up is a plain failure. Any other transient error may have
	 * reached the API, so giving up leaves the message unconfirmed.
	 *
	 * @param Message   $message Message.
	 * @param ApiResult $result  API result.
	 * @param int       $now     Current time.
	 */
	private function retry_or_give_up( Message $message, ApiResult $result, int $now ): void {
		if ( $message->attempts < self::MAX_ATTEMPTS ) {
			$delay = self::BACKOFF[ min( $message->attempts, count( self::BACKOFF ) ) - 1 ];
			$this->reschedule( $message, $delay, $now );
			return;
		}

		if ( 502 === $result->http_status ) {
			$this->fail( $message, 'http_502', $result->detail, $now );
			return;
		}

		$message->error_code   = 0 === $result->http_status ? 'network' : 'http_' . $result->http_status;
		$message->error_detail = $result->detail;
		$this->finish( $message, Status::Unconfirmed, $now );
		$this->notifier->note( $message, Notifier::NOTE_UNCONFIRMED, array( 'message_id' => null ) );
	}

	/**
	 * Mark a message failed.
	 *
	 * @param Message     $message Message.
	 * @param string      $code    Error code.
	 * @param string|null $detail  Error detail.
	 * @param int         $now     Current time.
	 */
	private function fail( Message $message, string $code, ?string $detail, int $now ): void {
		$message->error_code   = $code;
		$message->error_detail = $detail;
		$this->finish( $message, Status::Failed, $now );
		$this->notifier->note( $message, Notifier::NOTE_FAILED, array( 'detail' => $detail ) );
	}

	/**
	 * Put a message back to pending and schedule the next run.
	 *
	 * @param Message $message Message.
	 * @param int     $delay   Seconds until the next run.
	 * @param int     $now     Current time.
	 */
	private function reschedule( Message $message, int $delay, int $now ): void {
		if ( Status::HeldCredits !== $message->status ) {
			$message->status = Status::Pending;
		}

		$message->next_attempt_at = $now + $delay;
		$message->updated_at      = $now;
		$this->store->save( $message );
		$this->scheduler->schedule_send( (int) $message->id, $delay );
	}

	/**
	 * Move a message to a status and save it.
	 *
	 * @param Message $message Message.
	 * @param Status  $status  New status.
	 * @param int     $now     Current time.
	 */
	private function finish( Message $message, Status $status, int $now ): void {
		$message->status          = $status;
		$message->next_attempt_at = null;
		$message->updated_at      = $now;
		$message->final_at        = $status->is_final() ? $now : null;
		$this->store->save( $message );
	}

	/**
	 * An attempt that was refused before processing does not use up a retry.
	 *
	 * @param Message $message Message.
	 */
	private function uncount_attempt( Message $message ): void {
		$message->attempts = max( 0, $message->attempts - 1 );
	}
}
