<?php
/**
 * Refreshes the balance from the service.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Balance;

use Povikvane\WooCommerce\Api\Gateway;
use Povikvane\WooCommerce\Api\Outcome;
use Povikvane\WooCommerce\Support\Clock;
use Povikvane\WooCommerce\Support\Logger;
use Povikvane\WooCommerce\Support\NullLogger;
use Povikvane\WooCommerce\Support\SendingState;

defined( 'ABSPATH' ) || exit;

/**
 * Asks the service for the balance, stores it, lifts a "no credits" pause once
 * credits are back, and triggers the low-balance alert. A failed or unavailable
 * call leaves the stored balance as it was.
 */
final class BalanceRefresher {

	/**
	 * Constructor.
	 *
	 * @param Gateway         $gateway API gateway.
	 * @param BalanceStore    $store   Balance store.
	 * @param SendingState    $state   Pause switch.
	 * @param Clock           $clock   Time source.
	 * @param LowBalanceAlert $alert   Low-balance alert rules.
	 * @param Logger          $logger  Diagnostic logger.
	 */
	public function __construct(
		private readonly Gateway $gateway,
		private readonly BalanceStore $store,
		private readonly SendingState $state,
		private readonly Clock $clock,
		private readonly LowBalanceAlert $alert,
		private readonly Logger $logger = new NullLogger()
	) {}

	/**
	 * Fetch and store the balance.
	 *
	 * @return Balance|null The new balance, or null if the service gave none.
	 */
	public function refresh(): ?Balance {
		$result = $this->gateway->account();

		if ( Outcome::Success !== $result->outcome ) {
			$this->logger->log( Logger::INFO, 'Balance not refreshed', array( 'outcome' => $result->outcome->value ) );
			return null;
		}

		return $this->apply( $result->attributes() );
	}

	/**
	 * Store a balance reported by the service.
	 *
	 * @param array<string,mixed> $attributes The `data.attributes` of the account response.
	 * @return Balance|null The stored balance, or null if the response had no usable figure.
	 */
	public function apply( array $attributes ): ?Balance {
		if ( ! isset( $attributes['credits-available'] ) || ! is_numeric( $attributes['credits-available'] ) ) {
			return null;
		}

		$channels = isset( $attributes['channels'] ) && is_array( $attributes['channels'] )
			? array_values( array_map( 'strval', $attributes['channels'] ) )
			: array();

		$balance = new Balance(
			(float) $attributes['credits-available'],
			(float) ( $attributes['credits-locked'] ?? 0 ),
			$channels,
			$this->clock->now()
		);

		$this->store->save( $balance );

		if ( $balance->available > 0 && SendingState::REASON_CREDITS === $this->state->paused_reason() ) {
			$this->state->resume();
		}

		$this->alert->check( $balance );
		$this->logger->log( Logger::DEBUG, 'Balance refreshed', array( 'available' => $balance->available ) );

		return $balance;
	}
}
