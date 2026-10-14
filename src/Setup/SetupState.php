<?php
/**
 * Remembers whether setup is finished.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Setup;

defined( 'ABSPATH' ) || exit;

/**
 * Two flags: setup was completed (never nag again), or the reminder was dismissed.
 * The checklist itself is worked out from real state; this only stops the nagging.
 */
final class SetupState {

	public const OPTION   = 'povikvane_setup';
	public const REDIRECT = 'povikvane_activation_redirect';

	/**
	 * Whether the wizard was finished or the checklist reached 100 per cent of the required steps.
	 */
	public function is_completed(): bool {
		return $this->flag( 'completed' );
	}

	/**
	 * Whether the shop owner dismissed the reminder.
	 */
	public function is_dismissed(): bool {
		return $this->flag( 'dismissed' );
	}

	/**
	 * Record that setup is done.
	 */
	public function complete(): void {
		$this->set( 'completed', true );
	}

	/**
	 * Stop showing the reminder.
	 */
	public function dismiss(): void {
		$this->set( 'dismissed', true );
	}

	/**
	 * Show the reminder again and offer the wizard after the next activation.
	 */
	public function reset(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Read a flag.
	 *
	 * @param string $name Flag name.
	 */
	private function flag( string $name ): bool {
		$data = get_option( self::OPTION, array() );

		return is_array( $data ) && ! empty( $data[ $name ] );
	}

	/**
	 * Store a flag.
	 *
	 * @param string $name  Flag name.
	 * @param bool   $value Value.
	 */
	private function set( string $name, bool $value ): void {
		$data = get_option( self::OPTION, array() );
		$data = is_array( $data ) ? $data : array();

		$data[ $name ] = $value;

		update_option( self::OPTION, $data, false );
	}
}
