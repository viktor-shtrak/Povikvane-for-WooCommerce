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

// TODO (M1+): drop the povikvane_messages table, delete povikvane_* options and unschedule Action Scheduler jobs.
delete_option( 'povikvane_delete_data_on_uninstall' );
