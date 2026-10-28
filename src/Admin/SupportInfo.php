<?php
/**
 * Gathers the support information.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Admin;

use Povikvane\WooCommerce\Api\Capabilities;
use Povikvane\WooCommerce\Balance\BalanceStore;
use Povikvane\WooCommerce\Marketing\MarketingSettings;
use Povikvane\WooCommerce\Reports\DeliveryReport;
use Povikvane\WooCommerce\Reports\ReportSource;
use Povikvane\WooCommerce\Support\Dates;
use Povikvane\WooCommerce\Support\Language;
use Povikvane\WooCommerce\Support\SendingState;
use Povikvane\WooCommerce\Support\Settings;
use Povikvane\WooCommerce\Support\SupportReport;
use Povikvane\WooCommerce\Webhook\WebhookActivity;

use const Povikvane\WooCommerce\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Versions, connection and capability state, settings and recent results: enough for
 * support to see what is going on without access to the shop.
 */
final class SupportInfo {

	/**
	 * Constructor.
	 *
	 * @param Settings          $settings  Settings.
	 * @param ReportSource      $source    Message log.
	 * @param SendingState      $state     Pause switch.
	 * @param WebhookActivity   $activity  Last delivery report.
	 * @param BalanceStore      $balances  Credit balance.
	 * @param MarketingSettings $marketing Promotion settings.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly ReportSource $source,
		private readonly SendingState $state,
		private readonly WebhookActivity $activity,
		private readonly BalanceStore $balances,
		private readonly MarketingSettings $marketing
	) {}

	/**
	 * The report as text.
	 */
	public function text(): string {
		$caps    = get_option( Capabilities::OPTION, array() );
		$caps    = is_array( $caps ) ? $caps : array();
		$values  = $this->settings->all();
		$balance = $this->balances->get();
		$last    = $this->activity->last();
		$report  = DeliveryReport::from_rows( $this->source->summary( time() - 7 * DAY_IN_SECONDS ) );
		$host    = wp_parse_url( home_url(), PHP_URL_HOST );
		$problem = array();

		foreach ( $this->source->recent_problems( 10 ) as $message ) {
			$problem[] = sprintf( '%s  %s  %s  %s  %s', Dates::format( $message->created_at, 'Y-m-d H:i' ), $message->event, $message->channel, $message->status->value, (string) $message->error_code );
		}

		return SupportReport::format(
			array(
				'Site'        => array(
					'Plugin'          => VERSION,
					'WordPress'       => get_bloginfo( 'version' ),
					'WooCommerce'     => defined( 'WC_VERSION' ) ? WC_VERSION : null,
					'PHP'             => PHP_VERSION,
					'Site host'       => is_string( $host ) ? $host : null,
					'Locale'          => get_locale(),
					'Plugin language' => Language::setting(),
					'WP-Cron off'     => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
				),
				'Connection'  => array(
					'Connection'           => (string) ( $caps['connection'] ?? 'unknown' ),
					'Last check'           => isset( $caps['checked_at'] ) ? Dates::format( (int) $caps['checked_at'], 'Y-m-d H:i' ) : null,
					'Balance known'        => true === ( $caps['account'] ?? null ),
					'Lookup'               => $caps['lookup'] ?? null,
					'Templates'            => $caps['templates'] ?? null,
					'SMS fallback refused' => $caps['fallback'] ?? null,
					'Viber'                => Capabilities::viber_status( $caps, $values['viber_sms_fallback'] ),
					'Viber switched on'    => $values['viber_enabled'],
					'Viber SMS fallback'   => $values['viber_sms_fallback'],
					'Custom field names'   => FieldLabelsPanel::plan()->state,
					'Account answer keys'  => is_array( $caps['balance'] ?? null ) ? implode( ', ', array_map( 'strval', array_keys( $caps['balance'] ) ) ) : null,
					'Credits'              => null === $balance ? null : $balance->available,
					'Sending paused'       => $this->state->paused_reason(),
					'Last webhook'         => null === $last ? null : Dates::format( $last['time'], 'Y-m-d H:i' ) . ' ' . $last['result'],
				),
				'Settings'    => array(
					'Default country'        => $values['default_country'],
					'Drop waiting after (h)' => $values['max_age_hours'],
					'Skip landlines'         => $values['mobile_only'],
					'Skip admin orders'      => $values['skip_admin_created'],
					'Max order age (d)'      => $values['max_order_age_days'],
					'Burst pause'            => $values['storm_threshold'],
					'Low credits'            => $values['low_balance_threshold'],
					'Keep text (d)'          => $values['retention_body_days'],
					'Keep records (d)'       => $values['retention_row_days'],
					'Debug logging'          => $values['debug_log'],
					'Promotions on'          => $this->marketing->enabled(),
				),
				'Last 7 days' => array(
					'Delivered'   => $report->delivered,
					'Failed'      => $report->failed,
					'Unconfirmed' => $report->unconfirmed,
					'In flight'   => $report->in_flight,
					'Skipped'     => $report->skipped,
					'Credits'     => $report->credits,
					'Viber sent'  => $report->viber_sent,
					'Fallbacks'   => $report->fallbacks,
				),
				'Latest problems (time, event, channel, status, code)' => $problem,
			)
		);
	}
}
