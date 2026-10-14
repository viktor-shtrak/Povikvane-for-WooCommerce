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
 * A message stored briefly for the current user and shown once on the next page.
 */
final class Flash {

	/**
	 * Store a message.
	 *
	 * @param string $type "success" or "error".
	 * @param string $text Message text.
	 */
	public static function add( string $type, string $text ): void {
		set_transient( self::key(), array( $type, $text ), 60 );
	}

	/**
	 * Show and clear the stored message.
	 */
	public static function render(): void {
		$flash = get_transient( self::key() );

		if ( ! is_array( $flash ) || 2 !== count( $flash ) ) {
			return;
		}

		delete_transient( self::key() );

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			'success' === $flash[0] ? 'success' : 'error',
			esc_html( (string) $flash[1] )
		);
	}

	/**
	 * Per-user storage key.
	 */
	private static function key(): string {
		return 'povikvane_flash_' . get_current_user_id();
	}
}
