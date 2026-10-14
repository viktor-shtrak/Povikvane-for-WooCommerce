<?php
/**
 * Tests for the site fingerprint and the mass-send guard.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Povikvane\WooCommerce\Log\Message;
use Povikvane\WooCommerce\Messages\Status;
use Povikvane\WooCommerce\Safety\Fingerprint;
use Povikvane\WooCommerce\Safety\StormGuard;
use Povikvane\WooCommerce\Support\SendingState;
use Povikvane\WooCommerce\Tests\Support\FixedClock;
use Povikvane\WooCommerce\Tests\Support\InMemoryState;
use Povikvane\WooCommerce\Tests\Support\InMemoryStore;

/**
 * Safety guards.
 */
final class SafetyTest extends TestCase {

	/**
	 * Cosmetic differences in the address are not a different site.
	 */
	public function test_fingerprint_ignores_scheme_www_and_trailing_slash(): void {
		$base = Fingerprint::of( 'https://shop.example.com' );

		$this->assertSame( $base, Fingerprint::of( 'http://shop.example.com' ) );
		$this->assertSame( $base, Fingerprint::of( 'https://shop.example.com/' ) );
		$this->assertSame( $base, Fingerprint::of( 'HTTPS://Shop.Example.com' ) );
		$this->assertSame( Fingerprint::of( 'https://example.com' ), Fingerprint::of( 'https://www.example.com' ) );
	}

	/**
	 * A staging copy has a different fingerprint.
	 */
	public function test_fingerprint_differs_for_other_sites(): void {
		$live = Fingerprint::of( 'https://example.com' );

		$this->assertNotSame( $live, Fingerprint::of( 'https://staging.example.com' ) );
		$this->assertNotSame( $live, Fingerprint::of( 'https://example.com/shop' ) );
		$this->assertNotSame( $live, Fingerprint::of( 'http://localhost:10008' ) );
		$this->assertNotSame( Fingerprint::of( 'http://localhost:10008' ), Fingerprint::of( 'http://localhost:10009' ) );
	}

	/**
	 * Fingerprints are short and stable.
	 */
	public function test_fingerprint_is_a_stable_short_hash(): void {
		$this->assertSame( 16, strlen( Fingerprint::of( 'https://example.com' ) ) );
		$this->assertSame( Fingerprint::of( 'https://example.com' ), Fingerprint::of( 'https://example.com' ) );
	}

	/**
	 * Add messages to the store.
	 *
	 * @param InMemoryStore $store      Store.
	 * @param int           $count      How many.
	 * @param int           $created_at Creation time.
	 * @param Status        $status     Status.
	 */
	private function fill( InMemoryStore $store, int $count, int $created_at, Status $status = Status::Pending ): void {
		for ( $i = 0; $i < $count; $i++ ) {
			$message             = new Message();
			$message->reference  = 'woo:ab:' . $created_at . ':' . $i . ':x:1';
			$message->created_at = $created_at;
			$message->status     = $status;
			$store->create( $message );
		}
	}

	/**
	 * Below the threshold nothing happens; at it, sending pauses.
	 */
	public function test_storm_guard_pauses_when_window_is_full(): void {
		$store = new InMemoryStore();
		$state = new InMemoryState();
		$clock = new FixedClock( 1760000000 );
		$guard = new StormGuard( $store, $state, $clock, 3, 600 );

		$this->fill( $store, 2, 1760000000 - 60 );
		$guard->check();
		$this->assertNull( $state->paused_reason() );

		$this->fill( $store, 1, 1760000000 - 30 );
		$guard->check();
		$this->assertSame( SendingState::REASON_STORM, $state->paused_reason() );
	}

	/**
	 * Old messages and skipped ones do not count.
	 */
	public function test_storm_guard_ignores_old_and_skipped_messages(): void {
		$store = new InMemoryStore();
		$state = new InMemoryState();
		$guard = new StormGuard( $store, $state, new FixedClock( 1760000000 ), 3, 600 );

		$this->fill( $store, 5, 1760000000 - 3600 );
		$this->fill( $store, 5, 1760000000 - 10, Status::Skipped );
		$guard->check();

		$this->assertNull( $state->paused_reason() );
	}

	/**
	 * A threshold of zero turns the guard off, and an existing pause is never overwritten.
	 */
	public function test_storm_guard_can_be_disabled_and_keeps_existing_pause(): void {
		$store = new InMemoryStore();
		$this->fill( $store, 10, 1760000000 - 10 );

		$off = new InMemoryState();
		( new StormGuard( $store, $off, new FixedClock( 1760000000 ), 0, 600 ) )->check();
		$this->assertNull( $off->paused_reason() );

		$credits         = new InMemoryState();
		$credits->reason = SendingState::REASON_CREDITS;
		( new StormGuard( $store, $credits, new FixedClock( 1760000000 ), 3, 600 ) )->check();
		$this->assertSame( SendingState::REASON_CREDITS, $credits->paused_reason() );
	}
}
