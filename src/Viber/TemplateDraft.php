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
 * "Order {customField1} from {customField3} is complete." and says when a text could
 * not be a template at all, using the limits Povikvane and the carrier apply.
 */
final class TemplateDraft {

	public const PROBLEM_EMPTY    = 'empty';
	public const PROBLEM_EDGE     = 'edge';
	public const PROBLEM_LINK     = 'link';
	public const PROBLEM_URL_HOST = 'url_host';
	public const PROBLEM_TOO_MANY = 'too_many';
	public const PROBLEM_TOO_LONG = 'too_long';

	public const MAX_LENGTH = 875;

	/**
	 * What may follow the last field without counting as text (Povikvane's own list).
	 */
	private const ONLY_PUNCTUATION = '/^[\\s.,!?;:…\'"“”‘’()\\[\\]<>«»—–-]*$/u';

	/**
	 * Which custom field each order value always uses.
	 */
	public const FIELD_FOR  = array(
		'order_number' => 1,
		'order_total'  => 2,
		'shop_name'    => 3,
	);
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
		$text  = TemplateMatcher::with_shop_address( $text );
		$parts = TemplateMatcher::split( $text, false );

		if ( '' === trim( $text ) || '' === trim( implode( '', $parts['literals'] ) ) ) {
			return new self( '', array(), self::PROBLEM_EMPTY );
		}

		$variables = array();
		$content   = '';

		foreach ( $parts['slots'] as $index => $placeholder ) {
			if ( ! isset( $variables[ $placeholder ] ) ) {
				// The same value always goes in the same field, so labels in Povikvane stay true.
				$variables[ $placeholder ] = 'customField' . ( self::FIELD_FOR[ $placeholder ] ?? 4 + count( $variables ) );
			}

			$content .= $parts['literals'][ $index ] . '{' . $variables[ $placeholder ] . '}';
		}

		$content .= $parts['literals'][ count( $parts['literals'] ) - 1 ];
		$mapping  = array_flip( $variables );

		return new self( $content, $mapping, self::problem_for( $parts, $variables, $content ) );
	}

	/**
	 * First reason the text cannot be a template.
	 *
	 * @param array{literals:string[],slots:string[]} $parts     Split text.
	 * @param array<string,string>                    $variables Placeholder => variable.
	 * @param string                                  $content   Template content with {customFieldN} variables.
	 */
	private static function problem_for( array $parts, array $variables, string $content ): ?string {
		foreach ( array_keys( $variables ) as $placeholder ) {
			if ( in_array( $placeholder, VariableMapper::LINK_PLACEHOLDERS, true ) ) {
				return self::PROBLEM_LINK;
			}
		}

		foreach ( $variables as $variable ) {
			if ( (int) substr( $variable, strlen( 'customField' ) ) > self::MAX_FIELDS ) {
				return self::PROBLEM_TOO_MANY;
			}
		}

		// The carrier does not accept a variable as the very first or last thing in a message. Punctuation
		// after the last one does not count as text: "... from {shop_name}." still ends with a variable.
		if ( array() !== $parts['slots'] && ( '' === $parts['literals'][0] || 1 === preg_match( self::ONLY_PUNCTUATION, $parts['literals'][ count( $parts['literals'] ) - 1 ] ) ) ) {
			return self::PROBLEM_EDGE;
		}

		if ( self::has_dynamic_url_host( $content ) ) {
			return self::PROBLEM_URL_HOST;
		}

		return mb_strlen( implode( '', $parts['literals'] ) ) > self::MAX_LENGTH ? self::PROBLEM_TOO_LONG : null;
	}

	/**
	 * Whether a field sits in the protocol or domain of a web address in the text. A field may
	 * fill a path, an ID or a query value after a fixed domain, but not the domain itself.
	 *
	 * @param string $content Template content with {customFieldN} variables.
	 */
	private static function has_dynamic_url_host( string $content ): bool {
		preg_match_all( '~(?:https?://|www\.)[^\s]*~i', $content, $tokens );

		foreach ( $tokens[0] as $token ) {
			if ( 1 !== preg_match( '/\{customField[1-3]\}/', $token, $variable, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}

			$before = substr( $token, 0, (int) $variable[0][1] );

			if ( 1 !== preg_match( '~^(?:https?://|www\.)(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}(?=[/?#])~i', $before ) ) {
				return true;
			}
		}

		return false;
	}
}
