<?php
/**
 * Source of trigger configuration.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Orders;

defined( 'ABSPATH' ) || exit;

/**
 * Looks up the trigger for a status.
 */
interface TriggerProvider {

	/**
	 * Trigger configuration for a status.
	 *
	 * @param string $status Status slug without the "wc-" prefix.
	 */
	public function for_status( string $status ): Trigger;
}
