<?php
/**
 * Outcome of normalising a phone number.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Phone;

defined( 'ABSPATH' ) || exit;

/**
 * Either an E.164 number or a reason code.
 */
final class PhoneResult {

	/**
	 * Constructor.
	 *
	 * @param string|null $e164   Normalised number including "+", or null.
	 * @param string|null $reason One of "empty", "invalid", "no_country"; null when valid.
	 */
	private function __construct(
		public readonly ?string $e164,
		public readonly ?string $reason
	) {}

	/**
	 * Successful result.
	 *
	 * @param string $e164 Number including "+".
	 */
	public static function success( string $e164 ): self {
		return new self( $e164, null );
	}

	/**
	 * Failed result.
	 *
	 * @param string $reason Reason code.
	 */
	public static function failure( string $reason ): self {
		return new self( null, $reason );
	}

	/**
	 * Whether a usable number was produced.
	 */
	public function is_valid(): bool {
		return null !== $this->e164;
	}
}
