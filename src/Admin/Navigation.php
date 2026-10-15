<?php
/**
 * The tab bar for screens other than the settings page.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the same tab bar the settings page shows, as links, so the message log
 * looks like part of the same screen.
 */
final class Navigation {

	/**
	 * Tabs as links to the settings page, with the message log marked current.
	 *
	 * @return array<int,array{id:string,label:string,url:string,current?:bool}>
	 */
	public static function for_log_page(): array {
		$settings = static fn( string $tab ): string => admin_url( 'admin.php?page=' . SettingsPage::SLUG . '&tab=' . $tab );

		return array(
			array(
				'id'    => 'overview',
				'label' => __( 'Overview', 'povikvane-for-woocommerce' ),
				'url'   => $settings( 'overview' ),
			),
			array(
				'id'    => 'messages',
				'label' => __( 'Messages', 'povikvane-for-woocommerce' ),
				'url'   => $settings( 'messages' ),
			),
			array(
				'id'    => 'promotions',
				'label' => __( 'Promotions', 'povikvane-for-woocommerce' ),
				'url'   => $settings( 'promotions' ),
			),
			array(
				'id'    => 'settings',
				'label' => __( 'Settings', 'povikvane-for-woocommerce' ),
				'url'   => $settings( 'settings' ),
			),
			array(
				'id'    => 'advanced',
				'label' => __( 'Advanced', 'povikvane-for-woocommerce' ),
				'url'   => $settings( 'advanced' ),
			),
			array(
				'id'      => 'log',
				'label'   => __( 'Message log', 'povikvane-for-woocommerce' ),
				'url'     => admin_url( 'admin.php?page=' . MessageLogPage::SLUG ),
				'current' => true,
			),
		);
	}
}
