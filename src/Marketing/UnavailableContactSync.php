<?php
/**
 * Stands in until the contacts API exists.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Marketing;

defined( 'ABSPATH' ) || exit;

/**
 * Sends nothing.
 */
final class UnavailableContactSync implements ContactSync {

	/**
	 * {@inheritDoc}
	 */
	public function available(): bool {
		return false;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<int,array{phone:string,first_name:string,last_name:string,agreed_at:int}> $contacts Customers.
	 */
	public function sync( array $contacts ): int {
		return -1;
	}
}
