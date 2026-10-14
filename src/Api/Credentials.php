<?php
/**
 * API credentials value object.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Api;

defined( 'ABSPATH' ) || exit;

/**
 * API key and service ID. Never log or print these.
 */
final class Credentials {

	/**
	 * Constructor.
	 *
	 * @param string $api_key    Bearer API key.
	 * @param string $service_id Service ID.
	 */
	public function __construct(
		private readonly string $api_key,
		public readonly string $service_id
	) {}

	/**
	 * Whether both values are present.
	 */
	public function is_complete(): bool {
		return '' !== $this->api_key && '' !== $this->service_id;
	}

	/**
	 * Authorization header value.
	 */
	public function bearer(): string {
		return 'Bearer ' . $this->api_key;
	}

	/**
	 * Keep secrets out of var_dump() and logs.
	 *
	 * @return array<string,string>
	 */
	public function __debugInfo(): array {
		return array(
			'api_key'    => '[hidden]',
			'service_id' => $this->service_id,
		);
	}
}
