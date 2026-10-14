<?php
/**
 * Plugin Name:          Povikvane SMS for WooCommerce
 * Plugin URI:           https://app.povikvane.com/
 * Description:          Send order status SMS notifications and opt-in marketing messages from WooCommerce through your Povikvane account.
 * Version:              0.1.0
 * Requires at least:    7.0
 * Requires PHP:         8.3
 * Requires Plugins:     woocommerce
 * WC requires at least: 11.0
 * Author:               Shtrak BG Ltd
 * Author URI:           https://shtrak.bg/
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          povikvane-for-woocommerce
 * Domain Path:          /languages
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce;

defined( 'ABSPATH' ) || exit;

const VERSION        = '0.1.0';
const MIN_WC_VERSION = '11.0';
const PLUGIN_FILE    = __FILE__;
const PLUGIN_DIR     = __DIR__ . '/';

// Minimal PSR-4 autoloader so the plugin runs from a plain ZIP, with no Composer runtime dependency.
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = __NAMESPACE__ . '\\';
		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		$file     = PLUGIN_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

// Declare compatibility with High-Performance Order Storage and the Cart/Checkout blocks.
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', PLUGIN_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', PLUGIN_FILE, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		Plugin::instance()->boot();
	}
);
