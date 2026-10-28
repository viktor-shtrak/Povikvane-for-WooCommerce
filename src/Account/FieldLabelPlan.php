<?php
/**
 * Compares the custom field labels of the account with the ones WooCommerce would give.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Account;

defined( 'ABSPATH' ) || exit;

/**
 * Custom fields 1 to 3 are what Viber templates use for order values. The plugin always
 * puts the order number in field 1, the total in field 2 and the shop name in field 3,
 * so labelling them that way in Povikvane keeps templates readable there.
 */
final class FieldLabelPlan {

	public const UNKNOWN = 'unknown';
	public const NONE    = 'none';
	public const PARTIAL = 'partial';
	public const FULL    = 'full';
	public const MATCHES = 'matches';

	/**
	 * Constructor.
	 *
	 * @param string               $state   One of the constants above.
	 * @param array<string,string> $current Field => label the account has ('' when none).
	 * @param array<string,string> $wanted  Field => label WooCommerce gives.
	 */
	public function __construct(
		public readonly string $state,
		public readonly array $current,
		public readonly array $wanted
	) {}

	/**
	 * Work out the state.
	 *
	 * @param array<string,string>|null $current Labels of the account, or null when unknown.
	 * @param array<string,string>      $wanted  Labels WooCommerce gives.
	 */
	public static function compare( ?array $current, array $wanted ): self {
		if ( null === $current ) {
			return new self( self::UNKNOWN, array(), $wanted );
		}

		$filled = array_filter( $current, static fn( string $label ): bool => '' !== $label );

		if ( array() === $filled ) {
			return new self( self::NONE, $current, $wanted );
		}

		if ( count( $filled ) < count( AccountInfo::FIELDS ) ) {
			return new self( self::PARTIAL, $current, $wanted );
		}

		return new self( $current === $wanted ? self::MATCHES : self::FULL, $current, $wanted );
	}

	/**
	 * Labels to send for a choice: every field, or only the empty ones.
	 *
	 * @param bool $only_empty Keep labels the account already has.
	 * @return array<string,string>
	 */
	public function labels_to_save( bool $only_empty ): array {
		if ( ! $only_empty ) {
			return $this->wanted;
		}

		$out = array();

		foreach ( $this->wanted as $field => $label ) {
			$out[ $field ] = '' !== ( $this->current[ $field ] ?? '' ) ? $this->current[ $field ] : $label;
		}

		return $out;
	}

	/**
	 * Labels to send when the owner picked which fields to rename.
	 *
	 * @param string[] $fields Fields to rename, such as "customField2".
	 * @return array<string,string> Field => label, only for the picked fields.
	 */
	public function labels_for( array $fields ): array {
		return array_intersect_key( $this->wanted, array_flip( $fields ) );
	}
}
