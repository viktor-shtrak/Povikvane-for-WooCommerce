<?php
/**
 * The text a shop owner copies to support.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Lays out facts about the site and the plugin as plain text. It only ever prints
 * what it is given, and what it is given never includes the API key, the webhook
 * address, phone numbers or message text.
 */
final class SupportReport {

	/**
	 * Format the facts.
	 *
	 * @param array<string,array<string,scalar|null>|list<string>> $sections Section title => key => value, or a list of lines.
	 */
	public static function format( array $sections ): string {
		$out = array();

		foreach ( $sections as $title => $rows ) {
			$out[] = '== ' . $title . ' ==';

			foreach ( $rows as $key => $value ) {
				if ( is_int( $key ) ) {
					$out[] = (string) $value;
					continue;
				}

				$out[] = $key . ': ' . self::scalar( $value );
			}

			$out[] = '';
		}

		return rtrim( implode( "\n", $out ) ) . "\n";
	}

	/**
	 * A value as text.
	 *
	 * @param scalar|null $value Value.
	 */
	private static function scalar( mixed $value ): string {
		return match ( true ) {
			null === $value  => '-',
			true === $value  => 'yes',
			false === $value => 'no',
			default          => (string) $value,
		};
	}
}
