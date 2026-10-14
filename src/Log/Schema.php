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

	public const VERSION = '4';
	public const OPTION  = 'povikvane_db_version';

	/**
	 * Full table name including the WordPress prefix.
	 */
	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'povikvane_messages';
	}

	/**
	 * Name of the consent ledger table.
	 */
	public static function consent_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'povikvane_consent';
	}

	/**
	 * Create or update the tables.
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
  delivered_via varchar(16) DEFAULT NULL,
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
  checks smallint(5) unsigned NOT NULL DEFAULT 0,
  send_after datetime DEFAULT NULL,
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

		$consent = self::consent_table();

		$consent_sql = "CREATE TABLE {$consent} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  phone_hash varchar(16) NOT NULL,
  phone varchar(20) DEFAULT NULL,
  email varchar(190) DEFAULT NULL,
  granted tinyint(1) NOT NULL DEFAULT 0,
  source varchar(20) NOT NULL,
  order_id bigint(20) unsigned NOT NULL DEFAULT 0,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  wording_hash varchar(16) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY phone_hash (phone_hash,id),
  KEY email (email)
) {$charset};";

		dbDelta( array( $sql, $consent_sql ) );
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
