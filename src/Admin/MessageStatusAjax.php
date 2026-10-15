<?php
/**
 * Keeps message statuses on screen current without reloading.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Admin;

use Povikvane\WooCommerce\Log\Message;
use Povikvane\WooCommerce\Log\MessageStore;

use const Povikvane\WooCommerce\PLUGIN_FILE;
use const Povikvane\WooCommerce\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * The Overview and the message log ask for the current status of the messages they
 * show. A message that is still on its way is also checked with the service right
 * away (at most once every 15 seconds), so a delivery shows up within seconds even
 * when no webhook arrives and the site's scheduled tasks are slow.
 */
final class MessageStatusAjax {

	public const ACTION = 'povikvane_message_status';
	public const NONCE  = 'povikvane_message_status';

	private const CAPABILITY   = 'manage_woocommerce';
	private const MAX_IDS      = 25;
	private const MIN_INTERVAL = 15;

	/**
	 * Constructor.
	 *
	 * @param MessageStore $store Message log.
	 * @param callable     $check Checks one message with the service now; returns whether it changed.
	 */
	public function __construct(
		private readonly MessageStore $store,
		private $check
	) {}

	/**
	 * Register the AJAX action.
	 */
	public function register(): void {
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Load the script that keeps statuses current. Call on screens that show them.
	 */
	public static function enqueue(): void {
		wp_enqueue_script( 'povikvane-live', plugins_url( 'assets/js/live.js', PLUGIN_FILE ), array(), VERSION, true );
		wp_localize_script(
			'povikvane-live',
			'povikvaneLive',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'action'  => self::ACTION,
			)
		);
	}

	/**
	 * Answer with the current status of the requested messages.
	 */
	public function handle(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array(), 403 );
		}

		$raw = isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ? array_map( 'absint', wp_unslash( $_POST['ids'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value is cast to an integer.
		$ids = array_slice( array_values( array_unique( array_filter( $raw ) ) ), 0, self::MAX_IDS );

		$out = array();

		foreach ( $ids as $id ) {
			$message = $this->store->find( $id );

			if ( null === $message ) {
				continue;
			}

			$message = $this->refresh( $message );

			$out[] = array(
				'id'      => $id,
				'html'    => StatusView::pill( $message->status ),
				'settled' => StatusView::is_settled( $message->status ),
			);
		}

		wp_send_json_success( array( 'messages' => $out ) );
	}

	/**
	 * Check a message that is still on its way, but not more often than every few seconds.
	 *
	 * @param Message $message Message.
	 */
	private function refresh( Message $message ): Message {
		$key = 'povikvane_live_' . (int) $message->id;

		if ( ! StatusView::is_settled( $message->status ) && false === get_transient( $key ) ) {
			set_transient( $key, 1, self::MIN_INTERVAL );
			( $this->check )( $message );

			return $this->store->find( (int) $message->id ) ?? $message;
		}

		return $message;
	}
}
