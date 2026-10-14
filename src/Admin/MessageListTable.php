<?php
/**
 * Message log table.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Admin;

use Povikvane\WooCommerce\Support\Dates;
use Povikvane\WooCommerce\Log\Message;
use Povikvane\WooCommerce\Log\MessageQuery;
use Povikvane\WooCommerce\Log\Repository;
use Povikvane\WooCommerce\Messages\Status;
use Povikvane\WooCommerce\Support\Mask;
use Povikvane\WooCommerce\Support\SkipLabels;
use WP_List_Table;

defined( 'ABSPATH' ) || exit;

/**
 * Every message the plugin has handled, with filters and a Resend action.
 */
final class MessageListTable extends WP_List_Table {

	private const PER_PAGE = 20;

	/**
	 * Constructor.
	 *
	 * @param Repository   $repository Message log.
	 * @param MessageQuery $query      Active filter.
	 */
	public function __construct(
		private readonly Repository $repository,
		private readonly MessageQuery $query
	) {
		parent::__construct(
			array(
				'singular' => 'message',
				'plural'   => 'messages',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Columns.
	 *
	 * @return array<string,string>
	 */
	public function get_columns(): array {
		return array(
			'created' => __( 'Time', 'povikvane-for-woocommerce' ),
			'order'   => __( 'Order', 'povikvane-for-woocommerce' ),
			'event'   => __( 'Event', 'povikvane-for-woocommerce' ),
			'to'      => __( 'Recipient', 'povikvane-for-woocommerce' ),
			'status'  => __( 'Status', 'povikvane-for-woocommerce' ),
			'credits' => __( 'Credits', 'povikvane-for-woocommerce' ),
			'detail'  => __( 'Detail', 'povikvane-for-woocommerce' ),
		);
	}

	/**
	 * Load the current page of rows.
	 */
	public function prepare_items(): void {
		$this->_column_headers = array( $this->get_columns(), array(), array() );

		$page  = max( 1, isset( $_GET['paged'] ) ? absint( wp_unslash( $_GET['paged'] ) ) : 1 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list paging.
		$total = $this->repository->count( $this->query );

		$this->items = $this->repository->search( $this->query, self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE );

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => self::PER_PAGE,
			)
		);
	}

	/**
	 * Fallback for columns without their own method.
	 *
	 * @param object|array<string,mixed> $item        Row.
	 * @param string                     $column_name Column.
	 */
	protected function column_default( $item, $column_name ): string {
		return '';
	}

	/**
	 * Time column, with the row actions.
	 *
	 * @param Message $item Row.
	 */
	protected function column_created( $item ): string {
		$actions = array();

		if ( $this->can_resend( $item ) ) {
			$actions['resend'] = sprintf(
				'<a href="%1$s" data-povikvane-confirm="%2$s">%3$s</a>',
				esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=povikvane_resend&message=' . (int) $item->id ), 'povikvane_resend_' . (int) $item->id ) ),
				esc_attr__( 'Send this message again? The customer may receive it twice if the first one was actually delivered.', 'povikvane-for-woocommerce' ),
				esc_html__( 'Resend', 'povikvane-for-woocommerce' )
			);
		}

		return esc_html( Dates::format( $item->created_at, 'Y-m-d H:i' ) ) . $this->row_actions( $actions );
	}

	/**
	 * Order column.
	 *
	 * @param Message $item Row.
	 */
	protected function column_order( $item ): string {
		if ( $item->order_id <= 0 ) {
			return '&mdash;';
		}

		$order = wc_get_order( $item->order_id );
		$label = '#' . $item->order_id;

		return $order instanceof \WC_Order ? sprintf( '<a href="%1$s">%2$s</a>', esc_url( $order->get_edit_order_url() ), esc_html( $label ) ) : esc_html( $label );
	}

	/**
	 * Event column.
	 *
	 * @param Message $item Row.
	 */
	protected function column_event( $item ): string {
		return esc_html( $item->event );
	}

	/**
	 * Recipient column (shortened).
	 *
	 * @param Message $item Row.
	 */
	protected function column_to( $item ): string {
		$phone = esc_html( Mask::phone( $item->recipient ) );

		if ( 'viber' !== $item->channel ) {
			return $phone;
		}

		$label = 'sms' === $item->delivered_via
			? __( 'Viber, delivered by SMS', 'povikvane-for-woocommerce' )
			: __( 'Viber', 'povikvane-for-woocommerce' );

		return $phone . '<br><small>' . esc_html( $label ) . '</small>';
	}

	/**
	 * Status column.
	 *
	 * @param Message $item Row.
	 */
	protected function column_status( $item ): string {
		return StatusView::pill( $item->status );
	}

	/**
	 * Credits column.
	 *
	 * @param Message $item Row.
	 */
	protected function column_credits( $item ): string {
		return null === $item->credits ? '' : esc_html( number_format_i18n( $item->credits, 1 ) );
	}

	/**
	 * Detail column.
	 *
	 * @param Message $item Row.
	 */
	protected function column_detail( $item ): string {
		if ( null !== $item->skip_reason ) {
			return esc_html( SkipLabels::text( $item->skip_reason ) );
		}

		return esc_html( (string) $item->error_detail );
	}

	/**
	 * Filters above the table.
	 *
	 * @param string $which "top" or "bottom".
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}

		echo '<div class="alignleft actions">';

		echo '<select name="status"><option value="">' . esc_html__( 'All statuses', 'povikvane-for-woocommerce' ) . '</option>';
		foreach ( Status::cases() as $status ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $status->value ), selected( $this->query->status?->value, $status->value, false ), esc_html( StatusView::label( $status ) ) );
		}
		echo '</select> ';

		echo '<select name="event"><option value="">' . esc_html__( 'All events', 'povikvane-for-woocommerce' ) . '</option>';
		foreach ( $this->repository->events() as $event ) {
			printf( '<option value="%1$s"%2$s>%1$s</option>', esc_attr( $event ), selected( $this->query->event, $event, false ) );
		}
		echo '</select> ';

		printf(
			'<label>%1$s <input type="date" name="from" value="%2$s"></label> <label>%3$s <input type="date" name="to" value="%4$s"></label> ',
			esc_html__( 'From', 'povikvane-for-woocommerce' ),
			esc_attr( null === $this->query->from ? '' : gmdate( 'Y-m-d', $this->query->from ) ),
			esc_html__( 'To', 'povikvane-for-woocommerce' ),
			esc_attr( null === $this->query->to ? '' : gmdate( 'Y-m-d', $this->query->to - DAY_IN_SECONDS ) )
		);

		submit_button( __( 'Filter', 'povikvane-for-woocommerce' ), '', 'filter_action', false );

		echo '</div>';
	}

	/**
	 * Whether a message may be sent again.
	 *
	 * @param Message $message Row.
	 */
	private function can_resend( Message $message ): bool {
		return in_array( $message->status, array( Status::Failed, Status::Unconfirmed ), true ) && '' !== $message->body;
	}
}
