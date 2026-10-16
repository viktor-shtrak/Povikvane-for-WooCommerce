<?php
/**
 * Stored trigger configuration.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Orders;

use Povikvane\WooCommerce\Messages\LegacyPlaceholders;
use Povikvane\WooCommerce\Messages\Placeholders;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and sanitises the per-status trigger settings. Anything not stored falls
 * back to the defaults: only "completed" is on, with a ready-made text.
 */
final class TriggerRepository implements TriggerProvider {

	public const OPTION = 'povikvane_triggers';

	private const MAX_TEMPLATE_LENGTH = 1000;
	private const MAX_DELAY_MINUTES   = 1440;

	/**
	 * Constructor.
	 *
	 * @param string $locale Site locale, used to pick the default text.
	 */
	public function __construct( private readonly string $locale ) {}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $status Status slug without the "wc-" prefix.
	 */
	public function for_status( string $status ): Trigger {
		$stored = get_option( self::OPTION, array() );
		$row    = is_array( $stored ) && isset( $stored[ $status ] ) && is_array( $stored[ $status ] ) ? $stored[ $status ] : array();

		$template = isset( $row['template'] ) && is_string( $row['template'] ) ? LegacyPlaceholders::strip( $row['template'] ) : '';

		return new Trigger(
			$status,
			array_key_exists( 'enabled', $row ) ? (bool) $row['enabled'] : TemplateDefaults::enabled_by_default( $status ),
			'' !== $template ? $template : TemplateDefaults::for_status( $status, $this->locale ),
			isset( $row['delay'] ) ? max( 0, min( self::MAX_DELAY_MINUTES, (int) $row['delay'] ) ) : 0
		);
	}

	/**
	 * Order statuses offered in the editor, keyed by slug.
	 *
	 * @return array<string,string>
	 */
	public function statuses(): array {
		$out = array();

		foreach ( wc_get_order_statuses() as $key => $label ) {
			$slug = (string) preg_replace( '/^wc-/', '', (string) $key );

			if ( 'checkout-draft' !== $slug ) {
				$out[ $slug ] = (string) $label;
			}
		}

		return $out;
	}

	/**
	 * The current configuration of every status in the shape the settings form posts,
	 * so a screen showing only some statuses can change those and leave the rest alone.
	 *
	 * @return array<string,array{enabled:bool,template:string,delay:int}>
	 */
	public function current_input(): array {
		$input = array();

		foreach ( array_keys( $this->statuses() ) as $slug ) {
			$trigger        = $this->for_status( $slug );
			$input[ $slug ] = array(
				'enabled'  => $trigger->enabled,
				'template' => $trigger->template,
				'delay'    => $trigger->delay_minutes,
			);
		}

		return $input;
	}

	/**
	 * Sanitise the submitted trigger table.
	 *
	 * @param mixed $input Submitted value, keyed by status slug.
	 * @return array<string,array{enabled:bool,template:string,delay:int}>
	 */
	public function sanitize( mixed $input ): array {
		$input = is_array( $input ) ? $input : array();
		$clean = array();

		foreach ( array_keys( $this->statuses() ) as $slug ) {
			$row = isset( $input[ $slug ] ) && is_array( $input[ $slug ] ) ? $input[ $slug ] : array();

			$template = isset( $row['template'] ) ? sanitize_textarea_field( (string) $row['template'] ) : '';

			$clean[ $slug ] = array(
				'enabled'  => ! empty( $row['enabled'] ),
				'template' => function_exists( 'mb_substr' ) ? mb_substr( $template, 0, self::MAX_TEMPLATE_LENGTH ) : substr( $template, 0, self::MAX_TEMPLATE_LENGTH ),
				'delay'    => max( 0, min( self::MAX_DELAY_MINUTES, (int) ( $row['delay'] ?? 0 ) ) ),
			);
		}

		return $clean;
	}
}
