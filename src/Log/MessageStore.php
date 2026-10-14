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
}
