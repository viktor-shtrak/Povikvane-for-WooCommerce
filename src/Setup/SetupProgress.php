<?php
/**
 * Works out how far setup has got from the plugin's real state.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Setup;

use Povikvane\WooCommerce\Api\Capabilities;
use Povikvane\WooCommerce\Log\Repository;
use Povikvane\WooCommerce\Orders\TriggerRepository;
use Povikvane\WooCommerce\Webhook\WebhookActivity;

defined( 'ABSPATH' ) || exit;

/**
 * Gathers the facts the checklist needs.
 */
final class SetupProgress {

	/**
	 * Constructor.
	 *
	 * @param TriggerRepository $triggers   Trigger configuration.
	 * @param Repository        $repository Message log.
	 * @param WebhookActivity   $activity   Last delivery report.
	 */
	public function __construct(
		private readonly TriggerRepository $triggers,
		private readonly Repository $repository,
		private readonly WebhookActivity $activity
	) {}

	/**
	 * The checklist for the current state.
	 *
	 * @return array<int,array{id:string,done:bool,required:bool}>
	 */
	public function checklist(): array {
		$caps      = get_option( Capabilities::OPTION, array() );
		$connected = is_array( $caps ) && Capabilities::CONNECTION_OK === ( $caps['connection'] ?? '' );

		return SetupChecklist::evaluate( $connected, $this->enabled_statuses(), $this->test_was_sent(), null !== $this->activity->last() );
	}

	/**
	 * Number of order statuses that send a message.
	 */
	private function enabled_statuses(): int {
		$count = 0;

		foreach ( array_keys( $this->triggers->statuses() ) as $slug ) {
			if ( $this->triggers->for_status( $slug )->enabled ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Whether a test message was accepted by the service.
	 */
	private function test_was_sent(): bool {
		foreach ( $this->repository->for_order( 0, 20 ) as $row ) {
			if ( 'test' === $row->event && null !== $row->api_message_id ) {
				return true;
			}
		}

		return false;
	}
}
