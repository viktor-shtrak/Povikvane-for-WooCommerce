<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * @package Povikvane\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Data is kept unless the administrator turned on deletion in Advanced > Data and privacy.
$povikvane_settings = get_option( 'povikvane_settings', array() );

if ( empty( $povikvane_settings['delete_on_uninstall'] ) && 'yes' !== get_option( 'povikvane_delete_data_on_uninstall', 'no' ) ) {
	return;
}

global $wpdb;

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	foreach ( array( 'povikvane_send_message', 'povikvane_probe_capabilities', 'povikvane_refresh_balance', 'povikvane_reconcile', 'povikvane_purge' ) as $povikvane_hook ) {
		as_unschedule_all_actions( $povikvane_hook, array(), 'povikvane' );
	}
}

// The tables are ours and their names are built from the WordPress prefix.
// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'povikvane_messages' );
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'povikvane_consent' );

// Short-lived caches (live status, rate limits, flash notices) are options with these prefixes.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_povikvane_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_povikvane_' ) . '%'
	)
);
// phpcs:enable

foreach ( array(
	'povikvane_settings',
	'povikvane_api_key_enc',
	'povikvane_capabilities',
	'povikvane_viber_templates',
	'povikvane_template_requests',
	'povikvane_triggers',
	'povikvane_marketing',
	'povikvane_setup',
	'povikvane_site_fingerprint',
	'povikvane_balance',
	'povikvane_balance_alert',
	'povikvane_webhook_token',
	'povikvane_connection',
	'povikvane_webhook_secret_enc',
	'povikvane_webhook_seen',
	'povikvane_contacts_synced',
	'povikvane_webhook_activity',
	'povikvane_paused',
	'povikvane_install_salt',
	'povikvane_db_version',
	'povikvane_delete_data_on_uninstall',
	'povikvane_activation_redirect',
	'povikvane_landline_default',
	'povikvane_wizard_viber_chosen',
	'povikvane_webhook_verified_at',
) as $povikvane_option ) {
	delete_option( $povikvane_option );
}

delete_metadata( 'user', 0, 'povikvane_balance_notice_dismissed', '', true );
delete_metadata( 'user', 0, 'povikvane_language', '', true );
delete_metadata( 'user', 0, 'povikvane_attention_dismissed', '', true );
