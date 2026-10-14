<?php
/**
 * Masking helpers for personal data in logs and screens.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Hides most of a phone number.
 */
final class Mask {

	/**
	 * Keep the country prefix and the last three digits.
	 *
	 * @param string $e164 Number including "+".
	 */
	public static function phone( string $e164 ): string {
		return strlen( $e164 ) > 8 ? substr( $e164, 0, 4 ) . '…' . substr( $e164, -3 ) : $e164;
	}
}
