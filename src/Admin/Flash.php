<?php
/**
 * One-time admin messages.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Messages stored briefly for the current user and shown once on the next page. A step
 * that does several things (save, send for approval, rename fields) can say what each did.
 */
final class Flash {

	/**
	 * Store a message.
	 *
	 * @param string $type "success" or "error".
	 * @param string $text Message text.
	 */
	public static function add( string $type, string $text ): void {
		$stored   = get_transient( self::key() );
		$messages = is_array( $stored ) && isset( $stored[0] ) && is_array( $stored[0] ) ? $stored : array();

		$messages[] = array( $type, $text );

		set_transient( self::key(), $messages, 60 );
	}

	/**
	 * Show and clear the stored messages.
	 */
	public static function render(): void {
		$stored = get_transient( self::key() );

		if ( ! is_array( $stored ) ) {
			return;
		}

		delete_transient( self::key() );

		foreach ( $stored as $flash ) {
			if ( ! is_array( $flash ) || 2 !== count( $flash ) ) {
				continue;
			}

			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				'success' === $flash[0] ? 'success' : 'error',
				esc_html( (string) $flash[1] )
			);
		}
	}

	/**
	 * Per-user storage key.
	 */
	private static function key(): string {
		return 'povikvane_flash_' . get_current_user_id();
	}
}
