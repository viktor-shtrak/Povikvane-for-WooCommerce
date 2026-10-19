<?php
/**
 * Consent rules.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Marketing;

use Povikvane\WooCommerce\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Whether a number agreed to promotional messages. The newest decision wins, a
 * missing record means no consent, and a withdrawal is never overridden by an
 * older agreement.
 */
final class Consent {

	/**
	 * Constructor.
	 *
	 * @param ConsentLedger $ledger Stores events.
	 * @param Clock         $clock  Time source.
	 * @param string        $secret Key for hashing numbers.
	 * @param \Closure|null $on_withdrawn Called with the number and source after a withdrawal.
	 */
	public function __construct(
		private readonly ConsentLedger $ledger,
		private readonly Clock $clock,
		private readonly string $secret,
		private readonly ?\Closure $on_withdrawn = null
	) {}

	/**
	 * Keyed hash of a number, so records can be matched without keeping the number.
	 *
	 * @param string $e164 Number including "+".
	 */
	public function hash( string $e164 ): string {
		return substr( hash_hmac( 'sha256', $e164, $this->secret ), 0, 12 );
	}

	/**
	 * Whether the number currently agrees to promotional messages.
	 *
	 * @param string $e164 Number including "+".
	 */
	public function has_consent( string $e164 ): bool {
		$latest = $this->ledger->latest( $this->hash( $e164 ) );

		return null !== $latest && $latest->granted;
	}

	/**
	 * Record an agreement, unless the number already agrees.
	 *
	 * @param string      $e164         Number including "+".
	 * @param string|null $email        Customer email.
	 * @param string      $source       A ConsentEvent SOURCE_* constant.
	 * @param int         $order_id     Order, if any.
	 * @param int         $user_id      Customer account, if any.
	 * @param string      $wording_hash Hash of the wording shown.
	 * @return bool True when a new event was recorded.
	 */
	public function grant( string $e164, ?string $email, string $source, int $order_id, int $user_id, string $wording_hash ): bool {
		if ( $this->has_consent( $e164 ) ) {
			return false;
		}

		$this->ledger->append( new ConsentEvent( $this->hash( $e164 ), true, $source, $e164, $email, $order_id, $user_id, $wording_hash, $this->clock->now() ) );

		return true;
	}

	/**
	 * Record a withdrawal for a number.
	 *
	 * @param string $e164   Number including "+".
	 * @param string $source A ConsentEvent SOURCE_* constant.
	 * @param int    $user_id Customer account, if any.
	 * @return bool True when a new event was recorded.
	 */
	public function revoke( string $e164, string $source, int $user_id = 0 ): bool {
		$hash   = $this->hash( $e164 );
		$latest = $this->ledger->latest( $hash );

		if ( null !== $latest && ! $latest->granted ) {
			return false;
		}

		$this->ledger->append( new ConsentEvent( $hash, false, $source, $e164, $latest?->email, 0, $user_id, '', $this->clock->now() ) );
		$this->withdrawn( $e164, $source );

		return true;
	}

	/**
	 * Record a withdrawal when only the hash is known (the opt-out link).
	 *
	 * @param string $phone_hash Keyed hash of the number.
	 * @param string $source     A ConsentEvent SOURCE_* constant.
	 * @return bool True when a new event was recorded.
	 */
	public function revoke_by_hash( string $phone_hash, string $source ): bool {
		$latest = $this->ledger->latest( $phone_hash );

		if ( null !== $latest && ! $latest->granted ) {
			return false;
		}

		$this->ledger->append( new ConsentEvent( $phone_hash, false, $source, $latest?->phone, $latest?->email, 0, $latest->user_id ?? 0, '', $this->clock->now() ) );
		$this->withdrawn( $latest?->phone, $source );

		return true;
	}

	/**
	 * Tell whoever listens that a customer withdrew.
	 *
	 * @param string|null $e164   Number, when known.
	 * @param string      $source Where the withdrawal came from.
	 */
	private function withdrawn( ?string $e164, string $source ): void {
		if ( null !== $this->on_withdrawn && null !== $e164 && '' !== $e164 ) {
			( $this->on_withdrawn )( $e164, $source );
		}
	}
}
