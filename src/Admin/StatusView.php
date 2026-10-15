<?php
/**
 * How message statuses look.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Admin;

use Povikvane\WooCommerce\Messages\Status;

defined( 'ABSPATH' ) || exit;

/**
 * One place for status wording and colours.
 */
final class StatusView {

	/**
	 * Human label for a status.
	 *
	 * @param Status $status Status.
	 */
	public static function label( Status $status ): string {
		return match ( $status ) {
			Status::Pending     => __( 'Waiting', 'povikvane-for-woocommerce' ),
			Status::Sending     => __( 'Sending', 'povikvane-for-woocommerce' ),
			Status::Queued      => __( 'Accepted', 'povikvane-for-woocommerce' ),
			Status::Delivered   => __( 'Delivered', 'povikvane-for-woocommerce' ),
			Status::Failed      => __( 'Failed', 'povikvane-for-woocommerce' ),
			Status::Unconfirmed => __( 'Unconfirmed', 'povikvane-for-woocommerce' ),
			Status::Skipped     => __( 'Skipped', 'povikvane-for-woocommerce' ),
			Status::HeldCredits => __( 'On hold (no credits)', 'povikvane-for-woocommerce' ),
			Status::Expired     => __( 'Expired', 'povikvane-for-woocommerce' ),
		};
	}

	/**
	 * Colour tone for a status.
	 *
	 * @param Status $status Status.
	 */
	public static function tone( Status $status ): string {
		return match ( $status ) {
			Status::Delivered                          => 'ok',
			Status::Queued, Status::Sending            => 'info',
			Status::Failed                             => 'bad',
			Status::Unconfirmed, Status::HeldCredits   => 'warn',
			default                                    => '',
		};
	}

	/**
	 * Whether a message no longer needs to be watched.
	 *
	 * @param Status $status Status.
	 */
	public static function is_settled( Status $status ): bool {
		return $status->is_final() || Status::Unconfirmed === $status;
	}

	/**
	 * A status label that the page keeps current while the message is still on its way.
	 *
	 * @param int    $id     Message ID.
	 * @param Status $status Status.
	 * @return string HTML, escaped.
	 */
	public static function live_pill( int $id, Status $status ): string {
		return '<span data-pvk-live="' . (int) $id . '" data-pvk-settled="' . ( self::is_settled( $status ) ? '1' : '0' ) . '">' . self::pill( $status ) . '</span>';
	}

	/**
	 * A coloured status label.
	 *
	 * @param Status $status Status.
	 * @return string HTML, escaped.
	 */
	public static function pill( Status $status ): string {
		return Ui::pill( self::label( $status ), self::tone( $status ) );
	}
}
