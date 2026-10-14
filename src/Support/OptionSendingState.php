<?php
/**
 * Option-backed pause switch.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Stores the pause reason in an option.
 */
final class OptionSendingState implements SendingState {

	public const OPTION = 'povikvane_paused';

	/**
	 * {@inheritDoc}
	 */
	public function paused_reason(): ?string {
		$reason = get_option( self::OPTION, '' );

		return is_string( $reason ) && '' !== $reason ? $reason : null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $reason One of the REASON_* constants.
	 */
	public function pause( string $reason ): void {
		update_option( self::OPTION, $reason, false );
	}

	/**
	 * {@inheritDoc}
	 */
	public function resume(): void {
		delete_option( self::OPTION );
	}
}
