<?php
/**
 * Tests for credential encryption.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Povikvane\WooCommerce\Support\Crypto;

/**
 * Crypto round trips and failure modes.
 */
final class CryptoTest extends TestCase {

	/**
	 * Round trip.
	 */
	public function test_round_trip(): void {
		$crypto = new Crypto( 'salt-material' );

		$encrypted = $crypto->encrypt( 'secret-api-key' );

		$this->assertStringStartsWith( 'v1:', $encrypted );
		$this->assertStringNotContainsString( 'secret-api-key', $encrypted );
		$this->assertSame( 'secret-api-key', $crypto->decrypt( $encrypted ) );
	}

	/**
	 * Same input encrypts differently each time.
	 */
	public function test_ciphertext_is_randomised(): void {
		$crypto = new Crypto( 'salt-material' );

		$this->assertNotSame( $crypto->encrypt( 'x' ), $crypto->encrypt( 'x' ) );
	}

	/**
	 * Changing the key material (for example rotated salts) makes values unreadable, not wrong.
	 */
	public function test_wrong_key_returns_null(): void {
		$encrypted = ( new Crypto( 'old-salt' ) )->encrypt( 'secret' );

		$this->assertNull( ( new Crypto( 'new-salt' ) )->decrypt( $encrypted ) );
	}

	/**
	 * Tampered or malformed input returns null.
	 */
	public function test_tampered_or_malformed_input_returns_null(): void {
		$crypto    = new Crypto( 'salt-material' );
		$encrypted = $crypto->encrypt( 'secret' );
		$tampered  = substr( $encrypted, 0, -4 ) . 'AAAA';

		$this->assertNull( $crypto->decrypt( $tampered ) );
		$this->assertNull( $crypto->decrypt( 'not-encrypted' ) );
		$this->assertNull( $crypto->decrypt( 'v1:' ) );
		$this->assertNull( $crypto->decrypt( 'v1:%%%%' ) );
	}
}
