<?php
/**
 * Remembers which webhook events were already handled.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Webhook;

defined( 'ABSPATH' ) || exit;

/**
 * Povikvane delivers at least once: a retry keeps the event id. Retries stop after about
 * 33 hours, so ids are kept for three days.
 */
final class SeenEvents {

	public const OPTION   = 'povikvane_webhook_seen';
	public const KEEP     = 259200;
	public const MAX_KEPT = 500;

	/**
	 * Whether an id was handled before.
	 *
	 * @param string $id  Event id.
	 * @param int    $now Current time.
	 */
	public function has( string $id, int $now ): bool {
		return isset( $this->load( $now )[ $id ] );
	}

	/**
	 * Remember that an id was handled.
	 *
	 * @param string $id  Event id.
	 * @param int    $now Current time.
	 */
	public function remember( string $id, int $now ): void {
		if ( ! self::valid( $id ) ) {
			return;
		}

		$seen        = $this->load( $now );
		$seen[ $id ] = $now;

		// Oldest first: drop from the front when over the limit.
		if ( count( $seen ) > self::MAX_KEPT ) {
			asort( $seen );
			$seen = array_slice( $seen, -self::MAX_KEPT, null, true );
		}

		update_option( self::OPTION, $seen, false );
	}

	/**
	 * Whether a string looks like an event id.
	 *
	 * @param string $id Candidate.
	 */
	private static function valid( string $id ): bool {
		return 1 === preg_match( '/^[0-9a-f-]{16,64}$/i', $id );
	}

	/**
	 * The ids still worth remembering.
	 *
	 * @param int $now Current time.
	 * @return array<string,int>
	 */
	private function load( int $now ): array {
		$stored = get_option( self::OPTION, array() );
		$seen   = array();

		foreach ( is_array( $stored ) ? $stored : array() as $key => $time ) {
			if ( is_string( $key ) && is_int( $time ) && $now - $time < self::KEEP ) {
				$seen[ $key ] = $time;
			}
		}

		return $seen;
	}
}
