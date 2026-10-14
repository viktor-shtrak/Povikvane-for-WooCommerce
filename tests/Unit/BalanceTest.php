<?php
/**
 * Tests for balance tracking, refresh and the low-balance alert.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Povikvane\WooCommerce\Api\ApiResult;
use Povikvane\WooCommerce\Api\Outcome;
use Povikvane\WooCommerce\Balance\Balance;
use Povikvane\WooCommerce\Balance\BalanceRefresher;
use Povikvane\WooCommerce\Balance\BalanceTracker;
use Povikvane\WooCommerce\Balance\LowBalanceAlert;
use Povikvane\WooCommerce\Support\SendingState;
use Povikvane\WooCommerce\Tests\Support\FakeGateway;
use Povikvane\WooCommerce\Tests\Support\FixedClock;
use Povikvane\WooCommerce\Tests\Support\InMemoryBalanceStore;
use Povikvane\WooCommerce\Tests\Support\InMemoryState;
use Povikvane\WooCommerce\Tests\Support\RecordingMailer;
use Povikvane\WooCommerce\Tests\Support\RecordingScheduler;

/**
 * Balance behaviour.
 */
final class BalanceTest extends TestCase {

	private const NOW = 1760000000;

	private InMemoryBalanceStore $store;
	private RecordingScheduler $scheduler;
	private RecordingMailer $mailer;
	private InMemoryState $state;
	private FixedClock $clock;

	/**
	 * Fresh doubles per test.
	 */
	protected function setUp(): void {
		$this->store     = new InMemoryBalanceStore();
		$this->scheduler = new RecordingScheduler();
		$this->mailer    = new RecordingMailer();
		$this->state     = new InMemoryState();
		$this->clock     = new FixedClock( self::NOW );
	}

	/**
	 * Build a refresher around a gateway.
	 *
	 * @param FakeGateway $gateway   Gateway.
	 * @param int         $threshold Alert threshold.
	 */
	private function refresher( FakeGateway $gateway, int $threshold = 50 ): BalanceRefresher {
		return new BalanceRefresher(
			$gateway,
			$this->store,
			$this->state,
			$this->clock,
			new LowBalanceAlert( $this->store, $this->mailer, $this->clock, $threshold )
		);
	}

	/**
	 * Successful account response.
	 *
	 * @param float $available Credits available.
	 */
	private function account( float $available ): ApiResult {
		return new ApiResult(
			Outcome::Success,
			200,
			array(
				'attributes' => array(
					'credits-available' => $available,
					'credits-locked'    => 2,
					'channels'          => array( 'sms', 'viber' ),
				),
			)
		);
	}

	/**
	 * Spending never goes below zero and rounds decimal credits.
	 */
	public function test_balance_spend_floors_at_zero_and_rounds(): void {
		$balance = new Balance( 10.0, 0.0, array(), 1 );

		$this->assertSame( 9.2, $balance->spend( 0.8 )->available );
		$this->assertTrue( $balance->spend( 0.8 )->estimated );
		$this->assertSame( 0.0, $balance->spend( 25.0 )->available );
		$this->assertSame( 0.0, $balance->exhausted()->available );
		$this->assertSame( 1, $balance->spend( 1.0 )->fetched_at, 'the report time is kept' );
		$this->assertFalse( $balance->estimated );
	}

	/**
	 * Each accepted message lowers the balance and asks for one refresh.
	 */
	public function test_tracker_reduces_balance_and_schedules_refresh(): void {
		$this->store->save( new Balance( 100.0, 0.0, array( 'sms' ), self::NOW - 600 ) );
		$tracker = new BalanceTracker( $this->store, $this->scheduler, 60 );

		$tracker->record_spent( 1.0 );
		$tracker->record_spent( 0.8 );

		$this->assertSame( 98.2, $this->store->get()->available );
		$this->assertTrue( $this->store->get()->estimated );
		$this->assertSame( array( 60, 60 ), $this->scheduler->balance_refreshes, 'the scheduler de-duplicates; the tracker just asks' );
	}

	/**
	 * Without a stored balance the tracker still asks for a refresh.
	 */
	public function test_tracker_without_a_balance_only_schedules_refresh(): void {
		( new BalanceTracker( $this->store, $this->scheduler ) )->record_spent( 1.0 );

		$this->assertNull( $this->store->get() );
		$this->assertCount( 1, $this->scheduler->balance_refreshes );
	}

	/**
	 * A "no credits" answer drops the balance to zero straight away.
	 */
	public function test_tracker_marks_balance_exhausted(): void {
		$this->store->save( new Balance( 3.0, 0.0, array(), self::NOW ) );

		( new BalanceTracker( $this->store, $this->scheduler ) )->record_exhausted();

		$this->assertSame( 0.0, $this->store->get()->available );
		$this->assertCount( 1, $this->scheduler->balance_refreshes );
	}

	/**
	 * A refresh stores what the service reports.
	 */
	public function test_refresh_stores_the_reported_balance(): void {
		$gateway = ( new FakeGateway() )->will_return_for( 'account', $this->account( 142.8 ) );

		$balance = $this->refresher( $gateway )->refresh();

		$this->assertSame( 142.8, $balance->available );
		$this->assertSame( 2.0, $balance->locked );
		$this->assertSame( array( 'sms', 'viber' ), $balance->channels );
		$this->assertSame( self::NOW, $balance->fetched_at );
		$this->assertFalse( $balance->estimated );
		$this->assertSame( 142.8, $this->store->get()->available );
	}

	/**
	 * A failed or unavailable call keeps the old balance.
	 */
	public function test_failed_refresh_keeps_the_stored_balance(): void {
		$this->store->save( new Balance( 7.0, 0.0, array(), 1 ) );

		foreach ( array( Outcome::NotFound, Outcome::Retryable, Outcome::AuthFailure ) as $outcome ) {
			$gateway = ( new FakeGateway() )->will_return_for( 'account', new ApiResult( $outcome, 0 ) );
			$this->assertNull( $this->refresher( $gateway )->refresh() );
		}

		$this->assertSame( 7.0, $this->store->get()->available );
	}

	/**
	 * A response without a usable figure is ignored.
	 */
	public function test_response_without_a_figure_is_ignored(): void {
		$result = ( new FakeGateway() )->will_return_for( 'account', new ApiResult( Outcome::Success, 200, array( 'attributes' => array( 'credits-available' => 'lots' ) ) ) );

		$this->assertNull( $this->refresher( $result )->refresh() );
		$this->assertNull( $this->store->get() );
	}

	/**
	 * Credits coming back lift a "no credits" pause, but not other pauses.
	 */
	public function test_credits_returning_resume_a_credits_pause_only(): void {
		$this->state->reason = SendingState::REASON_CREDITS;
		$this->refresher( ( new FakeGateway() )->will_return_for( 'account', $this->account( 0.0 ) ) )->refresh();
		$this->assertSame( SendingState::REASON_CREDITS, $this->state->paused_reason(), 'still empty' );

		$this->refresher( ( new FakeGateway() )->will_return_for( 'account', $this->account( 500.0 ) ) )->refresh();
		$this->assertNull( $this->state->paused_reason() );

		$this->state->reason = SendingState::REASON_SITE;
		$this->refresher( ( new FakeGateway() )->will_return_for( 'account', $this->account( 500.0 ) ) )->refresh();
		$this->assertSame( SendingState::REASON_SITE, $this->state->paused_reason(), 'a site pause is never lifted by credits' );
	}

	/**
	 * The balance from a capability probe is stored without another API call.
	 */
	public function test_apply_stores_a_balance_without_calling_the_api(): void {
		$gateway = new FakeGateway();

		$this->refresher( $gateway )->apply( array( 'credits-available' => 12.5 ) );

		$this->assertSame( array(), $gateway->calls );
		$this->assertSame( 12.5, $this->store->get()->available );
	}

	/**
	 * Below the threshold: one email, then quiet for a day.
	 */
	public function test_low_balance_emails_once_a_day(): void {
		$alert = new LowBalanceAlert( $this->store, $this->mailer, $this->clock, 50 );
		$low   = new Balance( 20.0, 0.0, array(), self::NOW );

		$alert->check( $low );
		$alert->check( $low );
		$this->assertSame( array( array( 20.0, 50 ) ), $this->mailer->sent );

		$this->clock->time += 86400;
		$alert->check( $low );
		$this->assertCount( 2, $this->mailer->sent );
	}

	/**
	 * Recovering above the threshold re-arms the alert.
	 */
	public function test_alert_rearms_when_balance_recovers(): void {
		$alert = new LowBalanceAlert( $this->store, $this->mailer, $this->clock, 50 );

		$alert->check( new Balance( 20.0, 0.0, array(), self::NOW ) );
		$alert->check( new Balance( 500.0, 0.0, array(), self::NOW ) );
		$alert->check( new Balance( 10.0, 0.0, array(), self::NOW ) );

		$this->assertCount( 2, $this->mailer->sent, 'a new dip alerts immediately' );
	}

	/**
	 * A threshold of zero turns the alert off.
	 */
	public function test_alert_can_be_disabled(): void {
		( new LowBalanceAlert( $this->store, $this->mailer, $this->clock, 0 ) )->check( new Balance( 0.0, 0.0, array(), self::NOW ) );

		$this->assertSame( array(), $this->mailer->sent );
	}
}
