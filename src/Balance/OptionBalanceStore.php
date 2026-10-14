<?php
/**
 * Option-backed balance store.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Balance;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the balance and the last alert time in options (not autoloaded).
 */
final class OptionBalanceStore implements BalanceStore {

	public const OPTION       = 'povikvane_balance';
	public const ALERT_OPTION = 'povikvane_balance_alert';

	/**
	 * {@inheritDoc}
	 */
	public function get(): ?Balance {
		$data = get_option( self::OPTION );

		if ( ! is_array( $data ) || ! isset( $data['available'], $data['fetched_at'] ) ) {
			return null;
		}

		return new Balance(
			(float) $data['available'],
			(float) ( $data['locked'] ?? 0 ),
			is_array( $data['channels'] ?? null ) ? array_values( array_map( 'strval', $data['channels'] ) ) : array(),
			(int) $data['fetched_at'],
			! empty( $data['estimated'] )
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Balance $balance Balance.
	 */
	public function save( Balance $balance ): void {
		update_option(
			self::OPTION,
			array(
				'available'  => $balance->available,
				'locked'     => $balance->locked,
				'channels'   => $balance->channels,
				'fetched_at' => $balance->fetched_at,
				'estimated'  => $balance->estimated,
			),
			false
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function alert_sent_at(): ?int {
		$time = get_option( self::ALERT_OPTION );

		return is_numeric( $time ) && (int) $time > 0 ? (int) $time : null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $time Unix time.
	 */
	public function mark_alert_sent( int $time ): void {
		update_option( self::ALERT_OPTION, $time, false );
	}

	/**
	 * {@inheritDoc}
	 */
	public function clear_alert(): void {
		delete_option( self::ALERT_OPTION );
	}
}
