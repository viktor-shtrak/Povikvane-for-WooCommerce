<?php
/**
 * A message text written as a Viber template, ready to be sent for approval.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Viber;

defined( 'ABSPATH' ) || exit;

/**
 * Turns "Order {order_number} from {shop_name} is complete." into the template
 * "Order {customField1} from {customField2} is complete." and says when a text could
 * not be a template at all, using the limits Povikvane and the carrier apply.
 */
final class TemplateDraft {

	public const PROBLEM_EMPTY    = 'empty';
	public const PROBLEM_EDGE     = 'edge';
	public const PROBLEM_LINK     = 'link';
	public const PROBLEM_TOO_MANY = 'too_many';
	public const PROBLEM_TOO_LONG = 'too_long';

	public const MAX_LENGTH = 875;
	public const MAX_FIELDS = 3;

	/**
	 * Constructor.
	 *
	 * @param string               $content Template content with {customFieldN} variables.
	 * @param array<string,string> $mapping Variable => placeholder.
	 * @param string|null          $problem A PROBLEM_* constant when the text cannot be a template.
	 */
	public function __construct(
		public readonly string $content,
		public readonly array $mapping,
		public readonly ?string $problem
	) {}

	/**
	 * Build the draft for a text.
	 *
	 * @param string $text SMS text with {placeholders}.
	 */
	public static function from_text( string $text ): self {
		$parts = TemplateMatcher::split( $text, false );

		if ( '' === trim( $text ) || '' === trim( implode( '', $parts['literals'] ) ) ) {
			return new self( '', array(), self::PROBLEM_EMPTY );
		}

		$variables = array();
		$content   = '';

		foreach ( $parts['slots'] as $index => $placeholder ) {
			if ( ! isset( $variables[ $placeholder ] ) ) {
				$variables[ $placeholder ] = 'customField' . ( count( $variables ) + 1 );
			}

			$content .= $parts['literals'][ $index ] . '{' . $variables[ $placeholder ] . '}';
		}

		$content .= $parts['literals'][ count( $parts['literals'] ) - 1 ];
		$mapping  = array_flip( $variables );

		return new self( $content, $mapping, self::problem_for( $parts, $variables ) );
	}

	/**
	 * First reason the text cannot be a template.
	 *
	 * @param array{literals:string[],slots:string[]} $parts     Split text.
	 * @param array<string,string>                    $variables Placeholder => variable.
	 */
	private static function problem_for( array $parts, array $variables ): ?string {
		foreach ( array_keys( $variables ) as $placeholder ) {
			if ( in_array( $placeholder, VariableMapper::LINK_PLACEHOLDERS, true ) ) {
				return self::PROBLEM_LINK;
			}
		}

		if ( count( $variables ) > self::MAX_FIELDS ) {
			return self::PROBLEM_TOO_MANY;
		}

		// The carrier does not accept a variable as the very first or last thing in a message.
		if ( array() !== $parts['slots'] && ( '' === $parts['literals'][0] || '' === $parts['literals'][ count( $parts['literals'] ) - 1 ] ) ) {
			return self::PROBLEM_EDGE;
		}

		return mb_strlen( implode( '', $parts['literals'] ) ) > self::MAX_LENGTH ? self::PROBLEM_TOO_LONG : null;
	}
}
