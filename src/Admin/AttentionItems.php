<?php
/**
 * What needs the shop owner's attention, with the next step for each.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Admin;

use Povikvane\WooCommerce\Api\Capabilities;
use Povikvane\WooCommerce\Reports\DeliveryReport;
use Povikvane\WooCommerce\Support\SendingState;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the current state into a short list of problems, each with the button that
 * fixes it, so nobody has to work out where to go.
 */
final class AttentionItems {

	private const OVERDUE_SECONDS = 600;
	private const MIN_FINISHED    = 5;
	private const FAILURE_SHARE   = 0.3;
	private const MIN_DELIVERED   = 3;

	/**
	 * Build the list.
	 *
	 * @param string               $connection       A Capabilities::CONNECTION_* value.
	 * @param string|null          $paused           Pause reason, or null.
	 * @param float|null           $credits          Credits left, if known.
	 * @param int                  $low_threshold    Warning level (0 = none).
	 * @param int                  $queue_late       Seconds the oldest waiting message is overdue.
	 * @param DeliveryReport       $report           Results of the period.
	 * @param bool                 $webhook_seen     Whether a delivery report ever arrived.
	 * @param int                  $viber_fallbacks  Statuses set to Viber that go out as SMS.
	 * @param string               $viber_status     A Capabilities::VIBER_* value.
	 * @param array<string,string> $urls             Addresses: settings, messages, failed, app.
	 * @return array<int,array{tone:string,title:string,text:string,label:string,url:string,external:bool}>
	 */
	public static function build( string $connection, ?string $paused, ?float $credits, int $low_threshold, int $queue_late, DeliveryReport $report, bool $webhook_seen, int $viber_fallbacks, string $viber_status, array $urls ): array {
		$items = array();

		if ( SendingState::REASON_CREDITS === $paused ) {
			$items[] = self::item( 'bad', __( 'Sending is on hold: no credits', 'povikvane-for-woocommerce' ), __( 'Messages wait and go out by themselves as soon as credits are added.', 'povikvane-for-woocommerce' ), __( 'Top up credits', 'povikvane-for-woocommerce' ), $urls['app'], true );
		}

		if ( SendingState::REASON_AUTH === $paused || Capabilities::CONNECTION_AUTH === $connection ) {
			$items[] = self::item( 'bad', __( 'Povikvane rejected the API key', 'povikvane-for-woocommerce' ), __( 'No messages are sent until the API key and service ID are correct.', 'povikvane-for-woocommerce' ), __( 'Check the connection', 'povikvane-for-woocommerce' ), $urls['settings'] );
		} elseif ( Capabilities::CONNECTION_CONFIG === $connection ) {
			$items[] = self::item( 'bad', __( 'Povikvane rejected the request', 'povikvane-for-woocommerce' ), __( 'The service ID is probably wrong.', 'povikvane-for-woocommerce' ), __( 'Check the connection', 'povikvane-for-woocommerce' ), $urls['settings'] );
		}

		if ( SendingState::REASON_CREDITS !== $paused && null !== $credits && $low_threshold > 0 && $credits < $low_threshold ) {
			$items[] = self::item(
				'warn',
				__( 'Credits are running low', 'povikvane-for-woocommerce' ),
				sprintf(
					/* translators: %s: credits left. */
					__( '%s credits are left. Top up before messages start waiting.', 'povikvane-for-woocommerce' ),
					number_format_i18n( $credits, 1 )
				),
				__( 'Top up credits', 'povikvane-for-woocommerce' ),
				$urls['app'],
				true
			);
		}

		$finished = $report->finished();
		$bad      = $report->failed + $report->unconfirmed;

		if ( $finished >= self::MIN_FINISHED && $bad / $finished >= self::FAILURE_SHARE ) {
			$items[] = self::item(
				'warn',
				__( 'Many messages did not arrive', 'povikvane-for-woocommerce' ),
				sprintf(
					/* translators: 1: messages that failed or could not be confirmed, 2: messages with a known result. */
					__( '%1$d of %2$d messages failed or could not be confirmed.', 'povikvane-for-woocommerce' ),
					$bad,
					$finished
				),
				__( 'See the failed messages', 'povikvane-for-woocommerce' ),
				$urls['failed']
			);
		}

		if ( ! $webhook_seen && $report->delivered >= self::MIN_DELIVERED ) {
			$items[] = self::item( 'warn', __( 'Delivery reports are not arriving instantly', 'povikvane-for-woocommerce' ), __( 'Statuses still update, only a few minutes late. Add the webhook address in Povikvane to get them straight away.', 'povikvane-for-woocommerce' ), __( 'Set up delivery reports', 'povikvane-for-woocommerce' ), $urls['settings'] );
		}

		if ( $viber_fallbacks > 0 ) {
			$items[] = self::item(
				'warn',
				sprintf(
					/* translators: %d: number of order statuses. */
					_n( '%d order status is set to Viber but goes out as SMS', '%d order statuses are set to Viber but go out as SMS', $viber_fallbacks, 'povikvane-for-woocommerce' ),
					$viber_fallbacks
				),
				Capabilities::VIBER_READY === $viber_status ? __( 'Choose an approved template and a value for each of its fields.', 'povikvane-for-woocommerce' ) : ViberView::reason( $viber_status ),
				__( 'Open order messages', 'povikvane-for-woocommerce' ),
				$urls['messages']
			);
		}

		if ( $queue_late > self::OVERDUE_SECONDS ) {
			$items[] = self::item(
				'warn',
				__( 'Scheduled tasks are running late', 'povikvane-for-woocommerce' ),
				sprintf(
					/* translators: %d: minutes. */
					__( 'The oldest waiting message is %d minutes overdue. Ask your host to run WP-Cron regularly.', 'povikvane-for-woocommerce' ),
					(int) floor( $queue_late / 60 )
				),
				'',
				''
			);
		}

		return $items;
	}

	/**
	 * One item.
	 *
	 * @param string $tone     "bad" or "warn".
	 * @param string $title    Short title.
	 * @param string $text     What it means.
	 * @param string $label    Button text, or empty for none.
	 * @param string $url      Button address.
	 * @param bool   $external Whether it opens Povikvane.
	 * @return array{tone:string,title:string,text:string,label:string,url:string,external:bool}
	 */
	private static function item( string $tone, string $title, string $text, string $label, string $url, bool $external = false ): array {
		return array(
			'tone'     => $tone,
			'title'    => $title,
			'text'     => $text,
			'label'    => $label,
			'url'      => $url,
			'external' => $external,
		);
	}
}
