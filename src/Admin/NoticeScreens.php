<?php
/**
 * Where the plugin may show notices.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Notices stay on the screens where a shop owner is already working with the shop or with
 * this plugin: the Dashboard, the Plugins list, WooCommerce and order screens, and the
 * plugin's own pages. They do not appear on unrelated admin screens such as posts, media or
 * other plugins' settings.
 */
final class NoticeScreens {

	/**
	 * Whether the current admin screen is one where a notice belongs.
	 *
	 * @param bool $own_pages Whether the plugin's own pages count.
	 */
	public static function allowed( bool $own_pages ): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( null === $screen ) {
			return false;
		}

		if ( str_contains( $screen->id, 'povikvane' ) ) {
			return $own_pages;
		}

		return in_array( $screen->id, array( 'dashboard', 'plugins' ), true ) || str_contains( $screen->id, 'woocommerce' ) || str_contains( $screen->id, 'shop_order' );
	}
}
