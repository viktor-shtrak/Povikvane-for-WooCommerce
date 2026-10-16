<?php
/**
 * Copies customers who agreed to a contact group in Povikvane.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Marketing;

defined( 'ABSPATH' ) || exit;

/**
 * The part of the Povikvane API that creates contacts and groups. It is behind an
 * interface because that API does not exist yet; until it does, the screen says so
 * and offers the CSV file instead.
 */
interface ContactSync {

	/**
	 * Name of the group the customers are put in.
	 */
	public const GROUP_NAME = 'Webshop SMS opt-in';

	/**
	 * Whether contacts can be sent to Povikvane from the plugin.
	 */
	public function available(): bool;

	/**
	 * Add contacts to the group, creating it when it does not exist.
	 *
	 * @param array<int,array{phone:string,first_name:string,last_name:string,agreed_at:int}> $contacts Customers who agreed.
	 * @return int Number of contacts accepted; negative when the request failed.
	 */
	public function sync( array $contacts ): int;
}
