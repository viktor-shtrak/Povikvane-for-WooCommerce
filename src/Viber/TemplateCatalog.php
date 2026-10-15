<?php
/**
 * The account's Viber templates, kept in an option.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Viber;

defined( 'ABSPATH' ) || exit;

/**
 * A local copy of the template list, so the settings page and every order event
 * can read it without calling the API.
 */
final class TemplateCatalog {

	public const OPTION = 'povikvane_viber_templates';

	/**
	 * Every stored template, by name.
	 *
	 * @return ViberTemplate[]
	 */
	public function all(): array {
		$stored = get_option( self::OPTION, array() );
		$rows   = is_array( $stored ) && is_array( $stored['items'] ?? null ) ? $stored['items'] : array();
		$out    = array();

		foreach ( $rows as $row ) {
			$template = is_array( $row ) ? ViberTemplate::from_array( $row ) : null;

			if ( null !== $template ) {
				$out[] = $template;
			}
		}

		return $out;
	}

	/**
	 * One template.
	 *
	 * @param string $id Template ID.
	 */
	public function find( string $id ): ?ViberTemplate {
		foreach ( $this->all() as $template ) {
			if ( $template->id === $id ) {
				return $template;
			}
		}

		return null;
	}

	/**
	 * When the list was last fetched; 0 if never.
	 */
	public function fetched_at(): int {
		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) ? (int) ( $stored['fetched_at'] ?? 0 ) : 0;
	}

	/**
	 * Replace the stored list.
	 *
	 * @param ViberTemplate[] $templates Templates.
	 * @param int             $time      Fetch time.
	 */
	public function replace( array $templates, int $time ): void {
		update_option(
			self::OPTION,
			array(
				'fetched_at' => $time,
				'items'      => array_map( static fn( ViberTemplate $t ): array => $t->to_array(), $templates ),
			),
			false
		);
	}
}
