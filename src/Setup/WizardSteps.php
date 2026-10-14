<?php
/**
 * The order of the setup wizard's steps.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Setup;

defined( 'ABSPATH' ) || exit;

/**
 * Which steps there are, and how to move between them.
 */
final class WizardSteps {

	public const WELCOME  = 'welcome';
	public const CONNECT  = 'connect';
	public const MESSAGES = 'messages';
	public const TEST     = 'test';
	public const REPORTS  = 'reports';
	public const DONE     = 'done';

	/**
	 * Steps in order.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array( self::WELCOME, self::CONNECT, self::MESSAGES, self::TEST, self::REPORTS, self::DONE );
	}

	/**
	 * A requested step, or the first one if it is not valid.
	 *
	 * @param string $step Requested step.
	 */
	public static function normalize( string $step ): string {
		return in_array( $step, self::all(), true ) ? $step : self::WELCOME;
	}

	/**
	 * The step after another, or the last step if there is none.
	 *
	 * @param string $step Current step.
	 */
	public static function next( string $step ): string {
		$steps = self::all();
		$index = array_search( self::normalize( $step ), $steps, true );

		return $steps[ min( (int) $index + 1, count( $steps ) - 1 ) ];
	}

	/**
	 * The step before another, or the first step if there is none.
	 *
	 * @param string $step Current step.
	 */
	public static function previous( string $step ): string {
		$steps = self::all();
		$index = array_search( self::normalize( $step ), $steps, true );

		return $steps[ max( (int) $index - 1, 0 ) ];
	}

	/**
	 * Position of a step, starting at 1.
	 *
	 * @param string $step Step.
	 */
	public static function position( string $step ): int {
		return (int) array_search( self::normalize( $step ), self::all(), true ) + 1;
	}
}
