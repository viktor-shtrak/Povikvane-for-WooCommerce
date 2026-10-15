<?php
/**
 * The Overview tab.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Admin;

use Povikvane\WooCommerce\Api\Capabilities;
use Povikvane\WooCommerce\Setup\SetupChecklist;
use Povikvane\WooCommerce\Messages\ErrorExplainer;
use Povikvane\WooCommerce\Messages\Status;
use Povikvane\WooCommerce\Support\Dates;
use Povikvane\WooCommerce\Support\Mask;
use Povikvane\WooCommerce\Support\SendingState;
use Povikvane\WooCommerce\Support\SkipLabels;
use Povikvane\WooCommerce\Webhook\WebhookHandler;

use const Povikvane\WooCommerce\PLUGIN_FILE;
use const Povikvane\WooCommerce\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Status at a glance: is it connected, are there credits, is sending on, do
 * delivery reports arrive, and what happened to the latest messages.
 */
final class OverviewPanel {


	/**
	 * Print the panel.
	 *
	 * @param OverviewData $data What to show.
	 */
	public function render( OverviewData $data ): void {
		$this->render_attention( $data );

		if ( ! SetupChecklist::is_complete( $data->checklist ) ) {
			$this->render_checklist( $data );
		}

		$this->render_tiles( $data );
		$this->render_report( $data );
		$this->render_test();
		$this->render_recent( $data );
	}

	/**
	 * The "finish setting up" card.
	 *
	 * @param OverviewData $data What to show.
	 */
	private function render_checklist( OverviewData $data ): void {
		$percent = SetupChecklist::percent( $data->checklist );

		Ui::card_open( __( 'Finish setting up', 'povikvane-for-woocommerce' ), __( 'A few steps and your customers get their order updates by text message.', 'povikvane-for-woocommerce' ), 'pvk-card--accent' );

		printf(
			'<div class="pvk-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="%1$d" aria-label="%2$s"><span style="width:%1$d%%"></span></div>',
			(int) $percent,
			esc_attr__( 'Setup progress', 'povikvane-for-woocommerce' )
		);

		echo '<ul class="pvk-checklist">';

		foreach ( $data->checklist as $item ) {
			list( $title, $hint, $tab ) = $this->describe_step( $item['id'] );

			printf(
				'<li><span class="pvk-check%1$s" aria-hidden="true">%2$s</span><span class="pvk-checklist__text"><strong>%3$s</strong>%4$s<small>%5$s</small></span>%6$s</li>',
				$item['done'] ? ' is-done' : '',
				$item['done'] ? '&#10003;' : '',
				esc_html( $title ),
				$item['required'] ? '' : ' ' . Ui::pill( __( 'Optional', 'povikvane-for-woocommerce' ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by Ui::pill().
				esc_html( $hint ),
				$item['done'] || '' === $tab ? '<span class="screen-reader-text">' . esc_html__( 'Done', 'povikvane-for-woocommerce' ) . '</span>' : '<a class="pvk-btn" href="#' . esc_attr( $tab ) . '" data-pvk-goto="' . esc_attr( $tab ) . '">' . esc_html__( 'Open', 'povikvane-for-woocommerce' ) . '</a>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped on this line.
			);
		}

		echo '</ul>';

		Ui::card_close(
			'<a class="pvk-btn pvk-btn--primary" href="' . esc_url( $data->wizard_url ) . '">' . esc_html__( 'Run the setup wizard', 'povikvane-for-woocommerce' ) . '</a>'
		);
	}

	/**
	 * Wording and target tab of a checklist step.
	 *
	 * @param string $id Step ID.
	 * @return array{string,string,string} Title, hint, tab to open ('' for none).
	 */
	private function describe_step( string $id ): array {
		return match ( $id ) {
			SetupChecklist::CONNECT  => array( __( 'Connect your Povikvane account', 'povikvane-for-woocommerce' ), __( 'Enter your API key and service ID.', 'povikvane-for-woocommerce' ), 'connection' ),
			SetupChecklist::MESSAGES => array( __( 'Choose which orders send a message', 'povikvane-for-woocommerce' ), __( 'Only "Completed" is switched on by default.', 'povikvane-for-woocommerce' ), 'messages' ),
			SetupChecklist::TEST     => array( __( 'Send a test message', 'povikvane-for-woocommerce' ), __( 'Check that a real SMS reaches your phone.', 'povikvane-for-woocommerce' ), '' ),
			default                  => array( __( 'Get delivery reports', 'povikvane-for-woocommerce' ), __( 'Add the webhook address in Povikvane to see "Delivered" as it happens.', 'povikvane-for-woocommerce' ), 'connection' ),
		};
	}

	/**
	 * The four status tiles.
	 *
	 * @param OverviewData $data What to show.
	 */
	private function render_tiles( OverviewData $data ): void {
		echo '<div class="pvk-tiles">';

		// Connection.
		list( $label, $tone ) = match ( $data->connection ) {
			Capabilities::CONNECTION_OK          => array( __( 'Connected', 'povikvane-for-woocommerce' ), 'ok' ),
			Capabilities::CONNECTION_AUTH        => array( __( 'Key rejected', 'povikvane-for-woocommerce' ), 'bad' ),
			Capabilities::CONNECTION_UNREACHABLE => array( __( 'Unreachable', 'povikvane-for-woocommerce' ), 'warn' ),
			Capabilities::CONNECTION_CONFIG      => array( __( 'Check service ID', 'povikvane-for-woocommerce' ), 'bad' ),
			default                              => array( __( 'Not set up', 'povikvane-for-woocommerce' ), '' ),
		};

		Ui::tile(
			__( 'Connection', 'povikvane-for-woocommerce' ),
			Ui::pill( $label, $tone ),
			$data->checked_at > 0
				? sprintf(
					/* translators: %s: date and time. */
					__( 'Checked %s', 'povikvane-for-woocommerce' ),
					Dates::format( $data->checked_at )
				)
				: __( 'Not checked yet', 'povikvane-for-woocommerce' )
		);

		// Credits.
		if ( $data->balance_known ) {
			$low = null !== $data->balance && $data->low_threshold > 0 && $data->balance->available < $data->low_threshold;

			printf(
				'<div class="pvk-tile"><div class="pvk-tile__label">%1$s</div><div class="pvk-tile__value"><span id="povikvane-balance" data-refresh="1" data-age="%2$d" data-estimated="%3$d">%4$s</span>%5$s</div><div class="pvk-tile__hint" id="povikvane-balance-hint">%6$s</div></div>',
				esc_html__( 'Credits', 'povikvane-for-woocommerce' ),
				(int) ( null === $data->balance ? -1 : max( 0, time() - $data->balance->fetched_at ) ),
				null !== $data->balance && $data->balance->estimated ? 1 : 0,
				esc_html( BalanceView::amount( $data->balance ) ),
				$low ? ' ' . Ui::pill( __( 'Low', 'povikvane-for-woocommerce' ), 'warn' ) : '', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by Ui::pill().
				esc_html( BalanceView::hint( $data->balance ) )
			);
		} else {
			Ui::tile( __( 'Credits', 'povikvane-for-woocommerce' ), '—', __( 'Your balance is not available from the service yet.', 'povikvane-for-woocommerce' ) );
		}

		// Sending.
		list( $label, $tone, $hint ) = match ( $data->paused ) {
			null                          => array( __( 'Active', 'povikvane-for-woocommerce' ), 'ok', __( 'Messages go out as orders change.', 'povikvane-for-woocommerce' ) ),
			SendingState::REASON_CREDITS  => array( __( 'On hold', 'povikvane-for-woocommerce' ), 'warn', __( 'No credits: messages wait for a top-up.', 'povikvane-for-woocommerce' ) ),
			SendingState::REASON_SITE     => array( __( 'Paused', 'povikvane-for-woocommerce' ), 'bad', __( 'This site\'s address changed: confirm it is the live shop.', 'povikvane-for-woocommerce' ) ),
			SendingState::REASON_STORM    => array( __( 'Paused', 'povikvane-for-woocommerce' ), 'warn', __( 'Many messages at once: review and release them.', 'povikvane-for-woocommerce' ) ),
			default                       => array( __( 'Paused', 'povikvane-for-woocommerce' ), 'bad', __( 'The API key or service ID was rejected.', 'povikvane-for-woocommerce' ) ),
		};

		Ui::tile( __( 'Sending', 'povikvane-for-woocommerce' ), Ui::pill( $label, $tone ), $hint );

		// Delivery reports.
		if ( null === $data->last_report ) {
			Ui::tile( __( 'Delivery reports', 'povikvane-for-woocommerce' ), Ui::pill( __( 'None yet', 'povikvane-for-woocommerce' ) ), __( 'Messages are still checked regularly. Add the webhook for instant results.', 'povikvane-for-woocommerce' ) );
		} else {
			Ui::tile(
				__( 'Delivery reports', 'povikvane-for-woocommerce' ),
				Ui::pill( __( 'Working', 'povikvane-for-woocommerce' ), 'ok' ),
				sprintf(
					/* translators: 1: date and time, 2: what the report did. */
					__( 'Last: %1$s (%2$s)', 'povikvane-for-woocommerce' ),
					Dates::format( $data->last_report['time'] ),
					$this->report_label( $data->last_report['result'] )
				)
			);
		}

		echo '</div>';
	}

	/**
	 * What needs attention, each with the button that fixes it.
	 *
	 * @param OverviewData $data What to show.
	 */
	private function render_attention( OverviewData $data ): void {
		if ( array() === $data->attention ) {
			return;
		}

		wp_enqueue_script( 'povikvane-attention', plugins_url( 'assets/js/attention.js', PLUGIN_FILE ), array(), VERSION, true );

		Ui::card_open( __( 'Needs attention', 'povikvane-for-woocommerce' ) );
		echo '<div class="pvk-attn-list">';

		foreach ( $data->attention as $item ) {
			printf(
				'<div class="pvk-attn pvk-attn--%1$s"><div><strong>%2$s</strong><p>%3$s</p></div>',
				esc_attr( $item['tone'] ),
				esc_html( $item['title'] ),
				esc_html( $item['text'] )
			);

			echo '<div class="pvk-attn__actions">';

			if ( '' !== $item['label'] && '' !== $item['url'] ) {
				printf(
					'<a class="pvk-btn" href="%1$s"%2$s>%3$s</a>',
					esc_url( $item['url'] ),
					$item['external'] ? ' target="_blank" rel="noopener noreferrer"' : '',
					esc_html( $item['label'] )
				);
			}

			printf(
				'<button type="button" class="pvk-attn__dismiss" data-action="%1$s" data-nonce="%2$s" data-key="%3$s" aria-label="%4$s" title="%4$s">&times;</button>',
				esc_attr( AttentionDismissals::ACTION ),
				esc_attr( wp_create_nonce( AttentionDismissals::NONCE ) ),
				esc_attr( $item['key'] ),
				esc_attr__( 'Dismiss for a week', 'povikvane-for-woocommerce' )
			);

			echo '</div></div>';
		}

		echo '</div>';
		Ui::card_close();
	}

	/**
	 * Results of the chosen period.
	 *
	 * @param OverviewData $data What to show.
	 */
	private function render_report( OverviewData $data ): void {
		$report = $data->report;

		echo '<section class="pvk-card"><div class="pvk-card__head" style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:8px"><h2 class="pvk-card__title">' . esc_html__( 'Results', 'povikvane-for-woocommerce' ) . '</h2><p class="pvk-views">';

		foreach ( array( 7, 30, 90 ) as $days ) {
			printf(
				'<a href="%1$s"%2$s>%3$s</a>',
				esc_url( add_query_arg( 'range', $days, '' !== $data->overview_url ? $data->overview_url : '' ) ),
				$days === $data->range_days ? ' class="is-current"' : '',
				esc_html(
					sprintf(
						/* translators: %d: number of days. */
						_n( 'Last %d day', 'Last %d days', $days, 'povikvane-for-woocommerce' ),
						$days
					)
				)
			);
		}

		echo '</p></div>';

		if ( null === $report || 0 === $report->sent() ) {
			echo '<div class="pvk-empty">' . esc_html__( 'No messages in this period yet.', 'povikvane-for-woocommerce' ) . '</div></section>';
			return;
		}

		$rate = $report->rate();

		echo '<div class="pvk-tiles">';
		Ui::tile( __( 'Sent', 'povikvane-for-woocommerce' ), esc_html( number_format_i18n( $report->sent() ) ), 0 === $report->skipped ? '' : sprintf( /* translators: %d: number of messages. */ __( '%d not sent on purpose', 'povikvane-for-woocommerce' ), $report->skipped ) );
		Ui::tile( __( 'Messages delivered', 'povikvane-for-woocommerce' ), esc_html( number_format_i18n( $report->delivered ) ), null === $rate ? '' : sprintf( /* translators: %s: percentage. */ __( '%s%% of finished messages', 'povikvane-for-woocommerce' ), number_format_i18n( $rate, 1 ) ) );
		Ui::tile( __( 'Did not arrive', 'povikvane-for-woocommerce' ), esc_html( number_format_i18n( $report->failed + $report->unconfirmed ) ), 0 === $report->unconfirmed ? '' : sprintf( /* translators: %d: number of messages. */ __( '%d could not be confirmed', 'povikvane-for-woocommerce' ), $report->unconfirmed ) );
		Ui::tile( __( 'Credits used', 'povikvane-for-woocommerce' ), esc_html( number_format_i18n( $report->credits, 1 ) ) );

		if ( $report->viber_sent > 0 ) {
			Ui::tile( __( 'Viber', 'povikvane-for-woocommerce' ), esc_html( number_format_i18n( $report->viber_sent ) ), sprintf( /* translators: %d: number of messages. */ __( '%d fell back to SMS', 'povikvane-for-woocommerce' ), $report->fallbacks ) );
		}

		echo '</div>';

		$total = max( 1, $report->sent() );

		printf(
			'<div class="pvk-bar" role="img" aria-label="%1$s"><span class="pvk-bar__ok" style="width:%2$s%%"></span><span class="pvk-bar__bad" style="width:%3$s%%"></span><span class="pvk-bar__warn" style="width:%4$s%%"></span></div>',
			esc_attr__( 'Share of delivered, failed and unconfirmed messages', 'povikvane-for-woocommerce' ),
			esc_attr( (string) round( 100 * $report->delivered / $total, 1 ) ),
			esc_attr( (string) round( 100 * $report->failed / $total, 1 ) ),
			esc_attr( (string) round( 100 * $report->unconfirmed / $total, 1 ) )
		);

		echo '</section>';
	}

	/**
	 * Check connection and send a test message.
	 */
	private function render_test(): void {
		$form = Layout::register_form( 'povikvane_send_test' );

		Ui::card_open( __( 'Try it', 'povikvane-for-woocommerce' ), __( 'Sends one real SMS and uses message credits.', 'povikvane-for-woocommerce' ) );

		echo '<div class="pvk-inline" style="margin-top:12px">';
		printf(
			'<label class="screen-reader-text" for="pvk-test-phone">%1$s</label><input type="tel" id="pvk-test-phone" name="test_phone" form="%2$s" placeholder="%3$s" autocomplete="tel" required> <button type="submit" form="%2$s" class="pvk-btn pvk-btn--primary">%4$s</button>',
			esc_html__( 'Your phone number', 'povikvane-for-woocommerce' ),
			esc_attr( $form ),
			esc_attr__( 'Your phone, e.g. 0888 123 456', 'povikvane-for-woocommerce' ),
			esc_html__( 'Send a test message', 'povikvane-for-woocommerce' )
		);
		Layout::action_button( __( 'Check connection', 'povikvane-for-woocommerce' ), 'povikvane_check_connection' );
		echo '</div>';

		Ui::card_close();
	}

	/**
	 * The latest messages.
	 *
	 * @param OverviewData $data What to show.
	 */
	private function render_recent( OverviewData $data ): void {
		echo '<section class="pvk-card"><div class="pvk-card__head" style="display:flex;justify-content:space-between;align-items:center"><h2 class="pvk-card__title">' . esc_html__( 'Latest messages', 'povikvane-for-woocommerce' ) . '</h2>';
		printf( '<a href="%1$s">%2$s</a></div>', esc_url( $data->log_url ), esc_html__( 'View all', 'povikvane-for-woocommerce' ) );

		if ( array() === $data->recent ) {
			echo '<div class="pvk-empty">' . esc_html__( 'No messages yet. They appear here as soon as an order changes status or you send a test.', 'povikvane-for-woocommerce' ) . '</div></section>';
			return;
		}

		echo '<table class="pvk-table"><thead><tr>';
		foreach ( array( __( 'Time', 'povikvane-for-woocommerce' ), __( 'Event', 'povikvane-for-woocommerce' ), __( 'Type', 'povikvane-for-woocommerce' ), __( 'Recipient', 'povikvane-for-woocommerce' ), __( 'Status', 'povikvane-for-woocommerce' ), __( 'Detail', 'povikvane-for-woocommerce' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $data->recent as $row ) {
			printf(
				'<tr><td>%1$s</td><td>%2$s</td><td>%6$s</td><td class="pvk-mono">%3$s</td><td>%4$s</td><td>%5$s</td></tr>',
				esc_html( Dates::format( $row->created_at ) ),
				esc_html( $row->event ),
				esc_html( Mask::phone( $row->recipient ) ),
				StatusView::live_pill( (int) $row->id, $row->status ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by Ui::pill().
				esc_html( null !== $row->skip_reason ? SkipLabels::text( $row->skip_reason ) : ( in_array( $row->status, array( Status::Failed, Status::Unconfirmed ), true ) ? ErrorExplainer::explain( $row ) : (string) $row->error_detail ) ),
				esc_html( ChannelLabel::text( $row ) )
			);
		}

		echo '</tbody></table></section>';
	}

	/**
	 * Human label for a webhook result.
	 *
	 * @param string $result A WebhookHandler result code.
	 */
	private function report_label( string $result ): string {
		return match ( $result ) {
			WebhookHandler::RESULT_UPDATED => __( 'updated a message', 'povikvane-for-woocommerce' ),
			WebhookHandler::RESULT_IGNORED => __( 'already known', 'povikvane-for-woocommerce' ),
			WebhookHandler::RESULT_UNKNOWN => __( 'another message', 'povikvane-for-woocommerce' ),
			default                        => __( 'not understood', 'povikvane-for-woocommerce' ),
		};
	}
}
