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
 * Povikvane > Message log: browse, filter, resend and export.
 */
final class MessageLogPage {

	public const SLUG = 'povikvane-sms-messages';

	private const CAPABILITY  = 'manage_woocommerce';
	private const EXPORT_MAX  = 50000;
	private const EXPORT_PAGE = 500;
	private const BULK_MAX    = 100;

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
		add_action( 'admin_head', array( $this, 'hide_menu_entry' ) );
		add_filter( 'submenu_file', array( $this, 'highlight_settings_entry' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_povikvane_resend', array( $this, 'handle_resend' ) );
		add_action( 'admin_post_povikvane_bulk_resend', array( $this, 'handle_bulk_resend' ) );
		add_action( 'admin_post_povikvane_export', array( $this, 'handle_export' ) );
	}

	/**
	 * Add the page under the WooCommerce menu.
	 */
	public function add_menu(): void {
		$this->hook_suffix = (string) add_submenu_page(
			'woocommerce',
			__( 'Povikvane message log', 'povikvane-for-woocommerce' ),
			__( 'Povikvane message log', 'povikvane-for-woocommerce' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * The log is a tab of the Povikvane screen, so it has no menu entry of its own.
	 * It is hidden after WordPress has checked access to the page.
	 */
	public function hide_menu_entry(): void {
		remove_submenu_page( 'woocommerce', self::SLUG );
	}

	/**
	 * Keep the Povikvane menu entry highlighted while the log is open.
	 *
	 * @param mixed $submenu_file Current submenu file.
	 * @return mixed
	 */
	public function highlight_settings_entry( mixed $submenu_file ): mixed {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen check.
		return isset( $_GET['page'] ) && self::SLUG === sanitize_key( wp_unslash( $_GET['page'] ) ) ? SettingsPage::SLUG : $submenu_file;
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
			MessageStatusAjax::enqueue();
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

		$this->views( $query );

		echo '<form method="get">';
		printf( '<input type="hidden" name="page" value="%s">', esc_attr( self::SLUG ) );
		printf( '<input type="hidden" name="view" value="%s">', esc_attr( $query->needs_attention ? 'attention' : '' ) );
		wp_nonce_field( 'povikvane_bulk_resend' );
		$table->search_box( __( 'Search order number, reference or message ID', 'povikvane-for-woocommerce' ), 'povikvane-message' );
		$table->display();
		echo '</form></div></section>';

		Layout::close();
	}

	/**
	 * Links between "all messages" and "needs attention".
	 *
	 * @param MessageQuery $query Active filter.
	 */
	private function views( MessageQuery $query ): void {
		$attention = $this->repository->count( new MessageQuery( null, '', '', null, null, true ) );
		$base      = admin_url( 'admin.php?page=' . self::SLUG );

		printf(
			'<p class="pvk-views"><a href="%1$s"%2$s>%3$s</a><a href="%4$s"%5$s>%6$s</a></p>',
			esc_url( $base ),
			$query->needs_attention ? '' : ' class="is-current"',
			esc_html__( 'All messages', 'povikvane-for-woocommerce' ),
			esc_url( add_query_arg( 'view', 'attention', $base ) ),
			$query->needs_attention ? ' class="is-current"' : '',
			esc_html(
				sprintf(
					/* translators: %d: number of failed or unconfirmed messages. */
					__( 'Needs attention (%d)', 'povikvane-for-woocommerce' ),
					$attention
				)
			)
		);
	}

	/**
	 * Send a failed or unconfirmed message again, under a new reference.
	 */
	public function handle_resend(): void {
		$id = isset( $_GET['message'] ) ? absint( wp_unslash( $_GET['message'] ) ) : 0;

		check_admin_referer( 'povikvane_resend_' . $id );
		$this->require_capability();

		if ( SendingState::REASON_SITE === $this->state->paused_reason() ) {
			Flash::add( 'error', __( 'This site is not confirmed as the live shop yet. Confirm it first.', 'povikvane-for-woocommerce' ) );
			$this->back();
		}

		$old = $this->repository->find( $id );

		if ( null === $old || ! $this->resendable( $old ) ) {
			Flash::add( 'error', __( 'This message cannot be sent again.', 'povikvane-for-woocommerce' ) );
			$this->back();
		}

		if ( $this->queue_again( $old ) ) {
			Flash::add( 'success', __( 'The message was queued again.', 'povikvane-for-woocommerce' ) );
		} else {
			Flash::add( 'error', __( 'Another message was just queued. Wait a second and try again.', 'povikvane-for-woocommerce' ) );
		}

		$this->back();
	}

	/**
	 * Send several failed or unconfirmed messages again.
	 */
	public function handle_bulk_resend(): void {
		check_admin_referer( 'povikvane_bulk_resend' );
		$this->require_capability();

		if ( SendingState::REASON_SITE === $this->state->paused_reason() ) {
			Flash::add( 'error', __( 'This site is not confirmed as the live shop yet. Confirm it first.', 'povikvane-for-woocommerce' ) );
			$this->back();
		}

		$ids = isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ? array_map( 'absint', wp_unslash( $_POST['ids'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value is cast to an integer.
		$ids = array_slice( array_values( array_unique( array_filter( $ids ) ) ), 0, self::BULK_MAX );

		$queued  = 0;
		$skipped = 0;

		foreach ( $ids as $id ) {
			$old = $this->repository->find( $id );

			if ( null !== $old && $this->resendable( $old ) && $this->queue_again( $old ) ) {
				++$queued;
			} else {
				++$skipped;
			}
		}

		if ( 0 === $queued && 0 === $skipped ) {
			Flash::add( 'error', __( 'Select at least one message first.', 'povikvane-for-woocommerce' ) );
			$this->back();
		}

		Flash::add(
			$queued > 0 ? 'success' : 'error',
			sprintf(
				/* translators: 1: number of messages queued again, 2: number that could not be sent again. */
				__( '%1$d queued again, %2$d could not be sent again.', 'povikvane-for-woocommerce' ),
				$queued,
				$skipped
			)
		);

		$this->back();
	}

	/**
	 * Whether a message may be sent again.
	 *
	 * @param Message $old Message.
	 */
	private function resendable( Message $old ): bool {
		return in_array( $old->status, array( Status::Failed, Status::Unconfirmed ), true ) && '' !== $old->body && Kind::Transactional === $old->kind;
	}

	/**
	 * Queue a new SMS with the text of an earlier message, under a new reference.
	 *
	 * @param Message $old The earlier message.
	 * @return bool Whether it was queued.
	 */
	private function queue_again( Message $old ): bool {
		$new            = new Message();
		$new->order_id  = $old->order_id;
		$new->event     = $old->event;
		$new->kind      = $old->kind;
		$new->channel   = 'sms';
		$new->recipient = $old->recipient;
		$new->body      = null === $old->viber ? $old->body : $old->viber['fallback_sms'];
		$new->reference = Reference::for_event(
			SiteIdentity::token(),
			$old->order_id,
			$old->event,
			$this->repository->count_for_order_event( $old->order_id, $old->event ) + 1
		);

		return null !== $this->enqueuer->enqueue( $new );
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

		$attention = isset( $_GET['view'] ) && 'attention' === sanitize_key( wp_unslash( $_GET['view'] ) );

		return new MessageQuery( $status, $event, $search, $from, null === $to ? null : $to + DAY_IN_SECONDS, $attention );
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
				'view'   => $query->needs_attention ? 'attention' : null,
				'status' => $query->needs_attention ? null : $query->status?->value,
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
