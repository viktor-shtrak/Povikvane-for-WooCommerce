<?php
/**
 * Tests for reference/idempotency key derivation.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Povikvane\WooCommerce\Messages\Reference;

/**
 * Reference derivation.
 */
final class ReferenceTest extends TestCase {

	/**
	 * Standard event reference.
	 */
	public function test_event_reference_format(): void {
		$this->assertSame( 'woo:ab12cd34:1234:processing:1', Reference::for_event( 'ab12cd34', 1234, 'processing' ) );
		$this->assertSame( 'woo:ab12cd34:1234:processing:2', Reference::for_event( 'ab12cd34', 1234, 'processing', 2 ) );
	}

	/**
	 * Event names are reduced to safe characters.
	 */
	public function test_event_is_sanitised(): void {
		$this->assertSame( 'woo:ab12cd34:7:followup-review:1', Reference::for_event( 'ab12cd34', 7, 'Followup Review' ) );
		$this->assertSame( 'woo:ab12cd34:7:wc-on-hold:1', Reference::for_event( 'ab12cd34', 7, 'wc/on hold' ) );
	}

	/**
	 * The attempt number never drops below one.
	 */
	public function test_attempt_is_at_least_one(): void {
		$this->assertSame( 'woo:ab12cd34:7:completed:1', Reference::for_event( 'ab12cd34', 7, 'completed', 0 ) );
	}

	/**
	 * Test message reference.
	 */
	public function test_test_reference(): void {
		$this->assertSame( 'woo:ab12cd34:test:1760000000:1', Reference::for_test( 'ab12cd34', 1760000000 ) );
	}

	/**
	 * Generated references satisfy the validity rule, including the idempotency-key charset.
	 */
	public function test_generated_references_are_valid(): void {
		$this->assertTrue( Reference::is_valid( Reference::for_event( 'ab12cd34', 99, 'on-hold' ) ) );
		$this->assertTrue( Reference::is_valid( Reference::for_test( 'ab12cd34', 1760000000 ) ) );
	}

	/**
	 * Validity rules.
	 */
	public function test_validity_rules(): void {
		$this->assertFalse( Reference::is_valid( '' ) );
		$this->assertFalse( Reference::is_valid( 'has space' ) );
		$this->assertFalse( Reference::is_valid( 'has/slash' ), 'slash is allowed in references but not idempotency keys' );
		$this->assertFalse( Reference::is_valid( str_repeat( 'a', 129 ) ) );
		$this->assertTrue( Reference::is_valid( str_repeat( 'a', 128 ) ) );
	}
}
