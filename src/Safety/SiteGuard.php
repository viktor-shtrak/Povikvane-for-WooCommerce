<?php
/**
 * Detects a copied site.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Safety;

use Povikvane\WooCommerce\Support\SendingState;

defined( 'ABSPATH' ) || exit;

/**
 * Remembers the address the plugin was set up on. A staging copy of a shop's
 * database carries the live API key but has a different address, so sending is
 * paused there until someone confirms the site is the real one.
 */
final class SiteGuard {

	public const OPTION = 'povikvane_site_fingerprint';

	/**
	 * Constructor.
	 *
	 * @param SendingState $state Pause switch.
	 */
	public function __construct( private readonly SendingState $state ) {}

	/**
	 * Store the address on first use; pause sending if it has changed since.
	 */
	public function check(): void {
		$current = Fingerprint::of( home_url() );
		$stored  = get_option( self::OPTION );

		if ( ! is_string( $stored ) || '' === $stored ) {
			update_option( self::OPTION, $current, false );
			return;
		}

		if ( $stored !== $current && SendingState::REASON_SITE !== $this->state->paused_reason() ) {
			$this->state->pause( SendingState::REASON_SITE );
		}
	}

	/**
	 * The administrator confirmed this is the live site: accept the current address.
	 */
	public function confirm_live(): void {
		update_option( self::OPTION, Fingerprint::of( home_url() ), false );

		if ( SendingState::REASON_SITE === $this->state->paused_reason() ) {
			$this->state->resume();
		}
	}
}
