<?php
/**
 * Tests for phone normalisation.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Povikvane\WooCommerce\Phone\Normalizer;

/**
 * Phone normalisation.
 */
final class NormalizerTest extends TestCase {

	/**
	 * Valid inputs and the E.164 they should produce.
	 *
	 * @return array<string, array{string, string, string}>
	 */
	public static function valid_numbers(): array {
		return array(
			'national with trunk zero'    => array( '0888 123 456', '+359', '+359888123456' ),
			'international with plus'     => array( '+359 888 123 456', '+359', '+359888123456' ),
			'double zero prefix'          => array( '00359888123456', '+359', '+359888123456' ),
			'country code without plus'   => array( '359888123456', '359', '+359888123456' ),
			'national without trunk zero' => array( '888123456', '+359', '+359888123456' ),
			'trunk zero in parentheses'   => array( '+359 (0) 888 123 456', '+359', '+359888123456' ),
			'dashes and dots'             => array( '0888-123.456', '+359', '+359888123456' ),
			'foreign number overrides'    => array( '+44 7911 123456', '+359', '+447911123456' ),
			'us national'                 => array( '(555) 123-4567', '+1', '+15551234567' ),
			'us with leading one'         => array( '1 555 123 4567', '+1', '+15551234567' ),
		);
	}

	/**
	 * A valid number is normalised.
	 *
	 * @param string $raw      Input.
	 * @param string $code     Calling code.
	 * @param string $expected Expected E.164.
	 */
	#[DataProvider( 'valid_numbers' )]
	public function test_normalizes_valid_numbers( string $raw, string $code, string $expected ): void {
		$result = ( new Normalizer() )->normalize( $raw, $code );

		$this->assertTrue( $result->is_valid() );
		$this->assertSame( $expected, $result->e164 );
		$this->assertNull( $result->reason );
	}

	/**
	 * Invalid inputs and the reason expected.
	 *
	 * @return array<string, array{string, string, string}>
	 */
	public static function invalid_numbers(): array {
		return array(
			'empty'                => array( '', '+359', 'empty' ),
			'whitespace only'      => array( '   ', '+359', 'empty' ),
			'letters only'         => array( 'abc', '+359', 'invalid' ),
			'too short'            => array( '123', '+359', 'invalid' ),
			'too long'             => array( '+3598881234567890123', '+359', 'invalid' ),
			'national, no country' => array( '0888123456', '', 'no_country' ),
			'plus then zero'       => array( '+0888123456', '+359', 'invalid' ),
		);
	}

	/**
	 * An invalid number yields a reason and no E.164.
	 *
	 * @param string $raw    Input.
	 * @param string $code   Calling code.
	 * @param string $reason Expected reason.
	 */
	#[DataProvider( 'invalid_numbers' )]
	public function test_rejects_invalid_numbers( string $raw, string $code, string $reason ): void {
		$result = ( new Normalizer() )->normalize( $raw, $code );

		$this->assertFalse( $result->is_valid() );
		$this->assertNull( $result->e164 );
		$this->assertSame( $reason, $result->reason );
	}

	/**
	 * Bulgarian mobile detection.
	 */
	public function test_bulgarian_mobile_detection(): void {
		$normalizer = new Normalizer();

		$this->assertTrue( $normalizer->is_mobile( '+359888123456' ) );
		$this->assertTrue( $normalizer->is_mobile( '+359878123456' ) );
		$this->assertTrue( $normalizer->is_mobile( '+359988123456' ) );
		$this->assertFalse( $normalizer->is_mobile( '+35921234567' ), 'Sofia landline' );
		$this->assertFalse( $normalizer->is_mobile( '+35988812345' ), 'too short' );
		$this->assertNull( $normalizer->is_mobile( '+447911123456' ), 'country not checked' );
	}
}
