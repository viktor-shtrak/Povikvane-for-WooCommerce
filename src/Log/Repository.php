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
