<?php
/**
 * Everything the Overview tab shows.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Admin;

use Povikvane\WooCommerce\Balance\Balance;
use Povikvane\WooCommerce\Reports\DeliveryReport;
use Povikvane\WooCommerce\Log\Message;

defined( 'ABSPATH' ) || exit;

/**
 * Gathered once by the settings screen, so the panel only draws.
 */
final class OverviewData {

	/**
	 * Constructor.
	 *
	 * @param string                                                                                                  $connection     One of the Capabilities::CONNECTION_* values.
	 * @param int                                                                                                     $checked_at     When the connection was last checked (0 = never).
	 * @param bool                                                                                                    $balance_known  Whether the service offers the balance endpoint.
	 * @param Balance|null                                                                                            $balance        Last known balance.
	 * @param int                                                                                                     $low_threshold  Credits below which a warning shows (0 = none).
	 * @param string|null                                                                                             $paused         Pause reason, or null when sending is active.
	 * @param array{time:int,result:string}|null                                                                      $last_report    The most recent delivery report, if any.
	 * @param int                                                                                                     $queue_late     Seconds the oldest waiting message is overdue.
	 * @param Message[]                                                                                               $recent         Latest messages.
	 * @param array<int,array{id:string,done:bool,required:bool}>                                                     $checklist      Setup checklist.
	 * @param string                                                                                                  $wizard_url     Address of the setup wizard.
	 * @param string                                                                                                  $log_url        Address of the message log.
	 * @param string                                                                                                  $phone_default  Country calling code hint for the test phone box.
	 * @param DeliveryReport|null                                                                                     $report         Results of the chosen period.
	 * @param array<int,array{key:string,tone:string,title:string,text:string,label:string,url:string,external:bool}> $attention What needs attention.
	 * @param int                                                                                                     $range_days     Length of the reported period in days.
	 * @param string                                                                                                  $overview_url   Address of the Overview, for the period links.
	 */
	public function __construct(
		public readonly string $connection,
		public readonly int $checked_at,
		public readonly bool $balance_known,
		public readonly ?Balance $balance,
		public readonly int $low_threshold,
		public readonly ?string $paused,
		public readonly ?array $last_report,
		public readonly int $queue_late,
		public readonly array $recent,
		public readonly array $checklist,
		public readonly string $wizard_url,
		public readonly string $log_url,
		public readonly string $phone_default = '',
		public readonly ?DeliveryReport $report = null,
		public readonly array $attention = array(),
		public readonly int $range_days = 30,
		public readonly string $overview_url = ''
	) {}
}
