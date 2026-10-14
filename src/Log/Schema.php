<?php
/**
 * Database schema for the message log.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Log;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the message log table.
 */
final class Schema {

	public const VERSION = '1';
	public const OPTION  = 'povikvane_db_version';

	/**
	 * Full table name including the WordPress prefix.
	 */
	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'povikvane_messages';
	}

	/**
	 * Create or update the table.
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		// dbDelta() is strict about this layout: one definition per line, two spaces after PRIMARY KEY.
		$sql = "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  order_id bigint(20) unsigned NOT NULL DEFAULT 0,
  event varchar(64) NOT NULL,
  kind varchar(16) NOT NULL DEFAULT 'transactional',
  channel varchar(16) NOT NULL DEFAULT 'sms',
  recipient varchar(20) NOT NULL,
  body text NOT NULL,
  reference varchar(128) NOT NULL,
  api_message_id varchar(64) DEFAULT NULL,
  status varchar(20) NOT NULL DEFAULT 'pending',
  skip_reason varchar(32) DEFAULT NULL,
  error_code varchar(32) DEFAULT NULL,
  error_detail text,
  segments decimal(8,2) DEFAULT NULL,
  credits decimal(8,2) DEFAULT NULL,
  attempts smallint(5) unsigned NOT NULL DEFAULT 0,
  next_attempt_at datetime DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  final_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY reference (reference),
  KEY status_next (status,next_attempt_at),
  KEY order_event (order_id,event),
  KEY api_message_id (api_message_id)
) {$charset};";

		dbDelta( $sql );
		update_option( self::OPTION, self::VERSION, false );
	}

	/**
	 * Install when the stored schema version is behind.
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( self::OPTION ) !== self::VERSION ) {
			self::install();
		}
	}
}
