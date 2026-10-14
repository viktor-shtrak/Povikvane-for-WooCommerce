<?php
/**
 * Balance text for the admin.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Admin;

use Povikvane\WooCommerce\Support\Dates;
use Povikvane\WooCommerce\Balance\Balance;

defined( 'ABSPATH' ) || exit;

/**
 * One place that words the balance, so the page and the live refresh agree.
 */
final class BalanceView {

	/**
	 * Describe a balance, for example "142.8 (as of 14:32)".
	 *
	 * @param Balance|null $balance Balance, if known.
	 */
	public static function text( ?Balance $balance ): string {
		if ( null === $balance ) {
			return __( 'Not known yet', 'povikvane-for-woocommerce' );
		}

		$amount = number_format_i18n( $balance->available, 1 );

		if ( $balance->estimated ) {
			return sprintf(
				/* translators: %s: estimated credits. */
				__( '≈ %s (updating…)', 'povikvane-for-woocommerce' ),
				$amount
			);
		}

		return sprintf(
			/* translators: 1: credits, 2: time of day the balance was fetched. */
			__( '%1$s (as of %2$s)', 'povikvane-for-woocommerce' ),
			$amount,
			Dates::format( $balance->fetched_at, 'H:i' )
		);
	}

	/**
	 * Just the figure, for example "142.8".
	 *
	 * @param Balance|null $balance Balance, if known.
	 */
	public static function amount( ?Balance $balance ): string {
		if ( null === $balance ) {
			return '—';
		}

		return ( $balance->estimated ? '≈ ' : '' ) . number_format_i18n( $balance->available, 1 );
	}

	/**
	 * The line under the figure, for example "as of 14:32".
	 *
	 * @param Balance|null $balance Balance, if known.
	 */
	public static function hint( ?Balance $balance ): string {
		if ( null === $balance ) {
			return __( 'Not known yet', 'povikvane-for-woocommerce' );
		}

		if ( $balance->estimated ) {
			return __( 'updating…', 'povikvane-for-woocommerce' );
		}

		return sprintf(
			/* translators: %s: time of day the balance was fetched. */
			__( 'as of %s', 'povikvane-for-woocommerce' ),
			Dates::format( $balance->fetched_at, 'H:i' )
		);
	}
}
