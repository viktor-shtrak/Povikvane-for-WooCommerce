<?php
/**
 * SMS encoding and segment counting.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Messages;

defined( 'ABSPATH' ) || exit;

/**
 * Mirrors the platform's credit rules: GSM-7 is 160/153 units per segment, UCS-2
 * is 70/67. The GSM-7 extension characters count as two units, and a single
 * character outside GSM-7 (for example one Cyrillic letter) switches the whole
 * message to UCS-2.
 */
final class SegmentCounter {

	/**
	 * GSM 03.38 basic character set (without the escape code).
	 */
	private const GSM_BASIC = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";

	/**
	 * GSM 03.38 extension table (each costs two units).
	 */
	private const GSM_EXTENDED = "^{}\\[~]|€\f";

	/**
	 * Analyse a message body.
	 *
	 * @param string $text Message text (UTF-8).
	 */
	public function analyse( string $text ): SegmentInfo {
		$chars = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );

		if ( false === $chars ) {
			// Not valid UTF-8: count bytes under UCS-2 rules, which is the conservative choice.
			return $this->ucs2( strlen( $text ) );
		}

		if ( array() === $chars ) {
			return new SegmentInfo( 'gsm7', 0, 0, 153, 160 );
		}

		$gsm_units = 0;
		foreach ( $chars as $char ) {
			if ( str_contains( self::GSM_BASIC, $char ) ) {
				++$gsm_units;
			} elseif ( str_contains( self::GSM_EXTENDED, $char ) ) {
				$gsm_units += 2;
			} else {
				return $this->ucs2( $this->utf16_units( $chars ) );
			}
		}

		$segments = $gsm_units <= 160 ? 1 : (int) ceil( $gsm_units / 153 );

		return new SegmentInfo( 'gsm7', $gsm_units, $segments, 153, 160 );
	}

	/**
	 * Build a UCS-2 result.
	 *
	 * @param int $units Length in UTF-16 code units.
	 */
	private function ucs2( int $units ): SegmentInfo {
		$segments = $units <= 70 ? 1 : (int) ceil( $units / 67 );

		return new SegmentInfo( 'ucs2', $units, $segments, 67, 70 );
	}

	/**
	 * Count UTF-16 code units: characters outside the BMP take two.
	 *
	 * @param string[] $chars Individual UTF-8 characters.
	 */
	private function utf16_units( array $chars ): int {
		$units = 0;
		foreach ( $chars as $char ) {
			$units += strlen( $char ) >= 4 ? 2 : 1;
		}

		return $units;
	}
}
