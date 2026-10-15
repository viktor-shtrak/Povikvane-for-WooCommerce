<?php
/**
 * Tests for the on-demand delivery check and the dismissed-notice rule.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Povikvane\WooCommerce\Api\ApiResult;
use Povikvane\WooCommerce\Api\Outcome;
use Povikvane\WooCommerce\Log\Message;
use Povikvane\WooCommerce\Messages\DeliveryUpdater;
use Povikvane\WooCommerce\Messages\Reconciler;
use Povikvane\WooCommerce\Messages\Status;
use Povikvane\WooCommerce\Support\Dismissal;
use Povikvane\WooCommerce\Support\SendingState;
use Povikvane\WooCommerce\Tests\Support\FakeGateway;
use Povikvane\WooCommerce\Tests\Support\FixedClock;
use Povikvane\WooCommerce\Tests\Support\InMemoryState;
use Povikvane\WooCommerce\Tests\Support\InMemoryStore;
use Povikvane\WooCommerce\Tests\Support\RecordingNotifier;

/**
 * Watching a message must be cheap and must never disturb its regular checks.
 */
final class LiveStatusTest extends TestCase {

	private const NOW = 1760000000;

	/**
	 * Build a reconciler and a stored message.
	 *
	 * @param FakeGateway   $gateway Gateway.
	 * @param InMemoryStore $store   Store.
	 * @param Status        $status  Message status.
	 * @param InMemoryState $state   Pause state.
	 */
	private function setup_check( FakeGateway $gateway, InMemoryStore $store, Status $status = Status::Queued, ?InMemoryState $state = null ): Reconciler {
		$clock = new FixedClock( self::NOW );

		$message                  = new Message();
		$message->order_id        = 1;
		$message->event           = 'test';
		$message->recipient       = '+359888123456';
		$message->body            = 'Hi';
		$message->reference       = 'woo:ab12cd34:test:1:1';
		$message->status          = $status;
		$message->api_message_id  = 'api-1';
		$message->next_attempt_at = self::NOW + 300;
		$message->created_at      = self::NOW - 60;
		$message->updated_at      = self::NOW - 60;
		$store->create( $message );

		return new Reconciler( $store, $gateway, new DeliveryUpdater( $store, new RecordingNotifier(), $clock ), $state ?? new InMemoryState(), $clock );
	}

	/**
	 * A delivery is picked up straight away.
	 */
	public function test_check_now_records_a_delivery(): void {
		$gateway = ( new FakeGateway() )->will_return_for( 'status', new ApiResult( Outcome::Success, 200, array( 'attributes' => array( 'status' => 'delivered_to_handset' ) ) ) );
		$store   = new InMemoryStore();
		$check   = $this->setup_check( $gateway, $store );

		$message = $store->find( 1 );
		$this->assertNotNull( $message );
		$this->assertTrue( $check->check_now( $message ) );
		$this->assertSame( Status::Delivered, $store->find( 1 )?->status );
	}

	/**
	 * An answer that settles nothing leaves the regular schedule alone.
	 */
	public function test_check_now_without_an_answer_changes_nothing(): void {
		foreach ( array(
			new ApiResult( Outcome::Success, 200, array( 'attributes' => array( 'status' => 'queued_on_smsc' ) ) ),
			new ApiResult( Outcome::Retryable, 0 ),
			new ApiResult( Outcome::RateLimited, 429 ),
		) as $result ) {
			$gateway = ( new FakeGateway() )->will_return_for( 'status', $result );
			$store   = new InMemoryStore();
			$check   = $this->setup_check( $gateway, $store );

			$message = $store->find( 1 );
			$this->assertNotNull( $message );
			$this->assertFalse( $check->check_now( $message ) );

			$stored = $store->find( 1 );
			$this->assertSame( Status::Queued, $stored?->status );
			$this->assertSame( 0, $stored?->checks );
			$this->assertSame( self::NOW + 300, $stored?->next_attempt_at );
		}
	}

	/**
	 * Only accepted messages with an ID are checked, and not while the key is rejected.
	 */
	public function test_check_now_skips_other_messages(): void {
		$gateway = new FakeGateway();
		$store   = new InMemoryStore();
		$check   = $this->setup_check( $gateway, $store, Status::Delivered );

		$message = $store->find( 1 );
		$this->assertNotNull( $message );
		$this->assertFalse( $check->check_now( $message ) );

		$state = new InMemoryState();
		$state->pause( SendingState::REASON_AUTH );
		$store   = new InMemoryStore();
		$check   = $this->setup_check( $gateway, $store, Status::Queued, $state );
		$message = $store->find( 1 );
		$this->assertNotNull( $message );
		$this->assertFalse( $check->check_now( $message ) );

		$this->assertSame( array(), $gateway->calls );
	}

	/**
	 * A dismissed warning stays away for a week, then returns.
	 */
	public function test_dismissal_expires(): void {
		$this->assertFalse( Dismissal::hides( '', self::NOW ) );
		$this->assertFalse( Dismissal::hides( 0, self::NOW ) );
		$this->assertTrue( Dismissal::hides( (string) ( self::NOW - 3600 ), self::NOW ) );
		$this->assertTrue( Dismissal::hides( self::NOW - 6 * 86400, self::NOW ) );
		$this->assertFalse( Dismissal::hides( self::NOW - 8 * 86400, self::NOW ) );
	}
}
