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
 * Segment counting. The cases live in a JSON file shared with the JavaScript
 * counter used by the template editor, so the two cannot disagree.
 */
final class SegmentCounterTest extends TestCase {

	/**
	 * Cases from the shared fixture file.
	 *
	 * @return array<string, array{string, string, int, int}>
	 */
	public static function cases(): array {
		$json  = file_get_contents( dirname( __DIR__ ) . '/fixtures/segment-cases.json' );
		$cases = json_decode( (string) $json, true );
		$out   = array();

		foreach ( $cases as $case ) {
			$text = isset( $case['repeat'] ) ? str_repeat( $case['repeat'], $case['times'] ) : $case['text'];

			$out[ $case['name'] ] = array( $text, $case['encoding'], $case['units'], $case['segments'] );
		}

		return $out;
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
