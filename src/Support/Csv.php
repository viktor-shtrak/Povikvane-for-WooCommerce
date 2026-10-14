<?php
/**
 * CSV output helpers.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Builds CSV lines that are safe to open in a spreadsheet. A cell starting with
 * =, +, - or @ would be run as a formula, so it is prefixed with an apostrophe.
 */
final class Csv {

	/**
	 * One quoted, safe cell.
	 *
	 * @param string|int|float|null $value Cell value.
	 */
	public static function cell( string|int|float|null $value ): string {
		$text = (string) $value;

		if ( '' !== $text && in_array( $text[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) && ! is_numeric( $text ) ) {
			$text = "'" . $text;
		}

		return '"' . str_replace( '"', '""', $text ) . '"';
	}

	/**
	 * One CSV line, without the line ending.
	 *
	 * @param array<int,string|int|float|null> $cells Cell values.
	 */
	public static function row( array $cells ): string {
		return implode( ',', array_map( array( self::class, 'cell' ), $cells ) );
	}
}
