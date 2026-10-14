<?php
/**
 * Tests for SMS segment counting.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Povikvane\WooCommerce\Messages\SegmentCounter;

/**
 * Segment counting.
 */
final class SegmentCounterTest extends TestCase {

	/**
	 * Cases: text, encoding, units, segments.
	 *
	 * @return array<string, array{string, string, int, int}>
	 */
	public static function cases(): array {
		return array(
			'empty'               => array( '', 'gsm7', 0, 0 ),
			'short latin'         => array( 'Hello', 'gsm7', 5, 1 ),
			'160 latin fits'      => array( str_repeat( 'a', 160 ), 'gsm7', 160, 1 ),
			'161 latin splits'    => array( str_repeat( 'a', 161 ), 'gsm7', 161, 2 ),
			'306 latin is 2'      => array( str_repeat( 'a', 306 ), 'gsm7', 306, 2 ),
			'307 latin is 3'      => array( str_repeat( 'a', 307 ), 'gsm7', 307, 3 ),
			'euro counts double'  => array( str_repeat( '€', 80 ), 'gsm7', 160, 1 ),
			'euro over the limit' => array( str_repeat( '€', 81 ), 'gsm7', 162, 2 ),
			'brace counts double' => array( '{a}', 'gsm7', 5, 1 ),
			'newline is gsm7'     => array( "Hi\nthere", 'gsm7', 8, 1 ),
			'cyrillic is ucs2'    => array( 'Здравей', 'ucs2', 7, 1 ),
			'70 cyrillic fits'    => array( str_repeat( 'я', 70 ), 'ucs2', 70, 1 ),
			'71 cyrillic splits'  => array( str_repeat( 'я', 71 ), 'ucs2', 71, 2 ),
			'134 cyrillic is 2'   => array( str_repeat( 'я', 134 ), 'ucs2', 134, 2 ),
			'135 cyrillic is 3'   => array( str_repeat( 'я', 135 ), 'ucs2', 135, 3 ),
			'one cyrillic letter' => array( 'Hello Иван', 'ucs2', 10, 1 ),
			'emoji is two units'  => array( '😀', 'ucs2', 2, 1 ),
			'accent outside gsm7' => array( 'ő', 'ucs2', 1, 1 ),
			'accent inside gsm7'  => array( 'é', 'gsm7', 1, 1 ),
		);
	}

	/**
	 * Check one case.
	 *
	 * @param string $text     Body.
	 * @param string $encoding Expected encoding.
	 * @param int    $units    Expected units.
	 * @param int    $segments Expected segments.
	 */
	#[DataProvider( 'cases' )]
	public function test_counts_segments( string $text, string $encoding, int $units, int $segments ): void {
		$info = ( new SegmentCounter() )->analyse( $text );

		$this->assertSame( $encoding, $info->encoding );
		$this->assertSame( $units, $info->units );
		$this->assertSame( $segments, $info->segments );
	}

	/**
	 * Invalid UTF-8 never counts as the cheap encoding.
	 */
	public function test_invalid_utf8_is_treated_as_ucs2(): void {
		$info = ( new SegmentCounter() )->analyse( "abc\xFF" );

		$this->assertSame( 'ucs2', $info->encoding );
	}
}
