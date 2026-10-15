<?php
/**
 * Connects Viber template variables to order values.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Viber;

defined( 'ABSPATH' ) || exit;

/**
 * A Viber template can only contain firstName, lastName and customField1 to
 * customField3. The first two are filled from the order automatically; each
 * customField is whatever the shop chooses for it (for example the order number),
 * so the shop picks the value per order status. A template whose variables are not
 * all covered is not used.
 */
final class VariableMapper {

	/**
	 * Placeholders whose value is a link. Povikvane bills a Viber message with a link
	 * in a variable as promotional, so such a value is never sent as an order message.
	 */
	public const LINK_PLACEHOLDERS = array( 'order_status_url', 'tracking_url', 'shop_url', 'optout_link' );

	/**
	 * Variables filled without asking.
	 */
	private const AUTOMATIC = array(
		'firstName' => 'first_name',
		'lastName'  => 'last_name',
	);

	/**
	 * Placeholder that fills a variable, from the automatic rules or the shop's choice.
	 *
	 * @param string               $variable     Template variable name.
	 * @param array<string,string> $chosen       Shop's choices, variable => placeholder.
	 * @param string[]             $placeholders Supported placeholder names.
	 */
	public static function placeholder_for( string $variable, array $chosen, array $placeholders ): ?string {
		$placeholder = self::AUTOMATIC[ $variable ] ?? ( $chosen[ $variable ] ?? '' );

		return '' !== $placeholder && in_array( $placeholder, $placeholders, true ) ? $placeholder : null;
	}

	/**
	 * Map every variable of a template.
	 *
	 * @param string[]             $variables    Template variables.
	 * @param array<string,string> $chosen       Shop's choices.
	 * @param string[]             $placeholders Supported placeholder names.
	 * @return array<string,string>|null Variable => placeholder, or null if any cannot be filled.
	 */
	public static function map( array $variables, array $chosen, array $placeholders ): ?array {
		$map = array();

		foreach ( $variables as $variable ) {
			$placeholder = self::placeholder_for( $variable, $chosen, $placeholders );

			if ( null === $placeholder ) {
				return null;
			}

			$map[ $variable ] = $placeholder;
		}

		return $map;
	}

	/**
	 * Variables the shop still has to choose a value for.
	 *
	 * @param string[]             $variables    Template variables.
	 * @param array<string,string> $chosen       Shop's choices.
	 * @param string[]             $placeholders Supported placeholder names.
	 * @return string[]
	 */
	public static function unmapped( array $variables, array $chosen, array $placeholders ): array {
		return array_values( array_filter( $variables, static fn( string $v ): bool => null === self::placeholder_for( $v, $chosen, $placeholders ) ) );
	}

	/**
	 * Variables the shop has to choose a value for (everything but the automatic ones).
	 *
	 * @param string[] $variables Template variables.
	 * @return string[]
	 */
	public static function needs_choice( array $variables ): array {
		return array_values( array_filter( $variables, static fn( string $v ): bool => ! isset( self::AUTOMATIC[ $v ] ) ) );
	}

	/**
	 * Placeholders offered for a variable: values that are not links.
	 *
	 * @param string[] $placeholders Supported placeholder names.
	 * @return string[]
	 */
	public static function choices( array $placeholders ): array {
		return array_values( array_diff( $placeholders, self::LINK_PLACEHOLDERS ) );
	}
}
