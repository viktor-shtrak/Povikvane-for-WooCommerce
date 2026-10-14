<?php
/**
 * Several gates in a row.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Messages;

use Povikvane\WooCommerce\Log\Message;

defined( 'ABSPATH' ) || exit;

/**
 * The first gate that blocks a message decides.
 */
final class CompositeGate implements SendGate {

	/**
	 * Gates in order.
	 *
	 * @var SendGate[]
	 */
	private array $gates;

	/**
	 * Constructor.
	 *
	 * @param SendGate ...$gates Gates, checked in order.
	 */
	public function __construct( SendGate ...$gates ) {
		$this->gates = $gates;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Message $message Message about to be sent.
	 */
	public function blocks( Message $message ): ?string {
		foreach ( $this->gates as $gate ) {
			$reason = $gate->blocks( $message );

			if ( null !== $reason ) {
				return $reason;
			}
		}

		return null;
	}
}
