<?php
/**
 * Finds the Viber template that has the same wording as a message.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Viber;

use Povikvane\WooCommerce\Messages\Placeholders;

defined( 'ABSPATH' ) || exit;

/**
 * A Viber template is the same message as an SMS text when the fixed words are the
 * same and the variable spots line up: "Order {customField1} from {customField2}"
 * is the template for "Order {order_number} from {shop_name}". The spots are paired
 * by position, so nobody has to say which field gets which value.
 */
final class TemplateMatcher {

	private const TEMPLATE_PATTERN = '/\{\{\s*([A-Za-z0-9_]+)\s*\}\}|\{([A-Za-z0-9_]+)\}/';
	private const TEXT_PATTERN     = '/\{([A-Za-z_]+)\}/';

	/**
	 * Split a text into its fixed words and the names of its variable spots.
	 *
	 * @param string $text        Text.
	 * @param bool   $is_template Whether it is Viber template content (otherwise an SMS text).
	 * @return array{literals:string[],slots:string[]}
	 */
	public static function split( string $text, bool $is_template ): array {
		$pattern = $is_template ? self::TEMPLATE_PATTERN : self::TEXT_PATTERN;
		$found   = preg_match_all( $pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );
		$literal = array();
		$slots   = array();
		$cursor  = 0;

		foreach ( false === $found ? array() : $matches as $match ) {
			$literal[] = substr( $text, $cursor, $match[0][1] - $cursor );
			$cursor    = $match[0][1] + strlen( $match[0][0] );
			$name      = '' !== ( $match[1][0] ?? '' ) ? $match[1][0] : ( $match[2][0] ?? '' );
			$slots[]   = $is_template ? $name : strtolower( $name );
		}

		$literal[] = substr( $text, $cursor );

		$literal = array_map( static fn( string $part ): string => (string) preg_replace( '/\s+/u', ' ', $part ), $literal );

		$literal[0]                       = ltrim( $literal[0] );
		$literal[ count( $literal ) - 1 ] = rtrim( $literal[ count( $literal ) - 1 ] );

		return array(
			'literals' => $literal,
			'slots'    => $slots,
		);
	}

	/**
	 * The best template for a text: approved ones first.
	 *
	 * @param string          $text    SMS text with {placeholders}.
	 * @param TemplateCatalog $catalog Templates of the account.
	 */
	public static function find( string $text, TemplateCatalog $catalog ): TemplateMatch {
		$mine = self::split( $text, false );
		$best = TemplateMatch::none();
		$rank = -1;

		if ( array() === $mine['slots'] && '' === trim( implode( '', $mine['literals'] ) ) ) {
			return $best;
		}

		foreach ( $catalog->all() as $template ) {
			$theirs  = self::split( $template->content, true );
			$mapping = self::pair( $mine, $theirs );

			if ( null === $mapping ) {
				continue;
			}

			$state = self::state_of( $template );
			$score = self::rank( $state );

			if ( $score > $rank ) {
				$best = new TemplateMatch( $state, $template, $mapping );
				$rank = $score;
			}
		}

		return $best;
	}

	/**
	 * Pair the variable spots of a text with those of a template.
	 *
	 * @param array{literals:string[],slots:string[]} $mine   The SMS text.
	 * @param array{literals:string[],slots:string[]} $theirs The template.
	 * @return array<string,string>|null Variable => placeholder, or null when they differ.
	 */
	private static function pair( array $mine, array $theirs ): ?array {
		if ( $mine['literals'] !== $theirs['literals'] || count( $mine['slots'] ) !== count( $theirs['slots'] ) ) {
			return null;
		}

		$mapping = array();

		foreach ( $theirs['slots'] as $index => $variable ) {
			$placeholder = $mine['slots'][ $index ];

			// Names and surnames are filled by Povikvane from its own contact data.
			if ( in_array( $variable, array( 'firstName', 'lastName' ), true ) || ! in_array( $placeholder, Placeholders::NAMES, true ) ) {
				return null;
			}

			if ( isset( $mapping[ $variable ] ) && $mapping[ $variable ] !== $placeholder ) {
				return null;
			}

			$mapping[ $variable ] = $placeholder;
		}

		return $mapping;
	}

	/**
	 * How a template can be used.
	 *
	 * @param ViberTemplate $template Template.
	 */
	private static function state_of( ViberTemplate $template ): string {
		$provider = strtolower( (string) $template->provider_status );

		if ( str_contains( strtolower( $template->status ), 'reject' ) || str_contains( $provider, 'reject' ) || str_contains( $provider, 'declin' ) ) {
			return TemplateMatch::REJECTED;
		}

		if ( ! $template->is_approved() ) {
			return TemplateMatch::PENDING;
		}

		if ( $template->is_transactional() ) {
			return TemplateMatch::READY;
		}

		// Approved by Povikvane but the carrier has not answered yet: it counts as transactional only once it does.
		return 'pending' === $provider || str_contains( strtolower( (string) $template->why_not ), 'awaiting' ) ? TemplateMatch::PENDING : TemplateMatch::NOT_TRANSACTION;
	}

	/**
	 * Preference between several matching templates.
	 *
	 * @param string $state A TemplateMatch state.
	 */
	private static function rank( string $state ): int {
		return match ( $state ) {
			TemplateMatch::READY           => 4,
			TemplateMatch::NOT_TRANSACTION => 3,
			TemplateMatch::PENDING         => 2,
			default                        => 1,
		};
	}
}
