<?php
/**
 * Manual SMS box on the order screen.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Admin;

use Povikvane\WooCommerce\Log\Message;
use Povikvane\WooCommerce\Log\Repository;
use Povikvane\WooCommerce\Messages\Enqueuer;
use Povikvane\WooCommerce\Messages\Reference;
use Povikvane\WooCommerce\Messages\Status;
use Povikvane\WooCommerce\Orders\OrderContext;
use Povikvane\WooCommerce\Phone\Normalizer;
use Povikvane\WooCommerce\Support\Mask;
use Povikvane\WooCommerce\Support\SendingState;
use Povikvane\WooCommerce\Support\Settings;
use Povikvane\WooCommerce\Support\SiteIdentity;
use Povikvane\WooCommerce\Support\SkipLabels;
use WC_Order;
use WP_Post;

use const Povikvane\WooCommerce\PLUGIN_FILE;
use const Povikvane\WooCommerce\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Shows the messages sent for an order and lets staff send one by hand. The box
 * sits inside the order edit form, so it sends through AJAX instead of a nested form.
 */
final class OrderMetabox {

	private const CAPABILITY = 'manage_woocommerce';
	private const NONCE      = 'povikvane_send_manual';
	private const MAX_LENGTH = 480;
	private const SCRIPT     = 'povikvane-order-box';
	private const EVENT      = 'manual';

	/**
	 * Constructor.
	 *
	 * @param Settings     $settings   Settings.
	 * @param Repository   $repository Message log.
	 * @param Enqueuer     $enqueuer   Queues messages.
	 * @param Normalizer   $normalizer Phone normaliser.
	 * @param OrderContext $context    Reads orders.
	 * @param SendingState $state      Pause switch.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly Repository $repository,
		private readonly Enqueuer $enqueuer,
		private readonly Normalizer $normalizer,
		private readonly OrderContext $context,
		private readonly SendingState $state
	) {}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_ajax_povikvane_send_manual', array( $this, 'handle_send' ) );
	}

	/**
	 * Add the box to the order screen.
	 */
	public function add(): void {
		add_meta_box(
			'povikvane-sms',
			__( 'Povikvane SMS', 'povikvane-for-woocommerce' ),
			array( $this, 'render' ),
			wc_get_page_screen_id( 'shop-order' ),
			'side',
			'default'
		);
	}

	/**
	 * Load the box script on the order screen.
	 */
	public function enqueue(): void {
		$screen = get_current_screen();

		if ( null === $screen || wc_get_page_screen_id( 'shop-order' ) !== $screen->id ) {
			return;
		}

		wp_enqueue_script( self::SCRIPT, plugins_url( 'assets/js/order-box.js', PLUGIN_FILE ), array(), VERSION, true );
		wp_localize_script(
			self::SCRIPT,
			'povikvaneOrderBox',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'i18n'    => array(
					'sending' => __( 'Sending…', 'povikvane-for-woocommerce' ),
					'failed'  => __( 'The message could not be queued. Reload the page and try again.', 'povikvane-for-woocommerce' ),
				),
			)
		);
	}

	/**
	 * Render the box.
	 *
	 * @param WP_Post|WC_Order $post_or_order Post (legacy storage) or order (HPOS).
	 */
	public function render( $post_or_order ): void {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$rows = $this->repository->for_order( $order->get_id(), 5 );

		if ( array() === $rows ) {
			echo '<p>' . esc_html__( 'No messages for this order yet.', 'povikvane-for-woocommerce' ) . '</p>';
		} else {
			echo '<ul style="margin:0 0 12px">';
			foreach ( $rows as $row ) {
				printf(
					'<li><strong>%1$s</strong> · %2$s · %3$s%4$s</li>',
					esc_html( $row->event ),
					esc_html( Mask::phone( $row->recipient ) ),
					esc_html( $this->status_text( $row->status ) ),
					null !== $row->skip_reason ? ' (' . esc_html( SkipLabels::text( $row->skip_reason ) ) . ')' : ''
				);
			}
			echo '</ul>';
		}

		printf(
			'<div id="povikvane-manual" data-order-id="%1$d"><p><label for="povikvane-manual-text">%2$s</label></p><textarea id="povikvane-manual-text" rows="3" maxlength="%3$d" class="widefat"></textarea><p><button type="button" class="button" id="povikvane-manual-send">%4$s</button></p><p id="povikvane-manual-result" class="description" role="status"></p></div>',
			(int) $order->get_id(),
			esc_html__( 'Send a message about this order to the customer:', 'povikvane-for-woocommerce' ),
			(int) self::MAX_LENGTH,
			esc_html__( 'Send SMS', 'povikvane-for-woocommerce' )
		);
	}

	/**
	 * AJAX: queue a manual message.
	 */
	public function handle_send(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do that.', 'povikvane-for-woocommerce' ) ), 403 );
		}

		$order = wc_get_order( isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0 );
		$text  = isset( $_POST['text'] ) ? trim( sanitize_textarea_field( wp_unslash( $_POST['text'] ) ) ) : '';

		if ( ! $order instanceof WC_Order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'povikvane-for-woocommerce' ) ) );
		}

		if ( '' === $text || strlen( $text ) > self::MAX_LENGTH * 4 ) {
			wp_send_json_error( array( 'message' => __( 'Write a message first.', 'povikvane-for-woocommerce' ) ) );
		}

		if ( ! $this->settings->credentials()->is_complete() ) {
			wp_send_json_error( array( 'message' => __( 'Enter and save your API key and service ID first.', 'povikvane-for-woocommerce' ) ) );
		}

		if ( SendingState::REASON_SITE === $this->state->paused_reason() ) {
			wp_send_json_error( array( 'message' => __( 'This site is not confirmed as the live shop yet. Confirm it first.', 'povikvane-for-woocommerce' ) ) );
		}

		$facts  = $this->context->facts( $order, self::EVENT );
		$number = $this->normalizer->normalize( $facts->phone, $facts->calling_code );

		if ( ! $number->is_valid() ) {
			wp_send_json_error( array( 'message' => __( 'The order has no valid phone number.', 'povikvane-for-woocommerce' ) ) );
		}

		$message            = new Message();
		$message->order_id  = $order->get_id();
		$message->event     = self::EVENT;
		$message->recipient = (string) $number->e164;
		$message->body      = $text;
		$message->reference = Reference::for_event(
			SiteIdentity::token(),
			$order->get_id(),
			self::EVENT,
			$this->repository->count_for_order_event( $order->get_id(), self::EVENT ) + 1
		);

		if ( null === $this->enqueuer->enqueue( $message ) ) {
			wp_send_json_error( array( 'message' => __( 'Another message was just queued. Wait a second and try again.', 'povikvane-for-woocommerce' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'Queued. It will appear in the order notes once accepted.', 'povikvane-for-woocommerce' ) ) );
	}

	/**
	 * Short status text.
	 *
	 * @param Status $status Status.
	 */
	private function status_text( Status $status ): string {
		return match ( $status ) {
			Status::Pending     => __( 'waiting', 'povikvane-for-woocommerce' ),
			Status::Sending     => __( 'sending', 'povikvane-for-woocommerce' ),
			Status::Queued      => __( 'accepted', 'povikvane-for-woocommerce' ),
			Status::Delivered   => __( 'delivered', 'povikvane-for-woocommerce' ),
			Status::Failed      => __( 'failed', 'povikvane-for-woocommerce' ),
			Status::Unconfirmed => __( 'unconfirmed', 'povikvane-for-woocommerce' ),
			Status::Skipped     => __( 'not sent', 'povikvane-for-woocommerce' ),
			Status::HeldCredits => __( 'on hold', 'povikvane-for-woocommerce' ),
			Status::Expired     => __( 'expired', 'povikvane-for-woocommerce' ),
		};
	}
}
