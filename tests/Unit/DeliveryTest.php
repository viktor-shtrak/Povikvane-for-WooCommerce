<?php
/**
 * Tests for delivery tracking: updater, webhook, reconciler and crash recovery.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Povikvane\WooCommerce\Api\ApiResult;
use Povikvane\WooCommerce\Api\Outcome;
use Povikvane\WooCommerce\Log\Message;
use Povikvane\WooCommerce\Messages\CheckSchedule;
use Povikvane\WooCommerce\Messages\DeliveryUpdater;
use Povikvane\WooCommerce\Messages\Reconciler;
use Povikvane\WooCommerce\Messages\Status;
use Povikvane\WooCommerce\Messages\StuckRecovery;
use Povikvane\WooCommerce\Support\Notifier;
use Povikvane\WooCommerce\Support\SendingState;
use Povikvane\WooCommerce\Tests\Support\FakeGateway;
use Povikvane\WooCommerce\Tests\Support\FixedClock;
use Povikvane\WooCommerce\Tests\Support\InMemoryActivity;
use Povikvane\WooCommerce\Tests\Support\InMemoryState;
use Povikvane\WooCommerce\Tests\Support\InMemoryStore;
use Povikvane\WooCommerce\Tests\Support\RecordingNotifier;
use Povikvane\WooCommerce\Tests\Support\RecordingScheduler;
use Povikvane\WooCommerce\Webhook\WebhookHandler;

/**
 * Delivery tracking behaviour.
 */
final class DeliveryTest extends TestCase {

	private const NOW = 1760000000;

	private InMemoryStore $store;
	private RecordingNotifier $notifier;
	private FixedClock $clock;
	private DeliveryUpdater $updater;

	/**
	 * Fresh doubles per test.
	 */
	protected function setUp(): void {
		$this->store    = new InMemoryStore();
		$this->notifier = new RecordingNotifier();
		$this->clock    = new FixedClock( self::NOW );
		$this->updater  = new DeliveryUpdater( $this->store, $this->notifier, $this->clock );
	}

	/**
	 * Store a message.
	 *
	 * @param Status      $status Status.
	 * @param string|null $api_id API message ID.
	 * @param int|null    $next   Next check time.
	 * @param int         $order  Order ID.
	 */
	private function add( Status $status, ?string $api_id = 'api-1', ?int $next = null, int $order = 1 ): int {
		$message                  = new Message();
		$message->order_id        = $order;
		$message->event           = 'completed';
		$message->recipient       = '+359888123456';
		$message->body            = 'Hi';
		$message->reference       = 'woo:ab12cd34:' . $order . ':completed:1';
		$message->status          = $status;
		$message->api_message_id  = $api_id;
		$message->next_attempt_at = $next;
		$message->created_at      = self::NOW - 600;
		$message->updated_at      = self::NOW - 600;

		return (int) $this->store->create( $message );
	}

	/**
	 * Stored message.
	 *
	 * @param int $id ID.
	 */
	private function get( int $id ): Message {
		$message = $this->store->find( $id );
		$this->assertNotNull( $message );

		return $message;
	}

	/**
	 * Status response.
	 *
	 * @param string      $status Status string.
	 * @param string|null $detail Status detail.
	 */
	private function status_reply( string $status, ?string $detail = null ): ApiResult {
		$attributes = array( 'status' => $status );
		if ( null !== $detail ) {
			$attributes['status_detail'] = $detail;
		}

		return new ApiResult( Outcome::Success, 200, array( 'attributes' => $attributes ) );
	}

	// ---- DeliveryUpdater ----

	/**
	 * Delivered.
	 */
	public function test_delivered_is_final_and_noted(): void {
		$id = $this->add( Status::Queued, 'api-1', self::NOW + 300 );

		$this->assertTrue( $this->updater->delivered( $this->get( $id ) ) );

		$message = $this->get( $id );
		$this->assertSame( Status::Delivered, $message->status );
		$this->assertSame( self::NOW, $message->final_at );
		$this->assertNull( $message->next_attempt_at );
		$this->assertSame( array( Notifier::NOTE_DELIVERED ), $this->notifier->codes );
	}

	/**
	 * Failed keeps the reason.
	 */
	public function test_failed_records_the_reason(): void {
		$id = $this->add( Status::Queued );

		$this->assertTrue( $this->updater->failed( $this->get( $id ), 'Number not reachable' ) );

		$message = $this->get( $id );
		$this->assertSame( Status::Failed, $message->status );
		$this->assertSame( 'delivery_failed', $message->error_code );
		$this->assertSame( 'Number not reachable', $message->error_detail );
	}

	/**
	 * A result can never be undone by a late or repeated report.
	 */
	public function test_final_results_never_change(): void {
		$id = $this->add( Status::Queued );
		$this->updater->delivered( $this->get( $id ) );

		$this->assertFalse( $this->updater->failed( $this->get( $id ), 'late' ) );
		$this->assertFalse( $this->updater->delivered( $this->get( $id ) ) );
		$this->assertFalse( $this->updater->unconfirmed( $this->get( $id ), 'x', null ) );
		$this->assertSame( Status::Delivered, $this->get( $id )->status );
		$this->assertSame( array( Notifier::NOTE_DELIVERED ), $this->notifier->codes, 'no duplicate note' );
	}

	/**
	 * An unconfirmed message can still be resolved later.
	 */
	public function test_unconfirmed_can_become_delivered(): void {
		$id = $this->add( Status::Unconfirmed );

		$this->assertTrue( $this->updater->delivered( $this->get( $id ) ) );
		$this->assertSame( Status::Delivered, $this->get( $id )->status );
	}

	/**
	 * Unconfirmed only applies to accepted messages.
	 */
	public function test_unconfirmed_applies_only_to_accepted_messages(): void {
		$queued = $this->add( Status::Queued );
		$failed = $this->add( Status::Failed, 'api-2', null, 2 );

		$this->assertTrue( $this->updater->unconfirmed( $this->get( $queued ), 'unknown', 'x' ) );
		$this->assertFalse( $this->updater->unconfirmed( $this->get( $failed ), 'unknown', 'x' ) );
	}

	// ---- Webhook ----

	/**
	 * Build a webhook handler.
	 *
	 * @param InMemoryActivity $activity Activity log.
	 */
	private function webhook( InMemoryActivity $activity ): WebhookHandler {
		return new WebhookHandler( $this->store, $this->updater, $activity, $this->clock );
	}

	/**
	 * Build a webhook body.
	 *
	 * @param array<string,mixed> $data Data overrides.
	 * @param string              $event Event name.
	 * @return array<string,mixed>
	 */
	private function payload( array $data = array(), string $event = 'message.status_updated' ): array {
		return array(
			'event' => $event,
			'data'  => array_merge(
				array(
					'id'        => 'api-1',
					'status'    => 'delivered',
					'channel'   => 'sms',
					'recipient' => '+359888123456',
					'timestamp' => '2026-10-14T10:00:00.000Z',
					'error'     => null,
				),
				$data
			),
		);
	}

	/**
	 * A delivered event finishes the message.
	 */
	public function test_webhook_marks_delivered(): void {
		$id       = $this->add( Status::Queued );
		$activity = new InMemoryActivity();

		$result = $this->webhook( $activity )->handle( $this->payload() );

		$this->assertSame( WebhookHandler::RESULT_UPDATED, $result );
		$this->assertSame( Status::Delivered, $this->get( $id )->status );
		$this->assertSame( array( array( self::NOW, WebhookHandler::RESULT_UPDATED ) ), $activity->records );
	}

	/**
	 * A failed event keeps the error text.
	 */
	public function test_webhook_marks_failed_with_error(): void {
		$id = $this->add( Status::Queued );

		$this->webhook( new InMemoryActivity() )->handle(
			$this->payload(
				array(
					'status' => 'failed',
					'error'  => 'Handset switched off',
				)
			)
		);

		$this->assertSame( 'Handset switched off', $this->get( $id )->error_detail );
		$this->assertSame( Status::Failed, $this->get( $id )->status );
	}

	/**
	 * A repeated webhook changes nothing.
	 */
	public function test_repeated_webhook_is_ignored(): void {
		$this->add( Status::Queued );
		$handler = $this->webhook( new InMemoryActivity() );

		$handler->handle( $this->payload() );

		$this->assertSame( WebhookHandler::RESULT_IGNORED, $handler->handle( $this->payload() ) );
		$this->assertSame( WebhookHandler::RESULT_IGNORED, $handler->handle( $this->payload( array( 'status' => 'failed' ) ) ), 'cannot flip a delivered message' );
		$this->assertSame( array( Notifier::NOTE_DELIVERED ), $this->notifier->codes );
	}

	/**
	 * The account webhook also reports campaign messages: they are ignored, never created.
	 */
	public function test_unknown_messages_are_ignored(): void {
		$activity = new InMemoryActivity();

		$result = $this->webhook( $activity )->handle( $this->payload( array( 'id' => 'someone-elses-campaign-message' ) ) );

		$this->assertSame( WebhookHandler::RESULT_UNKNOWN, $result );
		$this->assertCount( 0, $this->store->rows );
		$this->assertSame( array( array( self::NOW, WebhookHandler::RESULT_UNKNOWN ) ), $activity->records );
	}

	/**
	 * When the ID was never saved, the reference finds the message and restores the ID.
	 */
	public function test_webhook_falls_back_to_the_reference(): void {
		$id = $this->add( Status::Sending, null );

		$result = $this->webhook( new InMemoryActivity() )->handle(
			$this->payload(
				array(
					'id'        => 'api-late',
					'reference' => 'woo:ab12cd34:1:completed:1',
				)
			)
		);

		$this->assertSame( WebhookHandler::RESULT_UPDATED, $result );
		$this->assertSame( Status::Delivered, $this->get( $id )->status );
		$this->assertSame( 'api-late', $this->get( $id )->api_message_id );
	}

	/**
	 * Malformed or unsupported events are rejected without side effects.
	 */
	public function test_invalid_payloads_are_rejected(): void {
		$id      = $this->add( Status::Queued );
		$handler = $this->webhook( new InMemoryActivity() );

		$this->assertSame( WebhookHandler::RESULT_INVALID, $handler->handle( array() ) );
		$this->assertSame( WebhookHandler::RESULT_INVALID, $handler->handle( $this->payload( array(), 'something.else' ) ) );
		$this->assertSame( WebhookHandler::RESULT_INVALID, $handler->handle( $this->payload( array( 'status' => 'queued' ) ) ) );
		$this->assertSame( WebhookHandler::RESULT_INVALID, $handler->handle( $this->payload( array( 'id' => '' ) ) ) );
		$this->assertSame(
			WebhookHandler::RESULT_INVALID,
			$handler->handle(
				array(
					'event' => 'message.status_updated',
					'data'  => 'x',
				)
			)
		);
		$this->assertSame( Status::Queued, $this->get( $id )->status );
	}

	// ---- Reconciler ----

	/**
	 * Build a reconciler.
	 *
	 * @param FakeGateway   $gateway Gateway.
	 * @param InMemoryState $state  State.
	 */
	private function reconciler( FakeGateway $gateway, InMemoryState $state ): Reconciler {
		return new Reconciler( $this->store, $gateway, $this->updater, $state, $this->clock );
	}

	/**
	 * The back-off ends after five checks.
	 */
	public function test_check_schedule(): void {
		$this->assertSame( array( 300, 1800, 7200, 21600, 86400 ), array_map( array( CheckSchedule::class, 'delay_after' ), range( 0, 4 ) ) );
		$this->assertNull( CheckSchedule::delay_after( 5 ) );
	}

	/**
	 * Only messages that are due, and have an ID, are checked.
	 */
	public function test_only_due_messages_are_checked(): void {
		$due = $this->add( Status::Queued, 'api-1', self::NOW - 1, 1 );
		$this->add( Status::Queued, 'api-2', self::NOW + 600, 2 );
		$this->add( Status::Queued, null, self::NOW - 1, 3 );
		$this->add( Status::Delivered, 'api-4', self::NOW - 1, 4 );

		$gateway = ( new FakeGateway() )->will_return_for( 'status', $this->status_reply( 'delivered_to_handset' ) );

		$this->assertSame( 1, $this->reconciler( $gateway, new InMemoryState() )->run() );
		$this->assertSame( Status::Delivered, $this->get( $due )->status );
		$this->assertSame( array( 'status' ), $gateway->calls );
	}

	/**
	 * A failure report becomes Failed.
	 */
	public function test_not_delivered_becomes_failed(): void {
		$id      = $this->add( Status::Queued, 'api-1', self::NOW - 1 );
		$gateway = ( new FakeGateway() )->will_return_for( 'status', $this->status_reply( 'not_delivered_to_handset' ) );

		$this->reconciler( $gateway, new InMemoryState() )->run();

		$this->assertSame( Status::Failed, $this->get( $id )->status );
	}

	/**
	 * "Not delivered" with detail "unknown" is not a failure: the message stays unconfirmed and is checked again.
	 */
	public function test_unknown_detail_means_unconfirmed_not_failed(): void {
		$id      = $this->add( Status::Queued, 'api-1', self::NOW - 1 );
		$gateway = ( new FakeGateway() )->will_return_for( 'status', $this->status_reply( 'not_delivered_to_handset', 'unknown' ) );

		$this->reconciler( $gateway, new InMemoryState() )->run();

		$message = $this->get( $id );
		$this->assertSame( Status::Unconfirmed, $message->status );
		$this->assertSame( 'unknown', $message->error_code );
		$this->assertSame( self::NOW + 1800, $message->next_attempt_at );
		$this->assertSame( array( Notifier::NOTE_UNCONFIRMED ), $this->notifier->codes );
	}

	/**
	 * Still queued: back off and try again later.
	 */
	public function test_still_queued_backs_off(): void {
		$id      = $this->add( Status::Queued, 'api-1', self::NOW - 1 );
		$gateway = ( new FakeGateway() )->will_return_for( 'status', $this->status_reply( 'queued_on_smsc' ) );

		$this->reconciler( $gateway, new InMemoryState() )->run();

		$message = $this->get( $id );
		$this->assertSame( Status::Queued, $message->status );
		$this->assertSame( 1, $message->checks );
		$this->assertSame( self::NOW + 1800, $message->next_attempt_at );
	}

	/**
	 * After the last scheduled check the message is left unconfirmed and no longer polled.
	 */
	public function test_gives_up_after_the_last_check(): void {
		$id              = $this->add( Status::Queued, 'api-1', self::NOW - 1 );
		$message         = $this->get( $id );
		$message->checks = 4;
		$this->store->save( $message );
		$gateway = ( new FakeGateway() )->will_return_for( 'status', $this->status_reply( 'queued_on_smsc' ) );

		$this->reconciler( $gateway, new InMemoryState() )->run();

		$message = $this->get( $id );
		$this->assertSame( Status::Unconfirmed, $message->status );
		$this->assertSame( 'no_report', $message->error_code );
		$this->assertNull( $message->next_attempt_at );
		$this->assertCount( 0, $this->store->due_for_check( self::NOW + 999999, 10 ) );
	}

	/**
	 * Rate limiting stops the run without losing anything.
	 */
	public function test_rate_limit_stops_the_run(): void {
		$first   = $this->add( Status::Queued, 'api-1', self::NOW - 1, 1 );
		$second  = $this->add( Status::Queued, 'api-2', self::NOW - 1, 2 );
		$gateway = ( new FakeGateway() )->will_return_for( 'status', new ApiResult( Outcome::RateLimited, 429, array(), null, 30 ) );

		$this->assertSame( 0, $this->reconciler( $gateway, new InMemoryState() )->run() );
		$this->assertSame( array( 'status' ), $gateway->calls, 'no further calls once limited' );
		$this->assertSame( self::NOW - 1, $this->get( $first )->next_attempt_at );
		$this->assertSame( self::NOW - 1, $this->get( $second )->next_attempt_at );
	}

	/**
	 * A rejected key pauses sending and stops the run.
	 */
	public function test_auth_failure_pauses_and_stops(): void {
		$this->add( Status::Queued, 'api-1', self::NOW - 1 );
		$state   = new InMemoryState();
		$gateway = ( new FakeGateway() )->will_return_for( 'status', new ApiResult( Outcome::AuthFailure, 401 ) );

		$this->reconciler( $gateway, $state )->run();

		$this->assertSame( SendingState::REASON_AUTH, $state->paused_reason() );
	}

	/**
	 * A transient error counts as a check but changes nothing else.
	 */
	public function test_transient_error_keeps_status_and_backs_off(): void {
		$id      = $this->add( Status::Queued, 'api-1', self::NOW - 1 );
		$gateway = ( new FakeGateway() )->will_return_for( 'status', new ApiResult( Outcome::Retryable, 500 ) );

		$this->reconciler( $gateway, new InMemoryState() )->run();

		$this->assertSame( Status::Queued, $this->get( $id )->status );
		$this->assertSame( 1, $this->get( $id )->checks );
	}

	// ---- StuckRecovery ----

	/**
	 * Build a recovery job.
	 *
	 * @param FakeGateway        $gateway   Gateway.
	 * @param RecordingScheduler $scheduler Scheduler.
	 */
	private function recovery( FakeGateway $gateway, RecordingScheduler $scheduler ): StuckRecovery {
		return new StuckRecovery( $this->store, $gateway, $scheduler, $this->notifier, $this->clock );
	}

	/**
	 * Lookup result with one message.
	 */
	private function lookup_hit(): ApiResult {
		return new ApiResult(
			Outcome::Success,
			200,
			array(
				array(
					'id'         => 'api-found',
					'attributes' => array(
						'segments'        => 1,
						'credits-charged' => 1,
					),
				),
			)
		);
	}

	/**
	 * The service has the message: adopt its ID.
	 */
	public function test_recovery_adopts_the_found_message(): void {
		$id                = $this->add( Status::Sending, null );
		$this->clock->time = self::NOW + 200;

		$gateway = ( new FakeGateway() )->will_return_for( 'lookup', $this->lookup_hit() );
		$this->recovery( $gateway, new RecordingScheduler() )->run();

		$message = $this->get( $id );
		$this->assertSame( Status::Queued, $message->status );
		$this->assertSame( 'api-found', $message->api_message_id );
		$this->assertSame( 1.0, $message->credits );
		$this->assertSame( array( Notifier::NOTE_QUEUED ), $this->notifier->codes );
	}

	/**
	 * The service has nothing: the request never arrived, so send again with the same key.
	 */
	public function test_recovery_resends_when_the_service_has_nothing(): void {
		$id                = $this->add( Status::Sending, null );
		$this->clock->time = self::NOW + 200;
		$scheduler         = new RecordingScheduler();

		$gateway = ( new FakeGateway() )->will_return_for( 'lookup', new ApiResult( Outcome::Success, 200, array() ) );
		$this->recovery( $gateway, $scheduler )->run();

		$this->assertSame( Status::Pending, $this->get( $id )->status );
		$this->assertSame( array( array( $id, 0 ) ), $scheduler->runs );
	}

	/**
	 * Lookup switched off: resending the same key is safe within the idempotency window.
	 */
	public function test_recovery_resends_without_lookup_when_recent(): void {
		$id                = $this->add( Status::Sending, null );
		$this->clock->time = self::NOW + 200;
		$scheduler         = new RecordingScheduler();

		$gateway = ( new FakeGateway() )->will_return_for( 'lookup', new ApiResult( Outcome::NotFound, 404 ) );
		$this->recovery( $gateway, $scheduler )->run();

		$this->assertSame( Status::Pending, $this->get( $id )->status );
		$this->assertCount( 1, $scheduler->runs );
	}

	/**
	 * Lookup switched off and the idempotency window has passed: never risk a duplicate.
	 */
	public function test_recovery_never_resends_outside_the_idempotency_window(): void {
		$id                = $this->add( Status::Sending, null );
		$this->clock->time = self::NOW + 90000;
		$scheduler         = new RecordingScheduler();

		$gateway = ( new FakeGateway() )->will_return_for( 'lookup', new ApiResult( Outcome::NotFound, 404 ) );
		$this->recovery( $gateway, $scheduler )->run();

		$this->assertSame( Status::Unconfirmed, $this->get( $id )->status );
		$this->assertSame( 'interrupted', $this->get( $id )->error_code );
		$this->assertSame( array(), $scheduler->runs );
	}

	/**
	 * A message that only just started sending is not stuck yet.
	 */
	public function test_recovery_ignores_messages_that_just_started_sending(): void {
		$id                  = $this->add( Status::Sending, null );
		$message             = $this->get( $id );
		$message->updated_at = self::NOW - 30;
		$this->store->save( $message );

		$gateway = new FakeGateway();

		$this->assertSame( 0, $this->recovery( $gateway, new RecordingScheduler() )->run() );
		$this->assertSame( array(), $gateway->calls );
		$this->assertSame( Status::Sending, $this->get( $id )->status );
	}

	/**
	 * If the lookup itself fails, the message is retried on the next run.
	 */
	public function test_recovery_retries_later_when_lookup_fails(): void {
		$id = $this->add( Status::Sending, null );

		$gateway = ( new FakeGateway() )->will_return_for( 'lookup', new ApiResult( Outcome::Retryable, 0 ) );

		$this->assertSame( 0, $this->recovery( $gateway, new RecordingScheduler() )->run() );
		$this->assertSame( Status::Sending, $this->get( $id )->status );
	}
}
