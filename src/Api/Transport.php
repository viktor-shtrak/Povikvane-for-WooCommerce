<?php
/**
 * HTTP transport abstraction.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Lets the client be tested without network access.
 */
interface Transport {

	/**
	 * Perform a request.
	 *
	 * @param string               $method  HTTP method.
	 * @param string               $url     Absolute URL.
	 * @param array<string,string> $headers Request headers.
	 * @param string|null          $body    Raw request body.
	 */
	public function request( string $method, string $url, array $headers, ?string $body ): Response;
}
