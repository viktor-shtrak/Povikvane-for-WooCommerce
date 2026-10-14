<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * @package Povikvane\WooCommerce
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Data is kept unless the administrator explicitly opted in to deletion.
if ( 'yes' !== get_option( 'povikvane_delete_data_on_uninstall', 'no' ) ) {
	return;
}

global $wpdb;

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	foreach ( array( 'povikvane_send_message', 'povikvane_probe_capabilities', 'povikvane_refresh_balance', 'povikvane_reconcile', 'povikvane_purge' ) as $povikvane_hook ) {
		as_unschedule_all_actions( $povikvane_hook, array(), 'povikvane' );
	}
}

// The table is ours and the name is built from the WordPress prefix.
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'povikvane_messages' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

foreach ( array(
	'povikvane_settings',
	'povikvane_api_key_enc',
	'povikvane_capabilities',
	'povikvane_triggers',
	'povikvane_site_fingerprint',
	'povikvane_balance',
	'povikvane_balance_alert',
	'povikvane_webhook_token',
	'povikvane_webhook_activity',
	'povikvane_paused',
	'povikvane_install_salt',
	'povikvane_db_version',
	'povikvane_delete_data_on_uninstall',
) as $povikvane_option ) {
	delete_option( $povikvane_option );
}
