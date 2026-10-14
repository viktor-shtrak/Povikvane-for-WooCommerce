<?php
/**
 * Plugin bootstrap.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin together once WooCommerce is available and compatible.
 */
final class Plugin {

	/**
	 * Single instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Get the single instance.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Prevent direct construction.
	 */
	private function __construct() {}

	/**
	 * Register hooks if the environment is supported.
	 */
	public function boot(): void {
		if ( ! $this->woocommerce_is_compatible() ) {
			add_action( 'admin_notices', array( $this, 'render_incompatible_notice' ) );
			return;
		}

		/**
		 * Fires when the plugin has finished loading and WooCommerce is compatible.
		 */
		do_action( 'povikvane_loaded' );
	}

	/**
	 * Whether the active WooCommerce meets the minimum version.
	 */
	private function woocommerce_is_compatible(): bool {
		return defined( 'WC_VERSION' ) && version_compare( WC_VERSION, MIN_WC_VERSION, '>=' );
	}

	/**
	 * Tell administrators why the plugin is inactive.
	 */
	public function render_incompatible_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: minimum required WooCommerce version. */
					__( 'Povikvane SMS for WooCommerce requires WooCommerce %s or newer and has not been loaded.', 'povikvane-for-woocommerce' ),
					MIN_WC_VERSION
				)
			)
		);
	}
}
