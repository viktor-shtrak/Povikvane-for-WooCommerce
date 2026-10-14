<?php
/**
 * Template placeholders.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Messages;

defined( 'ABSPATH' ) || exit;

/**
 * Replaces {placeholders} in message templates. Unresolved placeholders are
 * removed rather than sent as literal text.
 */
final class Placeholders {

	/**
	 * Placeholders offered in the template editor.
	 */
	public const NAMES = array(
		'order_number',
		'order_total',
		'first_name',
		'last_name',
		'shop_name',
		'order_status_url',
		'tracking_number',
		'tracking_url',
	);

	/**
	 * Fill a template.
	 *
	 * @param string               $template Template text.
	 * @param array<string,string> $values   Values keyed by placeholder name.
	 */
	public static function render( string $template, array $values ): string {
		$text = preg_replace_callback(
			'/\{([A-Za-z_]+)\}/',
			static fn( array $found ): string => self::clean( $values[ strtolower( $found[1] ) ] ?? '' ),
			$template
		) ?? $template;

		$text = preg_replace( '/[ \t]{2,}/', ' ', $text ) ?? $text;

		return trim( $text );
	}

	/**
	 * Placeholder names in a template that are not supported.
	 *
	 * @param string $template Template text.
	 * @return string[]
	 */
	public static function unknown( string $template ): array {
		preg_match_all( '/\{([A-Za-z_]+)\}/', $template, $matches );

		$unknown = array();
		foreach ( $matches[1] as $name ) {
			if ( ! in_array( strtolower( $name ), self::NAMES, true ) ) {
				$unknown[ $name ] = $name;
			}
		}

		return array_values( $unknown );
	}

	/**
	 * Keep a value on one line without control characters.
	 *
	 * @param string $value Raw value.
	 */
	private static function clean( string $value ): string {
		$value = preg_replace( '/[\r\n\t]+/', ' ', $value ) ?? $value;
		$value = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value ) ?? $value;

		return trim( $value );
	}
}
