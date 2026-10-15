<?php
/**
 * Wording for Viber availability and templates.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Admin;

use Povikvane\WooCommerce\Api\Capabilities;
use Povikvane\WooCommerce\Viber\ViberPlanner;

defined( 'ABSPATH' ) || exit;

/**
 * One place for the reasons shown on the Messages and Connection tabs.
 */
final class ViberView {

	/**
	 * Why Viber is not offered.
	 *
	 * @param string $status A Capabilities::VIBER_* constant.
	 */
	public static function reason( string $status ): string {
		return match ( $status ) {
			Capabilities::VIBER_READY            => __( 'Viber is available on your account.', 'povikvane-for-woocommerce' ),
			Capabilities::VIBER_TEMPLATES_OFF    => __( 'Viber is not available yet: Viber templates are not switched on for your Povikvane account. Order messages go out as SMS. This page offers Viber as soon as it is available.', 'povikvane-for-woocommerce' ),
			Capabilities::VIBER_FALLBACK_REFUSED => __( 'Viber is paused: Povikvane has not enabled the SMS fallback for your account yet, so order messages go out as SMS. The plugin tries again every day.', 'povikvane-for-woocommerce' ),
			default                              => __( 'Viber has not been checked yet. Open the Connection tab and press "Check connection".', 'povikvane-for-woocommerce' ),
		};
	}

	/**
	 * Short reason a template cannot be used.
	 *
	 * @param string $problem A ViberPlanner::PROBLEM_* constant.
	 */
	public static function problem_label( string $problem ): string {
		return match ( $problem ) {
			ViberPlanner::PROBLEM_NOT_APPROVED      => __( 'awaiting approval', 'povikvane-for-woocommerce' ),
			ViberPlanner::PROBLEM_NOT_TRANSACTIONAL => __( 'not transactional', 'povikvane-for-woocommerce' ),
			ViberPlanner::PROBLEM_UNMAPPED          => __( 'has a variable the plugin cannot fill', 'povikvane-for-woocommerce' ),
			default                                 => __( 'contains a link', 'povikvane-for-woocommerce' ),
		};
	}
}
