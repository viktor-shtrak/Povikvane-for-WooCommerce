<?php
/**
 * Date formatting.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Formats timestamps in the site's time zone, always returning text.
 */
final class Dates {

	/**
	 * Format a Unix time for display.
	 *
	 * @param int    $timestamp Unix time.
	 * @param string $format    PHP date format.
	 */
	public static function format( int $timestamp, string $format = 'Y-m-d H:i' ): string {
		$text = wp_date( $format, $timestamp );

		return false === $text ? gmdate( $format, $timestamp ) : $text;
	}
}
