<?php
/**
 * Connects template variables to the plugin's placeholders.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Viber;

defined( 'ABSPATH' ) || exit;

/**
 * A template variable such as "firstName" is matched to a placeholder such as
 * {first_name} by name, ignoring case and separators, plus a few obvious aliases.
 * A template with a variable we cannot fill is not used.
 */
final class VariableMapper {

	/**
	 * Placeholders whose value is a link. Povikvane bills a Viber message with a link
	 * as promotional, so a template using one is not sent as an order message.
	 */
	public const LINK_PLACEHOLDERS = array( 'order_status_url', 'tracking_url', 'shop_url', 'optout_link' );

	private const ALIASES = array(
		'name'         => 'first_name',
		'customer'     => 'first_name',
		'order'        => 'order_number',
		'orderid'      => 'order_number',
		'total'        => 'order_total',
		'shop'         => 'shop_name',
		'store'        => 'shop_name',
		'storename'    => 'shop_name',
		'trackingcode' => 'tracking_number',
		'trackinglink' => 'tracking_url',
		'orderlink'    => 'order_status_url',
		'orderurl'     => 'order_status_url',
	);

	/**
	 * Placeholder for a variable, or null.
	 *
	 * @param string   $variable     Template variable name.
	 * @param string[] $placeholders Supported placeholder names.
	 */
	public static function placeholder_for( string $variable, array $placeholders ): ?string {
		$key = self::normalise( $variable );

		foreach ( $placeholders as $name ) {
			if ( self::normalise( $name ) === $key ) {
				return $name;
			}
		}

		return self::ALIASES[ $key ] ?? null;
	}

	/**
	 * Map every variable of a template.
	 *
	 * @param string[] $variables    Template variables.
	 * @param string[] $placeholders Supported placeholder names.
	 * @return array<string,string>|null Variable => placeholder, or null if any cannot be filled.
	 */
	public static function map( array $variables, array $placeholders ): ?array {
		$map = array();

		foreach ( $variables as $variable ) {
			$placeholder = self::placeholder_for( $variable, $placeholders );

			if ( null === $placeholder ) {
				return null;
			}

			$map[ $variable ] = $placeholder;
		}

		return $map;
	}

	/**
	 * Variables that cannot be filled.
	 *
	 * @param string[] $variables    Template variables.
	 * @param string[] $placeholders Supported placeholder names.
	 * @return string[]
	 */
	public static function unmapped( array $variables, array $placeholders ): array {
		return array_values( array_filter( $variables, static fn( string $v ): bool => null === self::placeholder_for( $v, $placeholders ) ) );
	}

	/**
	 * Lower-case letters and digits only.
	 *
	 * @param string $name Name.
	 */
	private static function normalise( string $name ): string {
		return strtolower( (string) preg_replace( '/[^A-Za-z0-9]/', '', $name ) );
	}
}
