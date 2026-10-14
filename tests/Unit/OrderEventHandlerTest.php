<?php
/**
 * Tests for the order event handler.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Povikvane\WooCommerce\Messages\Enqueuer;
use Povikvane\WooCommerce\Messages\SkipReason;
use Povikvane\WooCommerce\Messages\Status;
use Povikvane\WooCommerce\Orders\OrderEventHandler;
use Povikvane\WooCommerce\Orders\OrderFacts;
use Povikvane\WooCommerce\Orders\Rules;
use Povikvane\WooCommerce\Orders\Trigger;
use Povikvane\WooCommerce\Phone\Normalizer;
use Povikvane\WooCommerce\Safety\StormGuard;
use Povikvane\WooCommerce\Support\Notifier;
use Povikvane\WooCommerce\Support\SendingState;
use Povikvane\WooCommerce\Tests\Support\FixedClock;
use Povikvane\WooCommerce\Tests\Support\InMemoryState;
use Povikvane\WooCommerce\Tests\Support\InMemoryStore;
use Povikvane\WooCommerce\Tests\Support\RecordingNotifier;
use Povikvane\WooCommerce\Tests\Support\RecordingScheduler;

/**
 * What happens when an order changes status.
 */
final class OrderEventHandlerTest extends TestCase {

	private const NOW = 1760000000;

	private InMemoryStore $store;
	private RecordingScheduler $scheduler;
	private RecordingNotifier $notifier;
	private InMemoryState $state;
	private FixedClock $clock;

	/**
	 * Fresh doubles per test.
	 */
	protected function setUp(): void {
		$this->store     = new InMemoryStore();
		$this->scheduler = new RecordingScheduler();
		$this->notifier  = new RecordingNotifier();
		$this->state     = new InMemoryState();
		$this->clock     = new FixedClock( self::NOW );
	}

	/**
	 * Build a handler.
	 *
	 * @param Rules|null $rules Rules; defaults apply when null.
	 * @param bool       $allow Whether the veto callback allows.
	 * @param int        $storm Storm threshold.
	 */
	private function handler( ?Rules $rules = null, bool $allow = true, int $storm = 50 ): OrderEventHandler {
		return new OrderEventHandler(
			$this->store,
			new Enqueuer( $this->store, $this->scheduler, $this->clock ),
			new Normalizer(),
			new StormGuard( $this->store, $this->state, $this->clock, $storm, 600 ),
			$this->state,
			$this->notifier,
			$this->clock,
			'ab12cd34',
			$rules ?? new Rules(),
			static fn(): bool => $allow
		);
	}

	/**
	 * Build order facts.
	 *
	 * @param array<string,mixed> $overrides Field overrides.
	 */
	private function facts( array $overrides = array() ): OrderFacts {
		$data = array_merge(
			array(
				'order_id'     => 1234,
				'status'       => 'completed',
				'created_at'   => self::NOW - 3600,
				'created_via'  => 'checkout',
				'phone'        => '0888 123 456',
				'calling_code' => '+359',
				'values'       => array(
					'first_name'   => 'Maria',
					'order_number' => '1234',
					'shop_name'    => 'Demo Shop',
				),
			),
			$overrides
		);

		return new OrderFacts( $data['order_id'], $data['status'], $data['created_at'], $data['created_via'], $data['phone'], $data['calling_code'], $data['values'] );
	}

	/**
	 * Enabled trigger.
	 *
	 * @param int    $delay    Delay in minutes.
	 * @param string $template Template.
	 */
	private function trigger( int $delay = 0, string $template = 'Hi {first_name}, order #{order_number}. {shop_name}' ): Trigger {
		return new Trigger( 'completed', true, $template, $delay );
	}

	/**
	 * The normal path: one pending message with the rendered text.
	 */
	public function test_queues_a_message(): void {
		$result = $this->handler()->handle( $this->facts(), $this->trigger() );

		$this->assertSame( OrderEventHandler::RESULT_QUEUED, $result );
		$message = $this->store->find( 1 );
		$this->assertSame( Status::Pending, $message->status );
		$this->assertSame( '+359888123456', $message->recipient );
		$this->assertSame( 'Hi Maria, order #1234. Demo Shop', $message->body );
		$this->assertSame( 'woo:ab12cd34:1234:completed:1', $message->reference );
		$this->assertSame( 'completed', $message->event );
		$this->assertSame( array( array( 1, 0 ) ), $this->scheduler->runs );
	}

	/**
	 * A configured delay becomes the schedule delay.
	 */
	public function test_delay_is_applied(): void {
		$this->handler()->handle( $this->facts(), $this->trigger( 5 ) );

		$this->assertSame( array( array( 1, 300 ) ), $this->scheduler->runs );
	}

	/**
	 * A disabled status does nothing at all, not even a log row.
	 */
	public function test_disabled_trigger_does_nothing(): void {
		$result = $this->handler()->handle( $this->facts(), new Trigger( 'completed', false, 'x', 0 ) );

		$this->assertSame( OrderEventHandler::RESULT_DISABLED, $result );
		$this->assertCount( 0, $this->store->rows );
	}

	/**
	 * Status flip-flops cannot send the same message twice.
	 */
	public function test_same_order_and_status_is_only_handled_once(): void {
		$handler = $this->handler();

		$first  = $handler->handle( $this->facts(), $this->trigger() );
		$second = $handler->handle( $this->facts(), $this->trigger() );

		$this->assertSame( OrderEventHandler::RESULT_QUEUED, $first );
		$this->assertSame( OrderEventHandler::RESULT_DUPLICATE, $second );
		$this->assertCount( 1, $this->store->rows );
		$this->assertCount( 1, $this->scheduler->runs );
	}

	/**
	 * Skipped orders are logged with a reason, noted, and never scheduled.
	 *
	 * @param array<string,mixed> $overrides Facts overrides.
	 * @param string              $reason    Expected reason.
	 * @param Rules|null          $rules     Rules.
	 */
	private function assert_skipped( array $overrides, string $reason, ?Rules $rules = null ): void {
		$result = $this->handler( $rules )->handle( $this->facts( $overrides ), $this->trigger() );

		$this->assertSame( OrderEventHandler::RESULT_SKIPPED, $result );
		$message = $this->store->find( 1 );
		$this->assertSame( Status::Skipped, $message->status );
		$this->assertSame( $reason, $message->skip_reason );
		$this->assertNotNull( $message->final_at );
		$this->assertSame( array(), $this->scheduler->runs );
		$this->assertSame( array( Notifier::NOTE_SKIPPED ), $this->notifier->codes );
	}

	/**
	 * No phone number.
	 */
	public function test_missing_phone_is_skipped(): void {
		$this->assert_skipped( array( 'phone' => '  ' ), SkipReason::NO_PHONE );
	}

	/**
	 * Garbage phone number.
	 */
	public function test_invalid_phone_is_skipped(): void {
		$this->assert_skipped( array( 'phone' => 'call me' ), SkipReason::INVALID_PHONE );
	}

	/**
	 * A Bulgarian landline wastes a credit, so it is skipped by default.
	 */
	public function test_landline_is_skipped_when_mobile_only(): void {
		$this->assert_skipped( array( 'phone' => '02 123 4567' ), SkipReason::NOT_MOBILE );
	}

	/**
	 * The mobile-only rule can be turned off, and unchecked countries always pass.
	 */
	public function test_landline_is_sent_when_mobile_only_is_off(): void {
		$landline = $this->handler( new Rules( 7, false, false ) )->handle( $this->facts( array( 'phone' => '02 123 4567' ) ), $this->trigger() );
		$this->assertSame( OrderEventHandler::RESULT_QUEUED, $landline );
	}

	/**
	 * Numbers from countries we do not check are not blocked.
	 */
	public function test_foreign_number_is_not_blocked_by_mobile_check(): void {
		$result = $this->handler()->handle( $this->facts( array( 'phone' => '+44 7911 123456' ) ), $this->trigger() );

		$this->assertSame( OrderEventHandler::RESULT_QUEUED, $result );
	}

	/**
	 * Old orders never get an automatic message.
	 */
	public function test_old_orders_are_skipped(): void {
		$this->assert_skipped( array( 'created_at' => self::NOW - 8 * DAY_IN_SECONDS ), SkipReason::TOO_OLD );
	}

	/**
	 * The age limit can be disabled.
	 */
	public function test_age_limit_can_be_disabled(): void {
		$result = $this->handler( new Rules( 0 ) )->handle( $this->facts( array( 'created_at' => self::NOW - 400 * DAY_IN_SECONDS ) ), $this->trigger() );

		$this->assertSame( OrderEventHandler::RESULT_QUEUED, $result );
	}

	/**
	 * Admin-created orders are skipped only when that rule is on.
	 */
	public function test_admin_created_orders_follow_the_rule(): void {
		$this->assert_skipped( array( 'created_via' => 'admin' ), SkipReason::ADMIN_CREATED, new Rules( 7, true, true ) );
	}

	/**
	 * With the rule off, admin-created orders are messaged.
	 */
	public function test_admin_created_orders_are_sent_by_default(): void {
		$result = $this->handler()->handle( $this->facts( array( 'created_via' => 'admin' ) ), $this->trigger() );

		$this->assertSame( OrderEventHandler::RESULT_QUEUED, $result );
	}

	/**
	 * A copied site must not message customers: events are recorded as skipped.
	 */
	public function test_unconfirmed_site_skips_everything(): void {
		$this->state->reason = SendingState::REASON_SITE;

		$this->assert_skipped( array(), SkipReason::SITE_UNCONFIRMED );
	}

	/**
	 * A template that renders to nothing is not sent.
	 */
	public function test_empty_message_is_skipped(): void {
		$result = $this->handler()->handle( $this->facts(), $this->trigger( 0, '{tracking_number}' ) );

		$this->assertSame( OrderEventHandler::RESULT_SKIPPED, $result );
		$this->assertSame( SkipReason::EMPTY_MESSAGE, $this->store->find( 1 )->skip_reason );
	}

	/**
	 * Other code can veto a message.
	 */
	public function test_veto_skips_the_message(): void {
		$result = $this->handler( null, false )->handle( $this->facts(), $this->trigger() );

		$this->assertSame( OrderEventHandler::RESULT_SKIPPED, $result );
		$this->assertSame( SkipReason::VETOED, $this->store->find( 1 )->skip_reason );
	}

	/**
	 * A burst of messages pauses sending but loses nothing.
	 */
	public function test_storm_pauses_sending_and_keeps_messages(): void {
		$handler = $this->handler( null, true, 3 );

		foreach ( range( 1, 5 ) as $order_id ) {
			$handler->handle( $this->facts( array( 'order_id' => $order_id ) ), $this->trigger() );
		}

		$this->assertSame( SendingState::REASON_STORM, $this->state->paused_reason() );
		$this->assertCount( 5, $this->store->rows, 'every message is still recorded' );
		$this->assertCount( 5, $this->scheduler->runs );
	}
}
