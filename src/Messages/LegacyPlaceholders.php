<?php
/**
 * Removes placeholders the plugin no longer offers from saved texts.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Messages;

defined( 'ABSPATH' ) || exit;

/**
 * {first_name}, {last_name}, {order_status_url} and {tracking_number} were dropped
 * (Viber templates cannot use them, and texts that match a template send over Viber).
 * A saved text that still has one loses it, together with the comma in front, so
 * "Hi {first_name}, order" becomes "Hi, order" instead of "Hi , order".
 */
final class LegacyPlaceholders {

	/**
	 * Remove the dropped placeholders.
	 *
	 * @param string $text Saved text.
	 */
	public static function strip( string $text ): string {
		$clean = preg_replace( '/[,;:]?[ \t]*\{(?:first_name|last_name|order_status_url|tracking_number)\}/i', '', $text ) ?? $text;
		$clean = preg_replace( '/[ \t]{2,}/', ' ', $clean ) ?? $clean;

		return trim( $clean );
	}
}
