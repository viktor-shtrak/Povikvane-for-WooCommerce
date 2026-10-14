<?php
/**
 * Tests for enqueuing messages.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Povikvane\WooCommerce\Log\Message;
use Povikvane\WooCommerce\Messages\Enqueuer;
use Povikvane\WooCommerce\Messages\Status;
use Povikvane\WooCommerce\Tests\Support\FixedClock;
use Povikvane\WooCommerce\Tests\Support\InMemoryStore;
use Povikvane\WooCommerce\Tests\Support\RecordingScheduler;

/**
 * Duplicate protection and scheduling.
 */
final class EnqueuerTest extends TestCase {

	/**
	 * Build a prepared message.
	 *
	 * @param string $reference Reference.
	 */
	private function message( string $reference ): Message {
		$message            = new Message();
		$message->recipient = '+359888123456';
		$message->body      = 'Hello';
		$message->reference = $reference;
		$message->status    = Status::Failed; // Must be reset to pending by the enqueuer.

		return $message;
	}

	/**
	 * A new message is stored as pending and scheduled once.
	 */
	public function test_stores_and_schedules(): void {
		$store     = new InMemoryStore();
		$scheduler = new RecordingScheduler();
		$clock     = new FixedClock( 1760000000 );

		$id = ( new Enqueuer( $store, $scheduler, $clock ) )->enqueue( $this->message( 'woo:ab:1:processing:1' ) );

		$this->assertSame( 1, $id );
		$this->assertSame( array( array( 1, 0 ) ), $scheduler->runs );
		$stored = $store->find( 1 );
		$this->assertSame( Status::Pending, $stored->status );
		$this->assertSame( 1760000000, $stored->created_at );
	}

	/**
	 * The same reference can never create a second message.
	 */
	public function test_duplicate_reference_is_refused(): void {
		$store     = new InMemoryStore();
		$scheduler = new RecordingScheduler();
		$enqueuer  = new Enqueuer( $store, $scheduler, new FixedClock() );

		$first  = $enqueuer->enqueue( $this->message( 'woo:ab:1:processing:1' ) );
		$second = $enqueuer->enqueue( $this->message( 'woo:ab:1:processing:1' ) );

		$this->assertSame( 1, $first );
		$this->assertNull( $second );
		$this->assertCount( 1, $scheduler->runs, 'no second job for a duplicate' );
		$this->assertCount( 1, $store->rows );
	}

	/**
	 * A skipped message is logged as final, never scheduled, and still uses up its reference.
	 */
	public function test_record_skipped_logs_without_scheduling(): void {
		$store     = new InMemoryStore();
		$scheduler = new RecordingScheduler();
		$enqueuer  = new Enqueuer( $store, $scheduler, new FixedClock( 1760000000 ) );

		$id = $enqueuer->record_skipped( $this->message( 'woo:ab:1:processing:1' ), 'no_phone' );

		$this->assertSame( 1, $id );
		$stored = $store->find( 1 );
		$this->assertSame( Status::Skipped, $stored->status );
		$this->assertSame( 'no_phone', $stored->skip_reason );
		$this->assertSame( 1760000000, $stored->final_at );
		$this->assertSame( array(), $scheduler->runs );
		$this->assertNull( $enqueuer->enqueue( $this->message( 'woo:ab:1:processing:1' ) ), 'reference is used up' );
	}

	/**
	 * A delay is passed through to the scheduler.
	 */
	public function test_delay_reaches_the_scheduler(): void {
		$scheduler = new RecordingScheduler();

		( new Enqueuer( new InMemoryStore(), $scheduler, new FixedClock() ) )->enqueue( $this->message( 'woo:ab:2:completed:1' ), 600 );

		$this->assertSame( array( array( 1, 600 ) ), $scheduler->runs );
	}
}
