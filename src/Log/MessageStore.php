<?php
/**
 * Persistence for logged messages.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Log;

defined( 'ABSPATH' ) || exit;

/**
 * Implemented by Repository (database) and by an in-memory fake in tests.
 */
interface MessageStore {

	/**
	 * Insert a new message.
	 *
	 * @param Message $message Message without an ID.
	 * @return int|null New ID, or null if the reference already exists.
	 */
	public function create( Message $message ): ?int;

	/**
	 * Load by ID.
	 *
	 * @param int $id Row ID.
	 */
	public function find( int $id ): ?Message;

	/**
	 * Load by reference.
	 *
	 * @param string $reference Reference.
	 */
	public function find_by_reference( string $reference ): ?Message;

	/**
	 * Save changes to an existing message.
	 *
	 * @param Message $message Message with an ID.
	 */
	public function save( Message $message ): void;

	/**
	 * Number of messages created since a time, not counting skipped ones.
	 *
	 * @param int $since Unix time.
	 */
	public function count_created_since( int $since ): int;

	/**
	 * Number of messages (including skipped ones) logged for an order event.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $event    Event slug.
	 */
	public function count_for_order_event( int $order_id, string $event ): int;

	/**
	 * Load by the message ID the API returned.
	 *
	 * @param string $api_message_id API message UUID.
	 */
	public function find_by_api_message_id( string $api_message_id ): ?Message;

	/**
	 * Accepted or unconfirmed messages whose delivery status is due to be checked.
	 *
	 * @param int $now   Current time.
	 * @param int $limit Maximum rows.
	 * @return Message[]
	 */
	public function due_for_check( int $now, int $limit ): array;

	/**
	 * Messages that have been in the sending state since before a time.
	 *
	 * @param int $before Unix time.
	 * @param int $limit  Maximum rows.
	 * @return Message[]
	 */
	public function stuck_sending( int $before, int $limit ): array;

	/**
	 * Erase the text of finished messages older than a time.
	 *
	 * @param int $before Unix time.
	 * @return int Rows changed.
	 */
	public function purge_bodies( int $before ): int;

	/**
	 * Delete finished messages older than a time.
	 *
	 * @param int $before Unix time.
	 * @return int Rows deleted.
	 */
	public function delete_rows( int $before ): int;

	/**
	 * Promotional messages to a number that were sent (or are on their way) since a time.
	 *
	 * @param string $recipient Number in E.164.
	 * @param int    $since     Unix time.
	 */
	public function count_marketing_since( string $recipient, int $since ): int;
}
