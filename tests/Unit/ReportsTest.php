<?php
/**
 * Tests for delivery reports, plain-language errors, attention items and the support report.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Povikvane\WooCommerce\Admin\AttentionItems;
use Povikvane\WooCommerce\Api\Capabilities;
use Povikvane\WooCommerce\Log\Message;
use Povikvane\WooCommerce\Messages\ErrorExplainer;
use Povikvane\WooCommerce\Messages\Status;
use Povikvane\WooCommerce\Reports\DeliveryReport;
use Povikvane\WooCommerce\Support\SendingState;
use Povikvane\WooCommerce\Support\SupportReport;

/**
 * What the Overview and the support box say.
 */
final class ReportsTest extends TestCase {

	/**
	 * One grouped row.
	 *
	 * @param string      $status  Status.
	 * @param int         $count   Count.
	 * @param float       $credits Credits.
	 * @param string      $channel Channel.
	 * @param string|null $via     Delivering channel.
	 * @return array{status:string,channel:string,delivered_via:?string,count:int,credits:float}
	 */
	private function row( string $status, int $count, float $credits = 0.0, string $channel = 'sms', ?string $via = null ): array {
		return array(
			'status'        => $status,
			'channel'       => $channel,
			'delivered_via' => $via,
			'count'         => $count,
			'credits'       => $credits,
		);
	}

	/**
	 * Counts, rate, credits and Viber use are worked out from grouped rows.
	 */
	public function test_report_from_rows(): void {
		$report = DeliveryReport::from_rows(
			array(
				$this->row( 'delivered', 8, 8.0 ),
				$this->row( 'delivered', 3, 2.4, 'viber', 'viber' ),
				$this->row( 'delivered', 2, 2.0, 'viber', 'sms' ),
				$this->row( 'failed', 1, 0.0 ),
				$this->row( 'unconfirmed', 2, 2.0 ),
				$this->row( 'queued', 1, 1.0 ),
				$this->row( 'pending', 1 ),
				$this->row( 'skipped', 4, 0.0, 'viber' ),
				$this->row( 'expired', 1 ),
			)
		);

		$this->assertSame( 13, $report->delivered );
		$this->assertSame( 1, $report->failed );
		$this->assertSame( 2, $report->unconfirmed );
		$this->assertSame( 2, $report->in_flight );
		$this->assertSame( 4, $report->skipped );
		$this->assertSame( 16, $report->finished() );
		$this->assertSame( 18, $report->sent() );
		$this->assertSame( 81.3, $report->rate() );
		$this->assertSame( 15.4, $report->credits );
		$this->assertSame( 5, $report->viber_sent, 'skipped Viber messages are not counted' );
		$this->assertSame( 3, $report->viber_delivered );
		$this->assertSame( 2, $report->fallbacks );
	}

	/**
	 * Nothing finished yet means no rate, not zero.
	 */
	public function test_empty_report(): void {
		$report = DeliveryReport::from_rows( array( $this->row( 'queued', 2 ) ) );

		$this->assertNull( $report->rate() );
		$this->assertSame( 2, $report->sent() );
		$this->assertSame( 0, DeliveryReport::from_rows( array() )->sent() );
	}

	/**
	 * Every stored error code reads as a sentence.
	 */
	public function test_errors_are_explained(): void {
		$message         = new Message();
		$message->status = Status::Failed;

		foreach ( array( 'delivery_failed', 'http_502', 'http_400', 'http_422', 'http_401', 'idempotency_mismatch', 'network', null ) as $code ) {
			$message->error_code = $code;
			$text                = ErrorExplainer::explain( $message );

			$this->assertNotSame( '', $text );
			$this->assertStringNotContainsString( 'http_', $text, (string) $code );
		}

		$message->status     = Status::Unconfirmed;
		$message->error_code = 'http_504';
		$this->assertStringContainsString( 'confirm', ErrorExplainer::explain( $message ) );
	}

	/**
	 * Build attention items for a situation.
	 *
	 * @param array<string,mixed> $o Overrides.
	 * @return string[] Titles.
	 */
	private function titles( array $o = array() ): array {
		$o = array_merge(
			array(
				'connection' => Capabilities::CONNECTION_OK,
				'paused'     => null,
				'credits'    => 100.0,
				'threshold'  => 50,
				'late'       => 0,
				'report'     => DeliveryReport::from_rows( array() ),
				'webhook'    => true,
				'fallbacks'  => 0,
				'viber'      => Capabilities::VIBER_READY,
			),
			$o
		);

		$items = AttentionItems::build(
			$o['connection'],
			$o['paused'],
			$o['credits'],
			$o['threshold'],
			$o['late'],
			$o['report'],
			$o['webhook'],
			$o['fallbacks'],
			$o['viber'],
			array(
				'settings' => '#s',
				'messages' => '#m',
				'failed'   => '#f',
				'app'      => '#a',
			)
		);

		return array_column( $items, 'title' );
	}

	/**
	 * A healthy shop has nothing to look at.
	 */
	public function test_nothing_needs_attention_when_healthy(): void {
		$this->assertSame( array(), $this->titles() );
	}

	/**
	 * Each problem shows up, with its own wording.
	 */
	public function test_problems_are_listed(): void {
		$this->assertCount(
			1,
			$this->titles(
				array(
					'paused'  => SendingState::REASON_CREDITS,
					'credits' => 0.0,
				)
			),
			'no credits, once'
		);
		$this->assertCount(
			1,
			$this->titles(
				array(
					'paused'     => SendingState::REASON_AUTH,
					'connection' => Capabilities::CONNECTION_AUTH,
				)
			),
			'key rejected, once'
		);
		$this->assertCount( 1, $this->titles( array( 'credits' => 20.0 ) ), 'low credits' );
		$this->assertCount(
			0,
			$this->titles(
				array(
					'credits'   => 20.0,
					'threshold' => 0,
				)
			),
			'warning switched off'
		);
		$this->assertCount( 1, $this->titles( array( 'late' => 900 ) ), 'late queue' );
		$this->assertCount( 1, $this->titles( array( 'fallbacks' => 2 ) ), 'Viber statuses going as SMS' );

		$failing = DeliveryReport::from_rows( array( $this->row( 'delivered', 6 ), $this->row( 'failed', 4 ) ) );
		$this->assertCount( 1, $this->titles( array( 'report' => $failing ) ), 'many failures' );

		$few = DeliveryReport::from_rows( array( $this->row( 'failed', 2 ) ) );
		$this->assertCount( 0, $this->titles( array( 'report' => $few ) ), 'too few messages to judge' );

		$polled = DeliveryReport::from_rows( array( $this->row( 'delivered', 5 ) ) );
		$this->assertCount(
			1,
			$this->titles(
				array(
					'report'  => $polled,
					'webhook' => false,
				)
			),
			'no webhook although messages were delivered'
		);
		$this->assertCount(
			0,
			$this->titles(
				array(
					'report'  => $polled,
					'webhook' => true,
				)
			)
		);
	}

	/**
	 * The support text keeps to the facts it is given.
	 */
	public function test_support_report_format(): void {
		$text = SupportReport::format(
			array(
				'Site'   => array(
					'Plugin'   => '0.7.0',
					'Cron off' => false,
					'Paused'   => null,
				),
				'Latest' => array( 'one line', 'another' ),
			)
		);

		$this->assertSame( "== Site ==\nPlugin: 0.7.0\nCron off: no\nPaused: -\n\n== Latest ==\none line\nanother\n", $text );
	}
}
