<?php
/**
 * Storage for consent events.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Marketing;

defined( 'ABSPATH' ) || exit;

/**
 * Append-only record of consent decisions.
 */
interface ConsentLedger {

	/**
	 * The newest event for a number.
	 *
	 * @param string $phone_hash Keyed hash of the number.
	 */
	public function latest( string $phone_hash ): ?ConsentEvent;

	/**
	 * Record an event.
	 *
	 * @param ConsentEvent $event Event.
	 */
	public function append( ConsentEvent $event ): void;
}
