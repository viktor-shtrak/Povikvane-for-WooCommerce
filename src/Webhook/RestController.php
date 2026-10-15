<?php
/**
 * REST endpoint for delivery webhooks.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Webhook;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * POST /wp-json/povikvane/v1/dlr/{token}. A wrong token looks like a missing route.
 * Valid requests always get 200, even for unknown messages, so Povikvane never
 * sees errors for traffic this plugin does not own.
 */
final class RestController {

	private const MAX_BODY_BYTES      = 16384;
	private const MAX_REQUESTS_MINUTE = 300;
	private const RATE_KEY            = 'povikvane_webhook_rate';

	/**
	 * Constructor.
	 *
	 * @param WebhookHandler $handler Applies events.
	 * @param Token          $token   The webhook secret.
	 */
	public function __construct(
		private readonly WebhookHandler $handler,
		private readonly Token $token
	) {}

	/**
	 * Register the route.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Define the route.
	 */
	public function routes(): void {
		register_rest_route(
			'povikvane/v1',
			'/dlr/(?P<token>[A-Za-z0-9]{64})',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'authorize' ),
			)
		);
	}

	/**
	 * The secret in the URL is the credential.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function authorize( WP_REST_Request $request ) {
		if ( ! $this->token->matches( (string) $request['token'] ) ) {
			return new WP_Error( 'rest_no_route', __( 'No route was found matching the URL and request method.', 'povikvane-for-woocommerce' ), array( 'status' => 404 ) );
		}

		return true;
	}

	/**
	 * Handle a delivery event.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle( WP_REST_Request $request ) {
		if ( $this->over_rate_limit() ) {
			return new WP_Error( 'povikvane_rate_limited', __( 'Too many requests.', 'povikvane-for-woocommerce' ), array( 'status' => 429 ) );
		}

		if ( strlen( $request->get_body() ) > self::MAX_BODY_BYTES ) {
			return new WP_Error( 'povikvane_too_large', __( 'Request too large.', 'povikvane-for-woocommerce' ), array( 'status' => 413 ) );
		}

		$body = $request->get_json_params();

		if ( ! is_array( $body ) ) {
			return new WP_Error( 'povikvane_bad_request', __( 'Expected a JSON body.', 'povikvane-for-woocommerce' ), array( 'status' => 400 ) );
		}

		// The site's own check: answered, never recorded as a report from Povikvane.
		if ( SelfTest::EVENT === ( $body['event'] ?? null ) ) {
			return new WP_REST_Response(
				array(
					'ok'     => true,
					'result' => 'test',
				),
				200
			);
		}

		return new WP_REST_Response(
			array(
				'ok'     => true,
				'result' => $this->handler->handle( $body ),
			),
			200
		);
	}

	/**
	 * A simple per-minute cap, so a leaked URL cannot be used to hammer the site.
	 */
	private function over_rate_limit(): bool {
		$count = (int) get_transient( self::RATE_KEY );

		if ( $count >= self::MAX_REQUESTS_MINUTE ) {
			return true;
		}

		set_transient( self::RATE_KEY, $count + 1, MINUTE_IN_SECONDS );

		return false;
	}
}
