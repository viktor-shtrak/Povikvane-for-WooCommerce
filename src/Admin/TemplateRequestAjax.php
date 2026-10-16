<?php
/**
 * Sends order messages to Povikvane for approval as Viber templates.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Admin;

use Povikvane\WooCommerce\Orders\TriggerRepository;
use Povikvane\WooCommerce\Viber\TemplateRequestService;

defined( 'ABSPATH' ) || exit;

/**
 * The Messages tab posts the texts exactly as they are on screen, edited or not,
 * so a message can be sent for approval before the form is saved.
 */
final class TemplateRequestAjax {

	public const ACTION = 'povikvane_request_templates';
	public const NONCE  = 'povikvane_request_templates';

	/**
	 * Constructor.
	 *
	 * @param TemplateRequestService $service  Does the sending.
	 * @param TriggerRepository      $triggers Knows the order statuses.
	 */
	public function __construct(
		private readonly TemplateRequestService $service,
		private readonly TriggerRepository $triggers
	) {}

	/**
	 * Register the handler.
	 */
	public function register(): void {
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Handle the request.
	 */
	public function handle(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array(), 403 );
		}

		$statuses = $this->triggers->statuses();
		$texts    = array();
		$raw      = isset( $_POST['texts'] ) && is_array( $_POST['texts'] ) ? wp_unslash( $_POST['texts'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Every value is sanitised below.

		foreach ( $raw as $slug => $text ) {
			$slug = sanitize_key( (string) $slug );

			if ( isset( $statuses[ $slug ] ) && is_string( $text ) && '' !== trim( $text ) ) {
				$texts[ $slug ] = sanitize_textarea_field( $text );
			}
		}

		$results = $this->service->send( $texts, $statuses, wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), time() );
		$out     = array();

		foreach ( $results as $slug => $result ) {
			$out[ $slug ] = $result;
		}

		wp_send_json_success( array( 'results' => $out ) );
	}
}
