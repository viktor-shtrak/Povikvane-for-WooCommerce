<?php
/**
 * The operations the plugin needs from the API.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Implemented by Client; faked in tests.
 */
interface Gateway {

	/**
	 * Send a message.
	 *
	 * @param SendRequest $request Message to send.
	 */
	public function send( SendRequest $request ): ApiResult;

	/**
	 * Fetch a message's delivery status.
	 *
	 * @param string $message_id Message UUID.
	 */
	public function message_status( string $message_id ): ApiResult;

	/**
	 * Find messages by caller reference (flagged endpoint).
	 *
	 * @param string $reference Reference to look up.
	 */
	public function find_by_reference( string $reference ): ApiResult;

	/**
	 * Account balance and channels (flagged endpoint).
	 */
	public function account(): ApiResult;
}
