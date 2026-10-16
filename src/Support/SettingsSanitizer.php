<?php
/**
 * Validates submitted settings.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Cleans the settings the screens submit and stores the API key (encrypted) as a
 * side effect. Shared by the settings page and the setup wizard so both follow the
 * same rules.
 */
final class SettingsSanitizer {

	/**
	 * Constructor.
	 *
	 * @param Settings     $settings Settings and credentials.
	 * @param SendingState $state    Pause switch, cleared when credentials change.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly SendingState $state
	) {}

	/**
	 * Sanitise submitted settings.
	 *
	 * WordPress has already removed slashes from the value at this point.
	 *
	 * @param mixed $input Submitted settings.
	 * @return array<string,mixed>
	 */
	public function sanitize( mixed $input ): array {
		$input   = is_array( $input ) ? $input : array();
		$current = $this->settings->all();
		$changed = false;

		$service_id = isset( $input['service_id'] ) ? sanitize_text_field( (string) $input['service_id'] ) : $current['service_id'];
		$country    = isset( $input['default_country'] ) ? strtoupper( sanitize_text_field( (string) $input['default_country'] ) ) : $current['default_country'];
		$max_age    = isset( $input['max_age_hours'] ) ? (int) $input['max_age_hours'] : $current['max_age_hours'];

		if ( ! array_key_exists( $country, Countries::all() ) ) {
			$country = $current['default_country'];
		}

		if ( $service_id !== $current['service_id'] ) {
			$changed = true;
		}

		if ( ! empty( $input['remove_api_key'] ) ) {
			$this->settings->clear_api_key();
			$changed = true;
		} elseif ( isset( $input['api_key'] ) && '' !== trim( (string) $input['api_key'] ) ) {
			$this->settings->set_api_key( (string) preg_replace( '/\s+/', '', (string) $input['api_key'] ) );
			$changed = true;
		}

		if ( $changed ) {
			// New credentials deserve a fresh start: clear a pause and re-check the connection.
			$this->state->resume();

			if ( function_exists( 'as_enqueue_async_action' ) ) {
				as_enqueue_async_action( ActionSchedulerScheduler::PROBE_HOOK, array(), ActionSchedulerScheduler::GROUP );
			}
		}

		return array(
			'service_id'            => $service_id,
			'default_country'       => $country,
			'max_age_hours'         => min( 168, max( 1, $max_age ) ),
			'debug_log'             => ! empty( $input['debug_log'] ),
			'max_order_age_days'    => min( 365, max( 0, (int) ( $input['max_order_age_days'] ?? $current['max_order_age_days'] ) ) ),
			'skip_admin_created'    => ! empty( $input['skip_admin_created'] ),
			'mobile_only'           => ! empty( $input['mobile_only'] ),
			'storm_threshold'       => min( 10000, max( 0, (int) ( $input['storm_threshold'] ?? $current['storm_threshold'] ) ) ),
			'low_balance_threshold' => min( 1000000, max( 0, (int) ( $input['low_balance_threshold'] ?? $current['low_balance_threshold'] ) ) ),
			'retention_body_days'   => min( 3650, max( 0, (int) ( $input['retention_body_days'] ?? $current['retention_body_days'] ) ) ),
			'retention_row_days'    => min( 3650, max( 0, (int) ( $input['retention_row_days'] ?? $current['retention_row_days'] ) ) ),
			'export_full_numbers'   => ! empty( $input['export_full_numbers'] ),
			'delete_on_uninstall'   => ! empty( $input['delete_on_uninstall'] ),
			'plugin_language'       => Language::normalize( (string) ( $input['plugin_language'] ?? $current['plugin_language'] ) ),
		);
	}
}
