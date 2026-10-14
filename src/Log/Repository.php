<?php
/**
 * Database-backed message store.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Log;

use Povikvane\WooCommerce\Messages\Kind;
use Povikvane\WooCommerce\Messages\Status;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table; the name comes from Schema::table() and values are prepared.

/**
 * Reads and writes the povikvane_messages table.
 */
final class Repository implements MessageStore {

	/**
	 * Column formats for $wpdb insert/update.
	 */
	private const FORMATS = array(
		'order_id'        => '%d',
		'event'           => '%s',
		'kind'            => '%s',
		'channel'         => '%s',
		'recipient'       => '%s',
		'body'            => '%s',
		'reference'       => '%s',
		'api_message_id'  => '%s',
		'status'          => '%s',
		'skip_reason'     => '%s',
		'error_code'      => '%s',
		'error_detail'    => '%s',
		'segments'        => '%s',
		'credits'         => '%s',
		'attempts'        => '%d',
		'checks'          => '%d',
		'next_attempt_at' => '%s',
		'created_at'      => '%s',
		'updated_at'      => '%s',
		'final_at'        => '%s',
	);

	/**
	 * {@inheritDoc}
	 *
	 * @param Message $message Message without an ID.
	 */
	public function create( Message $message ): ?int {
		global $wpdb;

		$row = $this->to_row( $message );

		// A duplicate reference violates the unique key; that is an expected outcome, not an error to print.
		$previous = $wpdb->suppress_errors( true );
		$inserted = $wpdb->insert( Schema::table(), $row, array_values( array_intersect_key( self::FORMATS, $row ) ) );
		$wpdb->suppress_errors( $previous );

		if ( false === $inserted || 0 === (int) $wpdb->insert_id ) {
			return null;
		}

		$message->id = (int) $wpdb->insert_id;

		return $message->id;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $id Row ID.
	 */
	public function find( int $id ): ?Message {
		global $wpdb;

		$table = Schema::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $reference Reference.
	 */
	public function find_by_reference( string $reference ): ?Message {
		global $wpdb;

		$table = Schema::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE reference = %s", $reference ), ARRAY_A );

		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Message $message Message with an ID.
	 */
	public function save( Message $message ): void {
		global $wpdb;

		if ( null === $message->id ) {
			return;
		}

		$row = $this->to_row( $message );

		$wpdb->update( Schema::table(), $row, array( 'id' => $message->id ), array_values( array_intersect_key( self::FORMATS, $row ) ), array( '%d' ) );
	}

	/**
	 * Most recent messages, newest first.
	 *
	 * @param int $limit Number of rows.
	 * @return Message[]
	 */
	public function recent( int $limit = 10 ): array {
		global $wpdb;

		$table = Schema::table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A );

		return array_map( fn( array $row ): Message => $this->hydrate( $row ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Number of messages per status.
	 *
	 * @return array<string,int>
	 */
	public function counts_by_status(): array {
		global $wpdb;

		$table = Schema::table();
		$rows  = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status", ARRAY_A );
		$out   = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[ (string) $row['status'] ] = (int) $row['total'];
		}

		return $out;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $since Unix time.
	 */
	public function count_created_since( int $since ): int {
		global $wpdb;

		$table = Schema::table();

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s AND status <> %s", gmdate( 'Y-m-d H:i:s', $since ), Status::Skipped->value )
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int    $order_id Order ID.
	 * @param string $event    Event slug.
	 */
	public function count_for_order_event( int $order_id, string $event ): int {
		global $wpdb;

		$table = Schema::table();

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE order_id = %d AND event = %s", $order_id, $event ) );
	}

	/**
	 * Messages for one order, newest first.
	 *
	 * @param int $order_id Order ID.
	 * @param int $limit    Number of rows.
	 * @return Message[]
	 */
	public function for_order( int $order_id, int $limit = 10 ): array {
		global $wpdb;

		$table = Schema::table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d ORDER BY id DESC LIMIT %d", $order_id, $limit ), ARRAY_A );

		return array_map( fn( array $row ): Message => $this->hydrate( $row ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Mark every waiting message as skipped (an administrator discarded the queue).
	 *
	 * @param string $reason A SkipReason constant.
	 * @param int    $now    Current time.
	 * @return int Number of messages discarded.
	 */
	public function discard_pending( string $reason, int $now ): int {
		global $wpdb;

		$table = Schema::table();
		$when  = gmdate( 'Y-m-d H:i:s', $now );

		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, skip_reason = %s, next_attempt_at = NULL, updated_at = %s, final_at = %s WHERE status IN (%s, %s)",
				Status::Skipped->value,
				$reason,
				$when,
				$when,
				Status::Pending->value,
				Status::HeldCredits->value
			)
		);

		return false === $affected ? 0 : (int) $affected;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $api_message_id API message UUID.
	 */
	public function find_by_api_message_id( string $api_message_id ): ?Message {
		global $wpdb;

		$table = Schema::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE api_message_id = %s ORDER BY id DESC LIMIT 1", $api_message_id ), ARRAY_A );

		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $now   Current time.
	 * @param int $limit Maximum rows.
	 */
	public function due_for_check( int $now, int $limit ): array {
		global $wpdb;

		$table = Schema::table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status IN (%s, %s) AND api_message_id IS NOT NULL AND next_attempt_at IS NOT NULL AND next_attempt_at <= %s ORDER BY next_attempt_at ASC LIMIT %d",
				Status::Queued->value,
				Status::Unconfirmed->value,
				gmdate( 'Y-m-d H:i:s', $now ),
				$limit
			),
			ARRAY_A
		);

		return $this->hydrate_all( $rows );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $before Unix time.
	 * @param int $limit  Maximum rows.
	 */
	public function stuck_sending( int $before, int $limit ): array {
		global $wpdb;

		$table = Schema::table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s AND updated_at < %s ORDER BY id ASC LIMIT %d", Status::Sending->value, gmdate( 'Y-m-d H:i:s', $before ), $limit ),
			ARRAY_A
		);

		return $this->hydrate_all( $rows );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $before Unix time.
	 */
	public function purge_bodies( int $before ): int {
		global $wpdb;

		$table = Schema::table();
		$done  = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET body = '' WHERE created_at < %s AND body <> '' AND status NOT IN (%s, %s, %s)",
				gmdate( 'Y-m-d H:i:s', $before ),
				Status::Pending->value,
				Status::Sending->value,
				Status::HeldCredits->value
			)
		);

		return false === $done ? 0 : (int) $done;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $before Unix time.
	 */
	public function delete_rows( int $before ): int {
		global $wpdb;

		$table = Schema::table();
		$done  = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE created_at < %s AND status NOT IN (%s, %s, %s)",
				gmdate( 'Y-m-d H:i:s', $before ),
				Status::Pending->value,
				Status::Sending->value,
				Status::HeldCredits->value
			)
		);

		return false === $done ? 0 : (int) $done;
	}

	/**
	 * When the oldest overdue waiting message should have been sent, or null if none is overdue.
	 *
	 * @param int $now Current time.
	 */
	public function oldest_overdue( int $now ): ?int {
		global $wpdb;

		$table = Schema::table();
		$due   = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MIN(COALESCE(next_attempt_at, created_at)) FROM {$table} WHERE status = %s AND COALESCE(next_attempt_at, created_at) <= %s",
				Status::Pending->value,
				gmdate( 'Y-m-d H:i:s', $now )
			)
		);

		return is_string( $due ) ? $this->from_datetime( $due ) : null;
	}

	/**
	 * Messages matching a filter, newest first.
	 *
	 * @param MessageQuery $query  Filter.
	 * @param int          $limit  Rows per page.
	 * @param int          $offset First row.
	 * @return Message[]
	 */
	public function search( MessageQuery $query, int $limit, int $offset ): array {
		global $wpdb;

		$table                = Schema::table();
		list( $where, $args ) = $this->where( $query );
		$args[]               = $limit;
		$args[]               = $offset;

		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The argument list is built together with the clauses.

		return $this->hydrate_all( $rows );
	}

	/**
	 * Number of messages matching a filter.
	 *
	 * @param MessageQuery $query Filter.
	 */
	public function count( MessageQuery $query ): int {
		global $wpdb;

		$table                = Schema::table();
		list( $where, $args ) = $this->where( $query );

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", $args ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- The placeholders are inside $where, which is built from fixed clauses.
	}

	/**
	 * Distinct event slugs in the log, for the filter menu.
	 *
	 * @return string[]
	 */
	public function events(): array {
		global $wpdb;

		$table = Schema::table();
		$rows  = $wpdb->get_col( "SELECT DISTINCT event FROM {$table} ORDER BY event ASC" );

		return is_array( $rows ) ? array_map( 'strval', $rows ) : array();
	}

	/**
	 * Build the WHERE clause and its arguments for a filter.
	 *
	 * @param MessageQuery $query Filter.
	 * @return array{string,array<int,mixed>}
	 */
	private function where( MessageQuery $query ): array {
		global $wpdb;

		// The always-true first clause keeps at least one placeholder, so every query is prepared.
		$clauses = array( '1 = %d' );
		$args    = array( 1 );

		if ( null !== $query->status ) {
			$clauses[] = 'status = %s';
			$args[]    = $query->status->value;
		}

		if ( '' !== $query->event ) {
			$clauses[] = 'event = %s';
			$args[]    = $query->event;
		}

		if ( '' !== $query->search ) {
			$clauses[] = '(order_id = %d OR reference LIKE %s OR api_message_id = %s)';
			$args[]    = (int) $query->search;
			$args[]    = '%' . $wpdb->esc_like( $query->search ) . '%';
			$args[]    = $query->search;
		}

		if ( null !== $query->from ) {
			$clauses[] = 'created_at >= %s';
			$args[]    = gmdate( 'Y-m-d H:i:s', $query->from );
		}

		if ( null !== $query->to ) {
			$clauses[] = 'created_at < %s';
			$args[]    = gmdate( 'Y-m-d H:i:s', $query->to );
		}

		return array( implode( ' AND ', $clauses ), $args );
	}

	/**
	 * Hydrate a list of rows.
	 *
	 * @param mixed $rows Database rows.
	 * @return Message[]
	 */
	private function hydrate_all( mixed $rows ): array {
		return array_map( fn( array $row ): Message => $this->hydrate( $row ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Convert a message to column values.
	 *
	 * @param Message $message Message.
	 * @return array<string,mixed>
	 */
	private function to_row( Message $message ): array {
		return array(
			'order_id'        => $message->order_id,
			'event'           => $message->event,
			'kind'            => $message->kind->value,
			'channel'         => $message->channel,
			'recipient'       => $message->recipient,
			'body'            => $message->body,
			'reference'       => $message->reference,
			'api_message_id'  => $message->api_message_id,
			'status'          => $message->status->value,
			'skip_reason'     => $message->skip_reason,
			'error_code'      => $message->error_code,
			'error_detail'    => $message->error_detail,
			'segments'        => null === $message->segments ? null : (string) $message->segments,
			'credits'         => null === $message->credits ? null : (string) $message->credits,
			'attempts'        => $message->attempts,
			'checks'          => $message->checks,
			'next_attempt_at' => $this->to_datetime( $message->next_attempt_at ),
			'created_at'      => $this->to_datetime( $message->created_at ),
			'updated_at'      => $this->to_datetime( $message->updated_at ),
			'final_at'        => $this->to_datetime( $message->final_at ),
		);
	}

	/**
	 * Build a message from a database row.
	 *
	 * @param array<string,mixed> $row Row.
	 */
	private function hydrate( array $row ): Message {
		$message                  = new Message();
		$message->id              = (int) $row['id'];
		$message->order_id        = (int) $row['order_id'];
		$message->event           = (string) $row['event'];
		$message->kind            = Kind::tryFrom( (string) $row['kind'] ) ?? Kind::Transactional;
		$message->channel         = (string) $row['channel'];
		$message->recipient       = (string) $row['recipient'];
		$message->body            = (string) $row['body'];
		$message->reference       = (string) $row['reference'];
		$message->api_message_id  = null === $row['api_message_id'] ? null : (string) $row['api_message_id'];
		$message->status          = Status::tryFrom( (string) $row['status'] ) ?? Status::Failed;
		$message->skip_reason     = null === $row['skip_reason'] ? null : (string) $row['skip_reason'];
		$message->error_code      = null === $row['error_code'] ? null : (string) $row['error_code'];
		$message->error_detail    = null === $row['error_detail'] ? null : (string) $row['error_detail'];
		$message->segments        = null === $row['segments'] ? null : (float) $row['segments'];
		$message->credits         = null === $row['credits'] ? null : (float) $row['credits'];
		$message->attempts        = (int) $row['attempts'];
		$message->checks          = (int) ( $row['checks'] ?? 0 );
		$message->next_attempt_at = $this->from_datetime( $row['next_attempt_at'] );
		$message->created_at      = (int) $this->from_datetime( $row['created_at'] );
		$message->updated_at      = (int) $this->from_datetime( $row['updated_at'] );
		$message->final_at        = $this->from_datetime( $row['final_at'] );

		return $message;
	}

	/**
	 * Unix time to a UTC datetime string.
	 *
	 * @param int|null $timestamp Unix time.
	 */
	private function to_datetime( ?int $timestamp ): ?string {
		return null === $timestamp || $timestamp <= 0 ? null : gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * UTC datetime string to Unix time.
	 *
	 * @param mixed $value Database value.
	 */
	private function from_datetime( mixed $value ): ?int {
		if ( ! is_string( $value ) || '' === $value || str_starts_with( $value, '0000' ) ) {
			return null;
		}

		$time = strtotime( $value . ' UTC' );

		return false === $time ? null : $time;
	}
}

// phpcs:enable
