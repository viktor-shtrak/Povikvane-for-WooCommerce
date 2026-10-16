<?php
/**
 * Tests for the public opt-out form logic.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Povikvane\WooCommerce\Marketing\Consent;
use Povikvane\WooCommerce\Marketing\ConsentEvent;
use Povikvane\WooCommerce\Marketing\OptOutForm;
use Povikvane\WooCommerce\Phone\Normalizer;
use Povikvane\WooCommerce\Tests\Support\FixedClock;
use Povikvane\WooCommerce\Tests\Support\InMemoryLedger;

/**
 * Anyone can withdraw a number; nobody learns who is on the list.
 */
final class OptOutFormTest extends TestCase {

	private const PHONE = '+359888123456';

	private InMemoryLedger $ledger;
	private Consent $consent;
	private OptOutForm $form;

	/**
	 * Fresh doubles per test.
	 */
	protected function setUp(): void {
		$this->ledger  = new InMemoryLedger();
		$this->consent = new Consent( $this->ledger, new FixedClock( 1760000000 ), 'secret' );
		$this->form    = new OptOutForm( $this->consent, new Normalizer(), 'BG' );
	}

	/**
	 * A number that agreed is withdrawn, however it is spaced.
	 */
	public function test_a_valid_number_is_withdrawn(): void {
		$this->consent->grant( self::PHONE, null, ConsentEvent::SOURCE_CHECKOUT, 1, 0, 'w' );
		$this->assertTrue( $this->consent->has_consent( self::PHONE ) );

		$this->assertSame( OptOutForm::DONE, $this->form->process( '+359 888 123 456', '', false ) );
		$this->assertFalse( $this->consent->has_consent( self::PHONE ) );
		$this->assertSame( ConsentEvent::SOURCE_OPTOUT, $this->ledger->latest( $this->consent->hash( self::PHONE ) )?->source );
	}

	/**
	 * A number that never agreed gets the same answer, and is recorded as not to be contacted.
	 */
	public function test_unknown_number_gets_the_same_answer(): void {
		$this->assertSame( OptOutForm::DONE, $this->form->process( '+359 88 812 3456', '', false ) );
		$this->assertFalse( $this->consent->has_consent( self::PHONE ) );
		$this->assertNotNull( $this->ledger->latest( $this->consent->hash( self::PHONE ) ) );
	}

	/**
	 * Nonsense is refused and nothing is recorded.
	 */
	public function test_invalid_number_is_refused(): void {
		$this->assertSame( OptOutForm::INVALID, $this->form->process( 'abc', '', false ) );
		$this->assertSame( OptOutForm::INVALID, $this->form->process( '', '', false ) );
	}

	/**
	 * Robots and floods are stopped without a trace.
	 */
	public function test_robots_and_floods_are_stopped(): void {
		$this->consent->grant( self::PHONE, null, ConsentEvent::SOURCE_CHECKOUT, 1, 0, 'w' );

		$this->assertSame( OptOutForm::DONE, $this->form->process( '+359 888 123 456', 'http://spam', false ), 'the hidden field was filled' );
		$this->assertTrue( $this->consent->has_consent( self::PHONE ), 'nothing was recorded' );

		$this->assertSame( OptOutForm::WAIT, $this->form->process( '+359 888 123 456', '', true ) );
		$this->assertTrue( $this->consent->has_consent( self::PHONE ) );
	}
}
