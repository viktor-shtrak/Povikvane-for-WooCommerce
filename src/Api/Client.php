<?php
/**
 * Povikvane Public API client.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Api;

use Povikvane\WooCommerce\Support\Logger;
use Povikvane\WooCommerce\Support\NullLogger;

defined( 'ABSPATH' ) || exit;

/**
 * Builds requests, sends them through a Transport and classifies the answers.
 * The service ID travels in a header for GET requests so it stays out of URLs.
 */
final class Client implements Gateway {

	public const DEFAULT_BASE_URL = 'https://app.povikvane.com';

	/**
	 * Base URL without trailing slash.
	 *
	 * @var string
	 */
	private string $base_url;

	/**
	 * Constructor.
	 *
	 * @param Transport   $transport   HTTP transport.
	 * @param Credentials $credentials API credentials.
	 * @param string      $base_url    API base URL.
	 * @param Logger      $logger      Diagnostic logger (never receives credentials or message text).
	 */
	public function __construct(
		private readonly Transport $transport,
		private readonly Credentials $credentials,
		string $base_url = self::DEFAULT_BASE_URL,
		private readonly Logger $logger = new NullLogger()
	) {
		$this->base_url = rtrim( $base_url, '/' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param SendRequest $request Message to send.
	 */
	public function send( SendRequest $request ): ApiResult {
		$message = array(
			'to'      => $request->to,
			'text'    => $request->text,
			'channel' => $request->channel,
		);

		// The API rejects "reference": null, so the field is omitted when there is none.
		if ( null !== $request->reference ) {
			$message['reference'] = $request->reference;
		}

		$headers = array(
			'Authorization' => $this->credentials->bearer(),
			'Content-Type'  => 'application/json',
			'Accept'        => 'application/json',
		);

		if ( null !== $request->idempotency_key ) {
			$headers['Idempotency-Key'] = $request->idempotency_key;
		}

		$body = wp_json_encode(
			array(
				'service-id' => $this->credentials->service_id,
				'message'    => $message,
			)
		);

		return $this->execute( 'POST', '/public-api/v1/sms', $headers, false === $body ? null : $body );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $message_id Message UUID.
	 */
	public function message_status( string $message_id ): ApiResult {
		return $this->get( '/public-api/v1/sms/' . rawurlencode( $message_id ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $reference Reference to look up.
	 */
	public function find_by_reference( string $reference ): ApiResult {
		return $this->get( '/public-api/v1/sms?reference=' . rawurlencode( $reference ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function account(): ApiResult {
		return $this->get( '/public-api/v1/account' );
	}

	/**
	 * Authenticated GET.
	 *
	 * @param string $path Path and query.
	 */
	private function get( string $path ): ApiResult {
		return $this->execute(
			'GET',
			$path,
			array(
				'Authorization' => $this->credentials->bearer(),
				'x-service-id'  => $this->credentials->service_id,
				'Accept'        => 'application/json',
			),
			null
		);
	}

	/**
	 * Send a request, log the outcome (never the headers or body) and parse it.
	 *
	 * @param string               $method  HTTP method.
	 * @param string               $path    Path and query.
	 * @param array<string,string> $headers Request headers.
	 * @param string|null          $body    Raw body.
	 */
	private function execute( string $method, string $path, array $headers, ?string $body ): ApiResult {
		$started  = microtime( true );
		$response = $this->transport->request( $method, $this->base_url . $path, $headers, $body );
		$result   = $this->parse( $response );

		$this->logger->log(
			in_array( $result->outcome, array( Outcome::Success, Outcome::NotFound ), true ) ? Logger::DEBUG : Logger::WARNING,
			'API ' . $method . ' ' . $path,
			array(
				'status'     => $result->http_status,
				'outcome'    => $result->outcome->value,
				'ms'         => (int) round( ( microtime( true ) - $started ) * 1000 ),
				'request_id' => $result->request_id,
				'code'       => $result->error_code,
				'detail'     => $result->detail,
			)
		);

		return $result;
	}

	/**
	 * Turn a raw response into an ApiResult.
	 *
	 * @param Response $response Raw response.
	 */
	private function parse( Response $response ): ApiResult {
		$data = $response->body['data'] ?? array();
		$data = is_array( $data ) ? $data : array();

		$message_id = isset( $data['id'] ) && is_string( $data['id'] ) ? $data['id'] : $response->error_message_id();
		$retry      = $response->header( 'retry-after' );

		return new ApiResult(
			ErrorClassifier::classify( $response ),
			$response->status,
			$data,
			$response->error_detail() ?? $response->transport_error,
			null !== $retry && ctype_digit( $retry ) ? (int) $retry : null,
			$response->header( 'x-request-id' ),
			$message_id,
			$response->error_code()
		);
	}
}
