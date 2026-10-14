<?php
/**
 * Option-backed webhook activity.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Webhook;

defined( 'ABSPATH' ) || exit;

/**
 * Stores the last webhook arrival in an option.
 */
final class OptionActivity implements WebhookActivity {

	public const OPTION = 'povikvane_webhook_activity';

	/**
	 * {@inheritDoc}
	 *
	 * @param int    $time   Unix time.
	 * @param string $result Result code.
	 */
	public function record( int $time, string $result ): void {
		update_option(
			self::OPTION,
			array(
				'time'   => $time,
				'result' => $result,
			),
			false
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function last(): ?array {
		$data = get_option( self::OPTION );

		if ( ! is_array( $data ) || ! isset( $data['time'], $data['result'] ) ) {
			return null;
		}

		return array(
			'time'   => (int) $data['time'],
			'result' => (string) $data['result'],
		);
	}
}
