<?php
/**
 * Tests for the send dispatcher and the response matrix.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Povikvane\WooCommerce\Api\ApiResult;
use Povikvane\WooCommerce\Api\Outcome;
use Povikvane\WooCommerce\Log\Message;
use Povikvane\WooCommerce\Messages\Dispatcher;
use Povikvane\WooCommerce\Messages\Status;
use Povikvane\WooCommerce\Support\Notifier;
use Povikvane\WooCommerce\Support\SendingState;
use Povikvane\WooCommerce\Tests\Support\FakeGateway;
use Povikvane\WooCommerce\Tests\Support\FixedClock;
use Povikvane\WooCommerce\Tests\Support\InMemoryState;
use Povikvane\WooCommerce\Tests\Support\InMemoryStore;
use Povikvane\WooCommerce\Tests\Support\RecordingNotifier;
use Povikvane\WooCommerce\Tests\Support\RecordingScheduler;

/**
 * Exercises every branch of the response matrix.
 */
final class DispatcherTest extends TestCase {

	private const REFERENCE = 'woo:ab12cd34:1234:processing:1';

	private InMemoryStore $store;
	private FakeGateway $gateway;
	private RecordingScheduler $scheduler;
	private FixedClock $clock;
	private RecordingNotifier $notifier;
	private InMemoryState $state;
	private Dispatcher $dispatcher;
	private int $id;

	/**
	 * Build a dispatcher around fakes with one pending message.
	 */
	protected function setUp(): void {
		$this->store     = new InMemoryStore();
		$this->gateway   = new FakeGateway();
		$this->scheduler = new RecordingScheduler();
		$this->clock     = new FixedClock();
		$this->notifier  = new RecordingNotifier();
		$this->state     = new InMemoryState();

		$this->dispatcher = new Dispatcher( $this->store, $this->gateway, $this->scheduler, $this->clock, $this->notifier, $this->state, 86400 );

		$message             = new Message();
		$message->order_id   = 1234;
		$message->event      = 'processing';
		$message->recipient  = '+359888123456';
		$message->body       = 'Your order is being processed.';
		$message->reference  = self::REFERENCE;
		$message->created_at = $this->clock->time;
		$message->updated_at = $this->clock->time;

		$this->id = (int) $this->store->create( $message );
	}

	/**
	 * Current stored message.
	 */
	private function message(): Message {
		$message = $this->store->find( $this->id );
		$this->assertNotNull( $message );

		return $message;
	}

	/**
	 * Build an API result.
	 *
	 * @param Outcome             $outcome    Outcome.
	 * @param int                 $http       HTTP status.
	 * @param array<string,mixed> $data       Response data.
	 * @param string|null         $detail     Detail.
	 * @param int|null            $retry      Retry-After.
	 * @param string|null         $message_id Message ID.
	 */
	private function api_result( Outcome $outcome, int $http, array $data = array(), ?string $detail = null, ?int $retry = null, ?string $message_id = null ): ApiResult {
		return new ApiResult( $outcome, $http, $data, $detail, $retry, null, $message_id );
	}

	/**
	 * Success.
	 */
	public function test_success_marks_message_queued(): void {
		$this->gateway->will_return(
			$this->api_result(
				Outcome::Success,
				200,
				array(
					'attributes' => array(
						'segments'        => 2,
						'credits-charged' => 2,
					),
				),
				null,
				null,
				'api-id-1'
			)
		);

		$this->dispatcher->handle( $this->id );

		$message = $this->message();
		$this->assertSame( Status::Queued, $message->status );
		$this->assertSame( 'api-id-1', $message->api_message_id );
		$this->assertSame( 2.0, $message->segments );
		$this->assertSame( 2.0, $message->credits );
		$this->assertSame( 1, $message->attempts );
		$this->assertSame( array( Notifier::NOTE_QUEUED ), $this->notifier->codes );
		$this->assertSame( array(), $this->scheduler->runs );
	}

	/**
	 * The reference doubles as the idempotency key.
	 */
	public function test_request_uses_reference_as_idempotency_key(): void {
		$this->gateway->will_return( $this->api_result( Outcome::Success, 200, array(), null, null, 'api-id-1' ) );

		$this->dispatcher->handle( $this->id );

		$sent = $this->gateway->sent[0];
		$this->assertSame( self::REFERENCE, $sent->reference );
		$this->assertSame( self::REFERENCE, $sent->idempotency_key );
		$this->assertSame( '+359888123456', $sent->to );
	}

	/**
	 * Validation failures are permanent.
	 */
	public function test_bad_request_fails_permanently(): void {
		$this->gateway->will_return( $this->api_result( Outcome::PermanentFailure, 400, array(), 'Invalid phone number format' ) );

		$this->dispatcher->handle( $this->id );

		$message = $this->message();
		$this->assertSame( Status::Failed, $message->status );
		$this->assertSame( 'http_400', $message->error_code );
		$this->assertSame( 'Invalid phone number format', $message->error_detail );
		$this->assertNotNull( $message->final_at );
		$this->assertSame( array(), $this->scheduler->runs );
	}

	/**
	 * A rejected key pauses sending and does not use up an attempt.
	 */
	public function test_auth_failure_pauses_sending(): void {
		$this->gateway->will_return( $this->api_result( Outcome::AuthFailure, 401, array(), 'Invalid API key or service ID' ) );

		$this->dispatcher->handle( $this->id );

		$message = $this->message();
		$this->assertSame( SendingState::REASON_AUTH, $this->state->paused_reason() );
		$this->assertSame( Status::Pending, $message->status );
		$this->assertSame( 0, $message->attempts );
		$this->assertSame( array( array( $this->id, 900 ) ), $this->scheduler->runs );
	}

	/**
	 * No credits: hold the message, pause, note once.
	 */
	public function test_no_credits_holds_the_message(): void {
		$this->gateway->will_return( $this->api_result( Outcome::InsufficientCredits, 402, array(), 'Insufficient credits' ) );

		$this->dispatcher->handle( $this->id );

		$message = $this->message();
		$this->assertSame( SendingState::REASON_CREDITS, $this->state->paused_reason() );
		$this->assertSame( Status::HeldCredits, $message->status );
		$this->assertSame( 0, $message->attempts );
		$this->assertSame( array( Notifier::NOTE_HELD_CREDITS ), $this->notifier->codes );
		$this->assertSame( array( array( $this->id, 600 ) ), $this->scheduler->runs );
	}

	/**
	 * While paused for credits nothing is sent and the note is not repeated.
	 */
	public function test_paused_messages_are_not_sent_or_renoted(): void {
		$this->gateway->will_return( $this->api_result( Outcome::InsufficientCredits, 402 ) );
		$this->dispatcher->handle( $this->id );

		$this->clock->time += 600;
		$this->dispatcher->handle( $this->id );
		$this->dispatcher->handle( $this->id );

		$this->assertCount( 1, $this->gateway->sent, 'no further API calls while paused' );
		$this->assertSame( array( Notifier::NOTE_HELD_CREDITS ), $this->notifier->codes );
		$this->assertSame( Status::HeldCredits, $this->message()->status );
	}

	/**
	 * Resuming sends the held message with the same reference.
	 */
	public function test_resume_sends_held_message(): void {
		$this->gateway->will_return( $this->api_result( Outcome::InsufficientCredits, 402 ) );
		$this->dispatcher->handle( $this->id );

		$this->state->resume();
		$this->clock->time += 600;
		$this->gateway->will_return( $this->api_result( Outcome::Success, 200, array(), null, null, 'api-id-2' ) );
		$this->dispatcher->handle( $this->id );

		$this->assertSame( Status::Queued, $this->message()->status );
		$this->assertSame( self::REFERENCE, $this->gateway->sent[1]->idempotency_key );
	}

	/**
	 * Stale messages are dropped, not sent late.
	 */
	public function test_stale_held_message_expires(): void {
		$this->gateway->will_return( $this->api_result( Outcome::InsufficientCredits, 402 ) );
		$this->dispatcher->handle( $this->id );

		$this->clock->time += 86401;
		$this->dispatcher->handle( $this->id );

		$message = $this->message();
		$this->assertSame( Status::Expired, $message->status );
		$this->assertNotNull( $message->final_at );
		$this->assertSame( array( Notifier::NOTE_HELD_CREDITS, Notifier::NOTE_EXPIRED ), $this->notifier->codes );
		$this->assertCount( 1, $this->gateway->sent );
	}

	/**
	 * Rate limiting waits for Retry-After without using an attempt.
	 */
	public function test_rate_limit_reschedules_with_retry_after(): void {
		$this->gateway->will_return( $this->api_result( Outcome::RateLimited, 429, array(), null, 15 ) );

		$this->dispatcher->handle( $this->id );

		$message = $this->message();
		$this->assertSame( Status::Pending, $message->status );
		$this->assertSame( 0, $message->attempts );
		$this->assertSame( array( array( $this->id, 15 ) ), $this->scheduler->runs );
	}

	/**
	 * A request still in flight is retried quickly.
	 */
	public function test_in_flight_conflict_retries_shortly(): void {
		$this->gateway->will_return( $this->api_result( Outcome::InFlight, 409, array(), null, 1 ) );

		$this->dispatcher->handle( $this->id );

		$this->assertSame( array( array( $this->id, 1 ) ), $this->scheduler->runs );
		$this->assertSame( 0, $this->message()->attempts );
	}

	/**
	 * Transient errors back off and reuse the same key.
	 */
	public function test_transient_errors_back_off_with_same_key(): void {
		foreach ( range( 1, 4 ) as $unused ) {
			$this->gateway->will_return( $this->api_result( Outcome::Retryable, 500, array(), 'Unexpected error' ) );
		}

		for ( $i = 0; $i < 4; $i++ ) {
			$this->dispatcher->handle( $this->id );
		}

		$this->assertSame( array( 30, 120, 600, 3600 ), array_column( $this->scheduler->runs, 1 ) );
		$this->assertCount( 4, $this->gateway->sent );
		foreach ( $this->gateway->sent as $sent ) {
			$this->assertSame( self::REFERENCE, $sent->idempotency_key );
		}
		$this->assertSame( Status::Pending, $this->message()->status );
	}

	/**
	 * Giving up after repeated transient errors cannot claim the message failed.
	 */
	public function test_exhausted_transient_errors_leave_message_unconfirmed(): void {
		foreach ( range( 1, 5 ) as $unused ) {
			$this->gateway->will_return( $this->api_result( Outcome::Retryable, 0, array(), 'timed out' ) );
		}

		for ( $i = 0; $i < 5; $i++ ) {
			$this->dispatcher->handle( $this->id );
		}

		$message = $this->message();
		$this->assertSame( Status::Unconfirmed, $message->status );
		$this->assertSame( 'network', $message->error_code );
		$this->assertSame( array( Notifier::NOTE_UNCONFIRMED ), $this->notifier->codes );
	}

	/**
	 * A carrier rejection (502, refunded) that keeps happening is a plain failure.
	 */
	public function test_exhausted_502_is_a_plain_failure(): void {
		foreach ( range( 1, 5 ) as $unused ) {
			$this->gateway->will_return( $this->api_result( Outcome::Retryable, 502, array(), 'Failed to send message to carrier' ) );
		}

		for ( $i = 0; $i < 5; $i++ ) {
			$this->dispatcher->handle( $this->id );
		}

		$this->assertSame( Status::Failed, $this->message()->status );
		$this->assertSame( 'http_502', $this->message()->error_code );
	}

	/**
	 * A 504 is recorded as unconfirmed with the message ID and is never retried.
	 */
	public function test_504_is_unconfirmed_and_never_retried(): void {
		$this->gateway->will_return( $this->api_result( Outcome::Uncertain, 504, array(), 'Message delivery status is uncertain.', null, 'api-id-504' ) );

		$this->dispatcher->handle( $this->id );
		$this->dispatcher->handle( $this->id );

		$message = $this->message();
		$this->assertSame( Status::Unconfirmed, $message->status );
		$this->assertSame( 'api-id-504', $message->api_message_id );
		$this->assertSame( 'http_504', $message->error_code );
		$this->assertCount( 1, $this->gateway->sent, 'must not resend after a 504' );
		$this->assertSame( array(), $this->scheduler->runs );
		$this->assertSame( array( Notifier::NOTE_UNCONFIRMED ), $this->notifier->codes );
	}

	/**
	 * Reusing a key with a different body is our bug: fail loudly, do not retry.
	 */
	public function test_idempotency_mismatch_fails_without_retry(): void {
		$this->gateway->will_return( $this->api_result( Outcome::IdempotencyMismatch, 422, array(), 'Idempotency-Key was already used with a different request body.' ) );

		$this->dispatcher->handle( $this->id );

		$this->assertSame( Status::Failed, $this->message()->status );
		$this->assertSame( 'idempotency_mismatch', $this->message()->error_code );
		$this->assertSame( array(), $this->scheduler->runs );
	}

	/**
	 * Messages that are not waiting to be sent are ignored (double job runs).
	 */
	public function test_non_pending_messages_are_ignored(): void {
		$this->gateway->will_return( $this->api_result( Outcome::Success, 200, array(), null, null, 'api-id-1' ) );
		$this->dispatcher->handle( $this->id );

		$this->dispatcher->handle( $this->id );
		$this->dispatcher->handle( 9999 );

		$this->assertCount( 1, $this->gateway->sent );
	}
}
