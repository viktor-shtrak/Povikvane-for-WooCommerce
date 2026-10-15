<?php
/**
 * Shared admin scripts.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Admin;

use const Povikvane\WooCommerce\PLUGIN_FILE;
use const Povikvane\WooCommerce\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Scripts used by more than one admin screen.
 */
final class Assets {

	/**
	 * The stylesheet shared by every Povikvane screen.
	 */
	public static function base(): void {
		wp_enqueue_style( 'povikvane-admin', plugins_url( 'assets/css/admin.css', PLUGIN_FILE ), array(), VERSION );
		wp_enqueue_script( 'povikvane-select', plugins_url( 'assets/js/select.js', PLUGIN_FILE ), array(), VERSION, true );
	}

	/**
	 * Ask "are you sure?" before links and buttons marked with data-povikvane-confirm.
	 */
	public static function confirm(): void {
		wp_enqueue_script( 'povikvane-confirm', plugins_url( 'assets/js/confirm.js', PLUGIN_FILE ), array(), VERSION, true );
	}
}
