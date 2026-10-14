<?php
/**
 * Tests for consent, opt-out, quiet hours, frequency cap and promotional gating.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Tests\Unit;

use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Povikvane\WooCommerce\Log\Message;
use Povikvane\WooCommerce\Marketing\Consent;
use Povikvane\WooCommerce\Marketing\ConsentEvent;
use Povikvane\WooCommerce\Marketing\ConsentLedger;
use Povikvane\WooCommerce\Marketing\FollowupConfig;
use Povikvane\WooCommerce\Marketing\FollowupHandler;
use Povikvane\WooCommerce\Marketing\FrequencyCap;
use Povikvane\WooCommerce\Marketing\MarketingGate;
use Povikvane\WooCommerce\Marketing\OptOutToken;
use Povikvane\WooCommerce\Marketing\QuietHours;
use Povikvane\WooCommerce\Messages\CompositeGate;
use Povikvane\WooCommerce\Messages\Dispatcher;
use Povikvane\WooCommerce\Messages\Enqueuer;
use Povikvane\WooCommerce\Messages\Kind;
use Povikvane\WooCommerce\Messages\SendGate;
use Povikvane\WooCommerce\Messages\SkipReason;
use Povikvane\WooCommerce\Messages\Status;
use Povikvane\WooCommerce\Orders\OrderFacts;
use Povikvane\WooCommerce\Orders\Rules;
use Povikvane\WooCommerce\Phone\Normalizer;
use Povikvane\WooCommerce\Safety\StormGuard;
use Povikvane\WooCommerce\Support\NullLogger;
use Povikvane\WooCommerce\Support\SendingState;
use Povikvane\WooCommerce\Tests\Support\FakeGateway;
use Povikvane\WooCommerce\Tests\Support\FixedClock;
use Povikvane\WooCommerce\Tests\Support\InMemoryLedger;
use Povikvane\WooCommerce\Tests\Support\InMemoryState;
use Povikvane\WooCommerce\Tests\Support\InMemoryStore;
use Povikvane\WooCommerce\Tests\Support\RecordingNotifier;
use Povikvane\WooCommerce\Tests\Support\RecordingScheduler;
use Povikvane\WooCommerce\Api\ApiResult;
use Povikvane\WooCommerce\Api\Outcome;

/**
 * Promotional messaging rules.
 */
final class MarketingTest extends TestCase {

	private const NOW   = 1760000000;
	private const PHONE = '+359888123456';

	private InMemoryLedger $ledger;
	private InMemoryStore $store;
	private FixedClock $clock;
	private Consent $consent;

	/**
	 * Fresh doubles per test.
	 */
	protected function setUp(): void {
		$this->ledger  = new InMemoryLedger();
		$this->store   = new InMemoryStore();
		$this->clock   = new FixedClock( self::NOW );
		$this->consent = new Consent( $this->ledger, $this->clock, 'secret' );
	}

	// ---- Consent ----

	/**
	 * Nobody has consent until they give it.
	 */
	public function test_no_record_means_no_consent(): void {
		$this->assertFalse( $this->consent->has_consent( self::PHONE ) );
	}

	/**
	 * Granting records one event, and repeating it records nothing.
	 */
	public function test_grant_is_recorded_once(): void {
		$this->assertTrue( $this->consent->grant( self::PHONE, 'a@example.com', ConsentEvent::SOURCE_CHECKOUT, 12, 3, 'abc' ) );
		$this->assertFalse( $this->consent->grant( self::PHONE, 'a@example.com', ConsentEvent::SOURCE_CHECKOUT, 13, 3, 'abc' ) );

		$this->assertTrue( $this->consent->has_consent( self::PHONE ) );
		$this->assertCount( 1, $this->ledger->events );
		$this->assertSame( self::NOW, $this->ledger->events[0]->created_at );
	}

	/**
	 * The newest decision wins, in both directions.
	 */
	public function test_newest_decision_wins(): void {
		$this->consent->grant( self::PHONE, null, ConsentEvent::SOURCE_CHECKOUT, 1, 0, 'abc' );
		$this->consent->revoke( self::PHONE, ConsentEvent::SOURCE_ACCOUNT );
		$this->assertFalse( $this->consent->has_consent( self::PHONE ) );

		$this->consent->grant( self::PHONE, null, ConsentEvent::SOURCE_CHECKOUT, 2, 0, 'abc' );
		$this->assertTrue( $this->consent->has_consent( self::PHONE ) );
		$this->assertCount( 3, $this->ledger->events );
	}

	/**
	 * Numbers are matched by a keyed hash, the same number always gives the same one.
	 */
	public function test_hash_is_stable_keyed_and_short(): void {
		$other = new Consent( $this->ledger, $this->clock, 'another secret' );

		$this->assertSame( $this->consent->hash( self::PHONE ), $this->consent->hash( self::PHONE ) );
		$this->assertNotSame( $this->consent->hash( self::PHONE ), $other->hash( self::PHONE ) );
		$this->assertNotSame( $this->consent->hash( self::PHONE ), $this->consent->hash( '+359888123457' ) );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{12}$/', $this->consent->hash( self::PHONE ) );
	}

	/**
	 * A withdrawal made from the link needs only the hash, and keeps the stored number.
	 */
	public function test_revoke_by_hash(): void {
		$this->consent->grant( self::PHONE, 'a@example.com', ConsentEvent::SOURCE_CHECKOUT, 1, 0, 'abc' );

		$this->assertTrue( $this->consent->revoke_by_hash( $this->consent->hash( self::PHONE ), ConsentEvent::SOURCE_OPTOUT ) );
		$this->assertFalse( $this->consent->has_consent( self::PHONE ) );
		$this->assertFalse( $this->consent->revoke_by_hash( $this->consent->hash( self::PHONE ), ConsentEvent::SOURCE_OPTOUT ), 'already withdrawn' );
		$this->assertSame( self::PHONE, $this->ledger->events[1]->phone );
	}

	/**
	 * Someone who never agreed can still opt out, which records the suppression.
	 */
	public function test_unknown_hash_can_opt_out(): void {
		$this->assertTrue( $this->consent->revoke_by_hash( 'aaaaaaaaaaaa', ConsentEvent::SOURCE_OPTOUT ) );
		$this->assertCount( 1, $this->ledger->events );
	}

	// ---- OptOutToken ----

	/**
	 * A token verifies back to its hash.
	 */
	public function test_token_round_trip(): void {
		$token = new OptOutToken( 'secret' );
		$hash  = $this->consent->hash( self::PHONE );

		$this->assertSame( 25, strlen( $token->create( $hash ) ) );
		$this->assertSame( $hash, $token->verify( $token->create( $hash ) ) );
	}

	/**
	 * Forged, altered or malformed tokens are rejected.
	 */
	public function test_bad_tokens_are_rejected(): void {
		$token = new OptOutToken( 'secret' );
		$good  = $token->create( 'abcdef123456' );

		$this->assertNull( $token->verify( 'abcdef123456-000000000000' ) );
		$this->assertNull( $token->verify( 'abcdef123457' . substr( $good, 12 ) ), 'a different hash with the old signature' );
		$this->assertNull( ( new OptOutToken( 'other secret' ) )->verify( $good ), 'signed with another key' );
		$this->assertNull( $token->verify( '' ) );
		$this->assertNull( $token->verify( 'abcdef123456' ) );
		$this->assertNull( $token->verify( 'ABCDEF123456-' . substr( $good, 13 ) ) );
		$this->assertNull( $token->verify( $good . 'x' ) );
	}

	// ---- QuietHours ----

	/**
	 * Build a window in Sofia time.
	 *
	 * @param int $open  Opening hour.
	 * @param int $close Closing hour.
	 */
	private function window( int $open = 9, int $close = 20 ): QuietHours {
		return new QuietHours( $open, $close, new DateTimeZone( 'Europe/Sofia' ) );
	}

	/**
	 * Unix time for a Sofia local time on 2026-03-10 (before daylight saving, UTC+2).
	 *
	 * @param string $time Local time such as "10:30".
	 */
	private function sofia( string $time ): int {
		return ( new \DateTimeImmutable( '2026-03-10 ' . $time, new DateTimeZone( 'Europe/Sofia' ) ) )->getTimestamp();
	}

	/**
	 * Inside the window nothing waits.
	 */
	public function test_open_hours_do_not_wait(): void {
		$this->assertSame( 0, $this->window()->seconds_until_open( $this->sofia( '09:00' ) ) );
		$this->assertSame( 0, $this->window()->seconds_until_open( $this->sofia( '14:30' ) ) );
		$this->assertSame( 0, $this->window()->seconds_until_open( $this->sofia( '19:59' ) ) );
	}

	/**
	 * Evening waits until the next morning; night waits until the same morning.
	 */
	public function test_closed_hours_wait_for_the_next_opening(): void {
		$this->assertSame( 13 * 3600, $this->window()->seconds_until_open( $this->sofia( '20:00' ) ) );
		$this->assertSame( 12 * 3600 + 1800, $this->window()->seconds_until_open( $this->sofia( '20:30' ) ) );
		$this->assertSame( 6 * 3600, $this->window()->seconds_until_open( $this->sofia( '03:00' ) ) );
		$this->assertSame( 1, $this->window()->seconds_until_open( $this->sofia( '08:59' ) + 59 ) );
	}

	/**
	 * Equal hours turn the limit off; an overnight window works across midnight.
	 */
	public function test_unlimited_and_overnight_windows(): void {
		$this->assertSame( 0, $this->window( 0, 0 )->seconds_until_open( $this->sofia( '03:00' ) ) );

		$overnight = $this->window( 22, 6 );
		$this->assertSame( 0, $overnight->seconds_until_open( $this->sofia( '23:00' ) ) );
		$this->assertSame( 0, $overnight->seconds_until_open( $this->sofia( '02:00' ) ) );
		$this->assertSame( 16 * 3600, $overnight->seconds_until_open( $this->sofia( '06:00' ) ) );
	}

	// ---- Frequency cap & gate ----

	/**
	 * Add a promotional message to the log.
	 *
	 * @param string $reference Reference suffix.
	 * @param Status $status    Status.
	 * @param int    $age_days  Age in days.
	 */
	private function add_marketing( string $reference, Status $status, int $age_days = 1 ): void {
		$message             = new Message();
		$message->kind       = Kind::Marketing;
		$message->recipient  = self::PHONE;
		$message->reference  = 'woo:ab:' . $reference;
		$message->status     = $status;
		$message->created_at = self::NOW - $age_days * 86400;
		$this->store->create( $message );
	}

	/**
	 * Only recent, actually-sent promotional messages count.
	 */
	public function test_frequency_cap_counts_recent_sent_messages_only(): void {
		$cap = new FrequencyCap( $this->store, 1, 7 );

		$this->assertFalse( $cap->reached( self::PHONE, self::NOW ) );

		$this->add_marketing( 'failed', Status::Failed );
		$this->add_marketing( 'skipped', Status::Skipped );
		$this->add_marketing( 'old', Status::Delivered, 10 );
		$this->assertFalse( $cap->reached( self::PHONE, self::NOW ), 'failed, skipped and old messages do not count' );

		$this->add_marketing( 'recent', Status::Queued );
		$this->assertTrue( $cap->reached( self::PHONE, self::NOW ) );
		$this->assertFalse( $cap->reached( '+359877000000', self::NOW ), 'another number is unaffected' );
	}

	/**
	 * A cap of zero means unlimited.
	 */
	public function test_frequency_cap_can_be_disabled(): void {
		$this->add_marketing( 'recent', Status::Delivered );

		$this->assertFalse( ( new FrequencyCap( $this->store, 0, 7 ) )->reached( self::PHONE, self::NOW ) );
		$this->assertFalse( ( new FrequencyCap( $this->store, 1, 0 ) )->reached( self::PHONE, self::NOW ) );
	}

	/**
	 * Build a promotional message.
	 */
	private function promo(): Message {
		$message            = new Message();
		$message->kind      = Kind::Marketing;
		$message->recipient = self::PHONE;

		return $message;
	}

	/**
	 * Build the gate.
	 *
	 * @param bool $enabled Whether promotions are switched on.
	 */
	private function gate( bool $enabled = true ): MarketingGate {
		return new MarketingGate( $enabled, $this->consent, new FrequencyCap( $this->store, 1, 7 ), $this->clock );
	}

	/**
	 * Transactional messages are never touched by the promotional gate.
	 */
	public function test_gate_ignores_transactional_messages(): void {
		$message = new Message();

		$this->assertNull( $this->gate( false )->blocks( $message ) );
	}

	/**
	 * Promotional messages need the switch, consent and room under the cap.
	 */
	public function test_gate_checks_switch_consent_and_cap(): void {
		$this->assertSame( SkipReason::MARKETING_OFF, $this->gate( false )->blocks( $this->promo() ) );
		$this->assertSame( SkipReason::NO_CONSENT, $this->gate()->blocks( $this->promo() ) );

		$this->consent->grant( self::PHONE, null, ConsentEvent::SOURCE_CHECKOUT, 1, 0, 'abc' );
		$this->assertNull( $this->gate()->blocks( $this->promo() ) );

		$this->add_marketing( 'recent', Status::Delivered );
		$this->assertSame( SkipReason::FREQUENCY_CAP, $this->gate()->blocks( $this->promo() ) );

		$this->consent->revoke( self::PHONE, ConsentEvent::SOURCE_OPTOUT );
		$this->assertSame( SkipReason::NO_CONSENT, $this->gate()->blocks( $this->promo() ), 'withdrawal beats everything' );
	}

	/**
	 * The composite returns the first reason that blocks.
	 */
	public function test_composite_gate_returns_first_block(): void {
		$allow = new class() implements SendGate {
			/**
			 * Allows.
			 *
			 * @param Message $message Message.
			 */
			public function blocks( Message $message ): ?string {
				return null;
			}
		};
		$deny  = new class() implements SendGate {
			/**
			 * Blocks.
			 *
			 * @param Message $message Message.
			 */
			public function blocks( Message $message ): ?string {
				return SkipReason::VETOED;
			}
		};

		$this->assertNull( ( new CompositeGate( $allow, $allow ) )->blocks( new Message() ) );
		$this->assertSame( SkipReason::VETOED, ( new CompositeGate( $allow, $deny, $allow ) )->blocks( new Message() ) );
		$this->assertNull( ( new CompositeGate() )->blocks( new Message() ) );
	}

	// ---- Dispatcher integration ----

	/**
	 * Build a dispatcher for a promotional message.
	 *
	 * @param FakeGateway        $gateway   Gateway.
	 * @param RecordingScheduler $scheduler Scheduler.
	 * @param QuietHours|null    $quiet     Quiet hours.
	 * @param bool               $enabled   Whether promotions are on.
	 */
	private function dispatcher( FakeGateway $gateway, RecordingScheduler $scheduler, ?QuietHours $quiet, bool $enabled = true ): Dispatcher {
		return new Dispatcher(
			$this->store,
			$gateway,
			$scheduler,
			$this->clock,
			new RecordingNotifier(),
			new InMemoryState(),
			86400,
			new NullLogger(),
			$this->gate( $enabled ),
			null,
			$quiet
		);
	}

	/**
	 * Store a pending promotional message and return its ID.
	 */
	private function pending_promo(): int {
		$message             = $this->promo();
		$message->reference  = 'woo:ab:9:followup_review:1';
		$message->body       = 'Hi';
		$message->created_at = $this->clock->time;
		$message->updated_at = $this->clock->time;

		return (int) $this->store->create( $message );
	}

	/**
	 * Outside the window a promotional message waits and is not sent.
	 */
	public function test_promotions_wait_for_quiet_hours_to_end(): void {
		$this->consent->grant( self::PHONE, null, ConsentEvent::SOURCE_CHECKOUT, 1, 0, 'abc' );
		$this->clock->time = $this->sofia( '22:00' );
		$id                = $this->pending_promo();
		$gateway           = new FakeGateway();
		$scheduler         = new RecordingScheduler();

		$this->dispatcher( $gateway, $scheduler, $this->window() )->handle( $id );

		$this->assertSame( array(), $gateway->sent );
		$this->assertSame( Status::Pending, $this->store->find( $id )->status );
		$this->assertSame( array( array( $id, 11 * 3600 ) ), $scheduler->runs );
		$this->assertSame( 0, $this->store->find( $id )->attempts );
	}

	/**
	 * Inside the window it is sent; transactional messages ignore the window.
	 */
	public function test_promotions_send_inside_the_window_and_transactional_ignores_it(): void {
		$this->consent->grant( self::PHONE, null, ConsentEvent::SOURCE_CHECKOUT, 1, 0, 'abc' );
		$this->clock->time = $this->sofia( '12:00' );
		$id                = $this->pending_promo();
		$gateway           = ( new FakeGateway() )->will_return( new ApiResult( Outcome::Success, 200, array( 'attributes' => array( 'credits-charged' => 1 ) ), null, null, null, 'api-1' ) );

		$this->dispatcher( $gateway, new RecordingScheduler(), $this->window() )->handle( $id );
		$this->assertSame( Status::Queued, $this->store->find( $id )->status );

		$transactional             = new Message();
		$transactional->recipient  = self::PHONE;
		$transactional->body       = 'Order shipped';
		$transactional->reference  = 'woo:ab:10:completed:1';
		$transactional->created_at = $this->clock->time;
		$tid                       = (int) $this->store->create( $transactional );

		$this->clock->time = $this->sofia( '23:00' );
		$gateway2          = ( new FakeGateway() )->will_return( new ApiResult( Outcome::Success, 200, array(), null, null, null, 'api-2' ) );
		$this->dispatcher( $gateway2, new RecordingScheduler(), $this->window() )->handle( $tid );
		$this->assertSame( Status::Queued, $this->store->find( $tid )->status, 'order messages are sent at any hour' );
	}

	/**
	 * Withdrawing between scheduling and sending stops the message.
	 */
	public function test_withdrawal_after_scheduling_blocks_the_send(): void {
		$this->consent->grant( self::PHONE, null, ConsentEvent::SOURCE_CHECKOUT, 1, 0, 'abc' );
		$this->clock->time = $this->sofia( '12:00' );
		$id                = $this->pending_promo();
		$this->consent->revoke( self::PHONE, ConsentEvent::SOURCE_OPTOUT );
		$gateway = new FakeGateway();

		$this->dispatcher( $gateway, new RecordingScheduler(), $this->window() )->handle( $id );

		$this->assertSame( array(), $gateway->sent );
		$this->assertSame( Status::Skipped, $this->store->find( $id )->status );
		$this->assertSame( SkipReason::NO_CONSENT, $this->store->find( $id )->skip_reason );
	}

	/**
	 * A message scheduled days ahead is not "too old" when it comes due.
	 */
	public function test_delayed_message_ages_from_its_due_time(): void {
		$this->consent->grant( self::PHONE, null, ConsentEvent::SOURCE_CHECKOUT, 1, 0, 'abc' );
		$this->clock->time   = $this->sofia( '12:00' );
		$id                  = $this->pending_promo();
		$message             = $this->store->find( $id );
		$message->send_after = $this->clock->time + 7 * 86400;
		$this->store->save( $message );

		$this->clock->time += 7 * 86400;
		$gateway            = ( new FakeGateway() )->will_return( new ApiResult( Outcome::Success, 200, array(), null, null, null, 'api-1' ) );

		$this->dispatcher( $gateway, new RecordingScheduler(), null )->handle( $id );

		$this->assertSame( Status::Queued, $this->store->find( $id )->status, 'created a week ago but only just due' );
	}

	// ---- FollowupHandler ----

	/**
	 * Build a handler.
	 *
	 * @param bool   $enabled Whether follow-ups are on.
	 * @param string $template Template.
	 * @param int    $days Days.
	 */
	private function followup( bool $enabled = true, string $template = 'Hi {first_name}, rate us: {shop_url}', int $days = 7 ): FollowupHandler {
		$state = new InMemoryState();

		return new FollowupHandler(
			$this->store,
			new Enqueuer( $this->store, new RecordingScheduler(), $this->clock ),
			new Normalizer(),
			$this->consent,
			new OptOutToken( 'secret' ),
			new StormGuard( $this->store, $state, $this->clock, 50, 600 ),
			$state,
			new RecordingNotifier(),
			$this->clock,
			'ab12cd34',
			new Rules(),
			new FollowupConfig( $enabled, $days, $template, 'Stop:' ),
			'https://shop.example.com/'
		);
	}

	/**
	 * Order facts.
	 *
	 * @param array<string,mixed> $overrides Overrides.
	 */
	private function facts( array $overrides = array() ): OrderFacts {
		$data = array_merge(
			array(
				'order_id' => 77,
				'phone'    => '0888 123 456',
			),
			$overrides
		);

		return new OrderFacts(
			$data['order_id'],
			'completed',
			self::NOW - 3600,
			'checkout',
			$data['phone'],
			'+359',
			array(
				'first_name' => 'Maria',
				'shop_url'   => 'https://shop.example.com',
			)
		);
	}

	/**
	 * A customer who agreed gets a delayed promotional message with an opt-out link.
	 */
	public function test_followup_is_scheduled_for_a_consenting_customer(): void {
		$this->consent->grant( self::PHONE, null, ConsentEvent::SOURCE_CHECKOUT, 77, 0, 'abc' );

		$this->assertSame( FollowupHandler::RESULT_QUEUED, $this->followup()->handle( $this->facts() ) );

		$message = $this->store->find( 1 );
		$this->assertSame( Kind::Marketing, $message->kind );
		$this->assertSame( 'followup_review', $message->event );
		$this->assertSame( self::NOW + 7 * 86400, $message->send_after );
		$this->assertStringContainsString( 'Hi Maria, rate us: https://shop.example.com Stop: https://shop.example.com/?po=', $message->body );
		$this->assertSame( $this->consent->hash( self::PHONE ), ( new OptOutToken( 'secret' ) )->verify( substr( $message->body, strpos( $message->body, '?po=' ) + 4 ) ) );
	}

	/**
	 * A template that already has the link keeps it in place and gets no second one.
	 */
	public function test_followup_does_not_duplicate_an_existing_optout_link(): void {
		$this->consent->grant( self::PHONE, null, ConsentEvent::SOURCE_CHECKOUT, 77, 0, 'abc' );

		$this->followup( true, 'Hi, unsubscribe here {optout_link}' )->handle( $this->facts() );

		$this->assertSame( 1, substr_count( $this->store->find( 1 )->body, '?po=' ) );
		$this->assertStringStartsWith( 'Hi, unsubscribe here https://shop.example.com/?po=', $this->store->find( 1 )->body );
	}

	/**
	 * No consent, no message: the decision is recorded as skipped.
	 */
	public function test_followup_without_consent_is_skipped(): void {
		$this->assertSame( FollowupHandler::RESULT_SKIPPED, $this->followup()->handle( $this->facts() ) );

		$this->assertSame( SkipReason::NO_CONSENT, $this->store->find( 1 )->skip_reason );
		$this->assertSame( Status::Skipped, $this->store->find( 1 )->status );
	}

	/**
	 * Disabled does nothing; the same order is only handled once.
	 */
	public function test_followup_disabled_and_duplicates(): void {
		$this->assertSame( FollowupHandler::RESULT_DISABLED, $this->followup( false )->handle( $this->facts() ) );
		$this->assertCount( 0, $this->store->rows );

		$this->consent->grant( self::PHONE, null, ConsentEvent::SOURCE_CHECKOUT, 77, 0, 'abc' );
		$handler = $this->followup();
		$handler->handle( $this->facts() );

		$this->assertSame( FollowupHandler::RESULT_DUPLICATE, $handler->handle( $this->facts() ) );
		$this->assertCount( 1, $this->store->rows );
	}

	/**
	 * The same phone rules as order messages apply.
	 */
	public function test_followup_applies_phone_rules(): void {
		$this->assertSame( FollowupHandler::RESULT_SKIPPED, $this->followup()->handle( $this->facts( array( 'phone' => '' ) ) ) );
		$this->assertSame( SkipReason::NO_PHONE, $this->store->find( 1 )->skip_reason );

		$this->assertSame(
			FollowupHandler::RESULT_SKIPPED,
			$this->followup()->handle(
				$this->facts(
					array(
						'order_id' => 78,
						'phone'    => '02 123 4567',
					)
				)
			)
		);
		$this->assertSame( SkipReason::NOT_MOBILE, $this->store->find( 2 )->skip_reason );
	}
}
