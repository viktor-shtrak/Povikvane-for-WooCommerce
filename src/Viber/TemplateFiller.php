<?php
/**
 * Turns an approved Viber template back into a message text.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Viber;

defined( 'ABSPATH' ) || exit;

/**
 * A template says "Order {{customField1}} from {{customField3}}". The plugin always puts
 * the order number in field 1, the order total in field 2 and the shop name in field 3
 * (see TemplateDraft::FIELD_FOR), so the same template reads "Order {order_number} from
 * {shop_name}" in a message. A template that needs anything else, such as the customer's
 * name, cannot be filled from an order and is left out.
 */
final class TemplateFiller {

	private const MARKER = '/\{\{\s*([A-Za-z0-9_]+)\s*\}\}|\{([A-Za-z0-9_]+)\}/';

	/**
	 * The message text for a template, or null when a variable has no order value.
	 *
	 * @param ViberTemplate $template Template.
	 */
	public static function text_for( ViberTemplate $template ): ?string {
		$field_for = array_flip( TemplateDraft::FIELD_FOR );
		$usable    = true;

		$text = preg_replace_callback(
			self::MARKER,
			static function ( array $found ) use ( $field_for, &$usable ): string {
				$variable = '' !== $found[1] ? $found[1] : ( $found[2] ?? '' );
				$number   = 0 === strpos( $variable, 'customField' ) ? (int) substr( $variable, strlen( 'customField' ) ) : 0;

				if ( ! isset( $field_for[ $number ] ) ) {
					$usable = false;

					return '';
				}

				return '{' . $field_for[ $number ] . '}';
			},
			$template->content
		);

		if ( ! $usable || null === $text || '' === trim( $text ) ) {
			return null;
		}

		// The shop's own address reads as {shop_url} again, the way the message is written here.
		return trim( str_replace( untrailingslashit( home_url() ), '{shop_url}', $text ) );
	}
}
