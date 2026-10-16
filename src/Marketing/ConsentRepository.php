<?php
/**
 * Database-backed consent ledger.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Marketing;

use Povikvane\WooCommerce\Log\Schema;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table; the name comes from Schema and values are prepared.

/**
 * Reads and writes the povikvane_consent table. Rows are never edited, except to
 * erase the personal details of a person who asked to be forgotten while keeping
 * the hash and the decision, so that their opt-out stays in force.
 */
final class ConsentRepository implements ConsentLedger {

	/**
	 * {@inheritDoc}
	 *
	 * @param string $phone_hash Keyed hash of the number.
	 */
	public function latest( string $phone_hash ): ?ConsentEvent {
		global $wpdb;

		$table = Schema::consent_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE phone_hash = %s ORDER BY id DESC LIMIT 1", $phone_hash ), ARRAY_A );

		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param ConsentEvent $event Event.
	 */
	public function append( ConsentEvent $event ): void {
		global $wpdb;

		$wpdb->insert(
			Schema::consent_table(),
			array(
				'phone_hash'   => $event->phone_hash,
				'phone'        => $event->phone,
				'email'        => $event->email,
				'granted'      => $event->granted ? 1 : 0,
				'source'       => $event->source,
				'order_id'     => $event->order_id,
				'user_id'      => $event->user_id,
				'wording_hash' => $event->wording_hash,
				'created_at'   => gmdate( 'Y-m-d H:i:s', $event->created_at ),
			),
			array( '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%s' )
		);
	}

	/**
	 * Customers who currently agree to promotional messages, oldest first.
	 *
	 * @param int $limit  Rows per page.
	 * @param int $offset First row.
	 * @return ConsentEvent[]
	 */
	public function opted_in( int $limit, int $offset ): array {
		global $wpdb;

		$table = Schema::consent_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.* FROM {$table} c INNER JOIN (SELECT MAX(id) AS id FROM {$table} GROUP BY phone_hash) latest ON c.id = latest.id WHERE c.granted = 1 AND c.phone IS NOT NULL ORDER BY c.id ASC LIMIT %d OFFSET %d",
				$limit,
				$offset
			),
			ARRAY_A
		);

		return array_map( fn( array $row ): ConsentEvent => $this->hydrate( $row ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Current agreements of some accounts: the newest "agreed" event per number that
	 * still agrees, matched by account or, for purchases made as a guest, by email.
	 *
	 * @param array<int,string> $accounts User ID => email.
	 * @return array<int,ConsentEvent> User ID => the event that gave the agreement.
	 */
	public function granted_for_accounts( array $accounts ): array {
		global $wpdb;

		if ( array() === $accounts ) {
			return array();
		}

		$table  = Schema::consent_table();
		$ids    = array_map( 'intval', array_keys( $accounts ) );
		$emails = array_values( array_filter( array_map( 'strtolower', $accounts ), static fn( string $email ): bool => '' !== $email ) );
		$clause = 'c.user_id IN (' . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ')';
		$args   = $ids;

		if ( array() !== $emails ) {
			$clause .= ' OR c.email IN (' . implode( ', ', array_fill( 0, count( $emails ), '%s' ) ) . ')';
			$args    = array_merge( $args, $emails );
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.* FROM {$table} c INNER JOIN (SELECT MAX(id) AS id FROM {$table} GROUP BY phone_hash) latest ON c.id = latest.id WHERE c.granted = 1 AND ({$clause}) ORDER BY c.id ASC", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Placeholders are generated to match the arguments.
				$args
			),
			ARRAY_A
		);

		$by_email = array_flip( array_map( 'strtolower', $accounts ) );
		$out      = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$event   = $this->hydrate( $row );
			$user_id = isset( $accounts[ $event->user_id ] ) ? $event->user_id : ( $by_email[ strtolower( (string) $event->email ) ] ?? 0 );

			if ( $user_id > 0 && ( ! isset( $out[ $user_id ] ) || $event->created_at >= $out[ $user_id ]->created_at ) ) {
				$out[ (int) $user_id ] = $event;
			}
		}

		return $out;
	}

	/**
	 * Who currently agrees, as account IDs and emails with the time they agreed, for
	 * marking customers in lists. Capped so a huge list cannot bloat a page.
	 *
	 * @param int $limit Most rows to return.
	 * @return array<int,array{user_id:int,email:string,time:int}>
	 */
	public function granted_summary( int $limit ): array {
		global $wpdb;

		$table = Schema::consent_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.user_id, c.email, c.created_at FROM {$table} c INNER JOIN (SELECT MAX(id) AS id FROM {$table} GROUP BY phone_hash) latest ON c.id = latest.id WHERE c.granted = 1 ORDER BY c.id DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		$out = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[] = array(
				'user_id' => (int) $row['user_id'],
				'email'   => strtolower( (string) $row['email'] ),
				'time'    => (int) strtotime( (string) $row['created_at'] . ' UTC' ),
			);
		}

		return $out;
	}

	/**
	 * Number of customers who currently agree.
	 */
	public function count_opted_in(): int {
		global $wpdb;

		$table = Schema::consent_table();

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} c INNER JOIN (SELECT MAX(id) AS id FROM {$table} GROUP BY phone_hash) latest ON c.id = latest.id WHERE c.granted = 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- No user input; the table name comes from Schema.
	}

	/**
	 * Every event for some numbers or an email address (personal data export).
	 *
	 * @param string[] $phone_hashes Keyed number hashes.
	 * @param string   $email        Email address, or an empty string.
	 * @return ConsentEvent[]
	 */
	public function events_for( array $phone_hashes, string $email ): array {
		global $wpdb;

		$table   = Schema::consent_table();
		$clauses = array();
		$args    = array();

		if ( array() !== $phone_hashes ) {
			$clauses[] = 'phone_hash IN (' . implode( ', ', array_fill( 0, count( $phone_hashes ), '%s' ) ) . ')';
			$args      = array_merge( $args, array_values( $phone_hashes ) );
		}

		if ( '' !== $email ) {
			$clauses[] = 'email = %s';
			$args[]    = $email;
		}

		if ( array() === $clauses ) {
			return array();
		}

		$where = implode( ' OR ', $clauses );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY id ASC", $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Placeholders are generated to match the arguments.

		return array_map( fn( array $row ): ConsentEvent => $this->hydrate( $row ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Erase the number and email from events, keeping the hash and the decision so a
	 * withdrawal stays in force.
	 *
	 * @param string[] $phone_hashes Keyed number hashes.
	 * @param string   $email        Email address, or an empty string.
	 * @return int Events changed.
	 */
	public function erase_personal_data( array $phone_hashes, string $email ): int {
		global $wpdb;

		$table   = Schema::consent_table();
		$clauses = array();
		$args    = array();

		if ( array() !== $phone_hashes ) {
			$clauses[] = 'phone_hash IN (' . implode( ', ', array_fill( 0, count( $phone_hashes ), '%s' ) ) . ')';
			$args      = array_merge( $args, array_values( $phone_hashes ) );
		}

		if ( '' !== $email ) {
			$clauses[] = 'email = %s';
			$args[]    = $email;
		}

		if ( array() === $clauses ) {
			return 0;
		}

		$where = implode( ' OR ', $clauses );
		$done  = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET phone = NULL, email = NULL WHERE {$where}", $args ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Placeholders are generated to match the arguments.

		return false === $done ? 0 : (int) $done;
	}

	/**
	 * Build an event from a database row.
	 *
	 * @param array<string,mixed> $row Row.
	 */
	private function hydrate( array $row ): ConsentEvent {
		return new ConsentEvent(
			(string) $row['phone_hash'],
			(bool) $row['granted'],
			(string) $row['source'],
			null === $row['phone'] ? null : (string) $row['phone'],
			null === $row['email'] ? null : (string) $row['email'],
			(int) $row['order_id'],
			(int) $row['user_id'],
			(string) $row['wording_hash'],
			(int) strtotime( (string) $row['created_at'] . ' UTC' ),
			(int) $row['id']
		);
	}
}

// phpcs:enable
