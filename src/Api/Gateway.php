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

	/**
	 * Viber templates registered for the account (flagged endpoint, not yet released).
	 */
	public function templates(): ApiResult;

	/**
	 * Template limits and today's usage (flagged endpoint).
	 */
	public function template_rules(): ApiResult;

	/**
	 * Create a Viber template for review (flagged endpoint).
	 *
	 * @param string $name    Template name.
	 * @param string $lang    "bg" or "en".
	 * @param string $content Text with {customFieldN} variables.
	 */
	public function create_template( string $name, string $lang, string $content ): ApiResult;

	/**
	 * Set custom field labels.
	 *
	 * @param array<string,string> $labels Field => label; an empty string clears.
	 */
	public function set_custom_fields( array $labels ): ApiResult;

	/**
	 * Create a contact group, or return the one with that name.
	 *
	 * @param string $name Group name.
	 */
	public function ensure_group( string $name ): ApiResult;

	/**
	 * Add or update up to 500 contacts in a group.
	 *
	 * @param string                         $group_id Group ID.
	 * @param array<int,array<string,mixed>> $contacts Contact items.
	 */
	public function upsert_contacts( string $group_id, array $contacts ): ApiResult;

	/**
	 * Delete a contact and its group memberships (privacy erasure).
	 *
	 * @param string $phone Phone number, E.164.
	 */
	public function delete_contact( string $phone ): ApiResult;

	/**
	 * Put up to 500 numbers on the company's opt-out list.
	 *
	 * @param array<int,array<string,string>> $numbers Items with phone, optional reason and withdrawn_at.
	 */
	public function add_suppressions( array $numbers ): ApiResult;
}
