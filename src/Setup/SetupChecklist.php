<?php
/**
 * What is left to set up.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Setup;

defined( 'ABSPATH' ) || exit;

/**
 * Works out the setup checklist from the plugin's real state, not from flags the
 * shop owner has to tick, so it can never claim something is done when it is not.
 */
final class SetupChecklist {

	public const CONNECT  = 'connect';
	public const MESSAGES = 'messages';
	public const TEST     = 'test';
	public const REPORTS  = 'reports';

	/**
	 * Steps that must be done before setup counts as complete.
	 */
	private const REQUIRED = array( self::CONNECT, self::MESSAGES, self::TEST );

	/**
	 * Evaluate the checklist.
	 *
	 * @param bool $connected        The service accepted the API key and service ID.
	 * @param int  $enabled_statuses Number of order statuses that send a message.
	 * @param bool $test_sent        A test message was accepted by the service.
	 * @param bool $report_received  A delivery report has reached this site.
	 * @return array<int,array{id:string,done:bool,required:bool}>
	 */
	public static function evaluate( bool $connected, int $enabled_statuses, bool $test_sent, bool $report_received ): array {
		$state = array(
			self::CONNECT  => $connected,
			self::MESSAGES => $enabled_statuses > 0,
			self::TEST     => $test_sent,
			self::REPORTS  => $report_received,
		);

		$items = array();

		foreach ( $state as $id => $done ) {
			$items[] = array(
				'id'       => $id,
				'done'     => $done,
				'required' => in_array( $id, self::REQUIRED, true ),
			);
		}

		return $items;
	}

	/**
	 * Whether every required step is done.
	 *
	 * @param array<int,array{id:string,done:bool,required:bool}> $items Result of evaluate().
	 */
	public static function is_complete( array $items ): bool {
		foreach ( $items as $item ) {
			if ( $item['required'] && ! $item['done'] ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Share of steps done, from 0 to 100.
	 *
	 * @param array<int,array{id:string,done:bool,required:bool}> $items Result of evaluate().
	 */
	public static function percent( array $items ): int {
		if ( array() === $items ) {
			return 0;
		}

		$done = count( array_filter( $items, static fn( array $item ): bool => $item['done'] ) );

		return (int) round( $done / count( $items ) * 100 );
	}

	/**
	 * The first required step that is not done, or null.
	 *
	 * @param array<int,array{id:string,done:bool,required:bool}> $items Result of evaluate().
	 */
	public static function next_step( array $items ): ?string {
		foreach ( $items as $item ) {
			if ( $item['required'] && ! $item['done'] ) {
				return $item['id'];
			}
		}

		return null;
	}
}
