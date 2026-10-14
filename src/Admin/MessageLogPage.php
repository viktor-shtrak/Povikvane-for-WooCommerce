<?php
/**
 * Message log screen.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Admin;

use Povikvane\WooCommerce\Support\Dates;
use DateTimeImmutable;
use Povikvane\WooCommerce\Log\Message;
use Povikvane\WooCommerce\Log\MessageQuery;
use Povikvane\WooCommerce\Log\Repository;
use Povikvane\WooCommerce\Messages\Enqueuer;
use Povikvane\WooCommerce\Messages\Kind;
use Povikvane\WooCommerce\Messages\Reference;
use Povikvane\WooCommerce\Messages\Status;
use Povikvane\WooCommerce\Support\Csv;
use Povikvane\WooCommerce\Support\Mask;
use Povikvane\WooCommerce\Support\SendingState;
use Povikvane\WooCommerce\Support\Settings;
use Povikvane\WooCommerce\Support\SiteIdentity;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Filters are read-only GET parameters; actions that change data verify nonces.

/**
 * WooCommerce > Povikvane messages: browse, filter, resend and export.
 */
final class MessageLogPage {

	public const SLUG = 'povikvane-sms-messages';

	private const CAPABILITY  = 'manage_woocommerce';
	private const EXPORT_MAX  = 50000;
	private const EXPORT_PAGE = 500;

	/**
	 * Admin screen hook suffix.
	 *
	 * @var string
	 */
	private string $hook_suffix = '';

	/**
	 * Constructor.
	 *
	 * @param Repository   $repository Message log.
	 * @param Enqueuer     $enqueuer   Queues messages.
	 * @param Settings     $settings   Settings.
	 * @param SendingState $state      Pause switch.
	 */
	public function __construct(
		private readonly Repository $repository,
		private readonly Enqueuer $enqueuer,
		private readonly Settings $settings,
		private readonly SendingState $state
	) {}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_povikvane_resend', array( $this, 'handle_resend' ) );
		add_action( 'admin_post_povikvane_export', array( $this, 'handle_export' ) );
	}

	/**
	 * Add the page under the WooCommerce menu.
	 */
	public function add_menu(): void {
		$this->hook_suffix = (string) add_submenu_page(
			'woocommerce',
			__( 'Povikvane messages', 'povikvane-for-woocommerce' ),
			__( 'Povikvane messages', 'povikvane-for-woocommerce' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Load the confirmation script on this page.
	 *
	 * @param string $hook_suffix Current admin screen.
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( '' !== $this->hook_suffix && $hook_suffix === $this->hook_suffix ) {
			Assets::base();
			Assets::confirm();
		}
	}

	/**
	 * Render the page.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'povikvane-for-woocommerce' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';

		$query = $this->query_from_request();
		$table = new MessageListTable( $this->repository, $query );
		$table->prepare_items();

		Layout::open();
		Layout::tabs( Navigation::for_log_page(), 'log' );

		echo '<section class="pvk-card"><div class="pvk-card__head" style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:8px"><h2 class="pvk-card__title">' . esc_html__( 'Message log', 'povikvane-for-woocommerce' ) . '</h2>';
		printf(
			'<a class="pvk-btn" href="%1$s">%2$s</a></div><div class="pvk-card__body">',
			esc_url( $this->export_url( $query ) ),
			esc_html__( 'Export CSV', 'povikvane-for-woocommerce' )
		);

		echo '<form method="get">';
		printf( '<input type="hidden" name="page" value="%s">', esc_attr( self::SLUG ) );
		$table->search_box( __( 'Search order number, reference or message ID', 'povikvane-for-woocommerce' ), 'povikvane-message' );
		$table->display();
		echo '</form></div></section>';

		Layout::close();
	}

	/**
	 * Send a failed or unconfirmed message again, under a new reference.
	 */
	public function handle_resend(): void {
		$id = isset( $_GET['message'] ) ? absint( wp_unslash( $_GET['message'] ) ) : 0;

		check_admin_referer( 'povikvane_resend_' . $id );
		$this->require_capability();

		$old = $this->repository->find( $id );

		if ( null === $old || ! in_array( $old->status, array( Status::Failed, Status::Unconfirmed ), true ) || '' === $old->body || Kind::Transactional !== $old->kind ) {
			Flash::add( 'error', __( 'This message cannot be sent again.', 'povikvane-for-woocommerce' ) );
			$this->back();
		}

		if ( SendingState::REASON_SITE === $this->state->paused_reason() ) {
			Flash::add( 'error', __( 'This site is not confirmed as the live shop yet. Confirm it first.', 'povikvane-for-woocommerce' ) );
			$this->back();
		}

		$new            = new Message();
		$new->order_id  = $old->order_id;
		$new->event     = $old->event;
		$new->kind      = $old->kind;
		$new->channel   = $old->channel;
		$new->recipient = $old->recipient;
		$new->body      = $old->body;
		$new->reference = Reference::for_event(
			SiteIdentity::token(),
			$old->order_id,
			$old->event,
			$this->repository->count_for_order_event( $old->order_id, $old->event ) + 1
		);

		if ( null === $this->enqueuer->enqueue( $new ) ) {
			Flash::add( 'error', __( 'Another message was just queued. Wait a second and try again.', 'povikvane-for-woocommerce' ) );
		} else {
			Flash::add( 'success', __( 'The message was queued again.', 'povikvane-for-woocommerce' ) );
		}

		$this->back();
	}

	/**
	 * Download the filtered log as CSV.
	 */
	public function handle_export(): void {
		check_admin_referer( 'povikvane_export' );
		$this->require_capability();

		$query = $this->query_from_request();
		$full  = $this->settings->export_full_numbers();

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="povikvane-messages-' . gmdate( 'Ymd-His' ) . '.csv"' );

		// A byte order mark makes Excel read the file as UTF-8.
		echo "\xEF\xBB\xBF"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed bytes.
		$header = Csv::row( array( 'id', 'created_utc', 'order_id', 'event', 'kind', 'recipient', 'status', 'skip_reason', 'error_code', 'error_detail', 'segments', 'credits', 'api_message_id', 'reference' ) );
		echo $header . "\r\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Every cell is quoted by Csv::cell().

		for ( $offset = 0; $offset < self::EXPORT_MAX; $offset += self::EXPORT_PAGE ) {
			$rows = $this->repository->search( $query, self::EXPORT_PAGE, $offset );

			foreach ( $rows as $row ) {
				$line = Csv::row(
					array(
						$row->id,
						gmdate( 'Y-m-d H:i:s', $row->created_at ),
						$row->order_id,
						$row->event,
						$row->kind->value,
						$full ? $row->recipient : ltrim( Mask::phone( $row->recipient ), '+' ),
						$row->status->value,
						$row->skip_reason,
						$row->error_code,
						$row->error_detail,
						$row->segments,
						$row->credits,
						$row->api_message_id,
						$row->reference,
					)
				);

				echo $line . "\r\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Every cell is quoted by Csv::cell().
			}

			if ( count( $rows ) < self::EXPORT_PAGE ) {
				break;
			}
		}

		exit;
	}

	/**
	 * The active filter from the URL.
	 */
	private function query_from_request(): MessageQuery {
		$status = isset( $_GET['status'] ) ? Status::tryFrom( sanitize_key( wp_unslash( $_GET['status'] ) ) ) : null;
		$event  = isset( $_GET['event'] ) ? sanitize_key( wp_unslash( $_GET['event'] ) ) : '';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$from   = isset( $_GET['from'] ) ? $this->day_start( sanitize_text_field( wp_unslash( $_GET['from'] ) ) ) : null;
		$to     = isset( $_GET['to'] ) ? $this->day_start( sanitize_text_field( wp_unslash( $_GET['to'] ) ) ) : null;

		return new MessageQuery( $status, $event, $search, $from, null === $to ? null : $to + DAY_IN_SECONDS );
	}

	/**
	 * Start of a day (YYYY-MM-DD) in the site's time zone, as a Unix time.
	 *
	 * @param string $date Date text.
	 */
	private function day_start( string $date ): ?int {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return null;
		}

		$day = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, wp_timezone() );

		return false === $day ? null : $day->getTimestamp();
	}

	/**
	 * Export link carrying the current filter.
	 *
	 * @param MessageQuery $query Active filter.
	 */
	private function export_url( MessageQuery $query ): string {
		$args = array_filter(
			array(
				'action' => 'povikvane_export',
				'status' => $query->status?->value,
				'event'  => $query->event,
				's'      => $query->search,
				'from'   => null === $query->from ? null : Dates::format( $query->from, 'Y-m-d' ),
				'to'     => null === $query->to ? null : Dates::format( $query->to - DAY_IN_SECONDS, 'Y-m-d' ),
			),
			static fn( $value ): bool => null !== $value && '' !== $value
		);

		return wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), 'povikvane_export' );
	}

	/**
	 * Stop unless the user may manage the shop.
	 */
	private function require_capability(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'povikvane-for-woocommerce' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Return to the log.
	 */
	private function back(): never {
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}
}

// phpcs:enable
