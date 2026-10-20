<?php
/**
 * What Povikvane says a message will cost.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Messages;

use Povikvane\WooCommerce\Api\ApiResult;
use Povikvane\WooCommerce\Api\Outcome;

defined( 'ABSPATH' ) || exit;

/**
 * "credits" is the price once the message is delivered and is the one to show;
 * "initial_debit" is only what is reserved when it is sent.
 */
final class PriceEstimate {

	/**
	 * Constructor.
	 *
	 * @param float       $credits       Final price in credits.
	 * @param float       $initial_debit Credits reserved at send time.
	 * @param string|null $message_type  "transactional" or "promotional" for Viber, null for SMS.
	 * @param bool        $suppressed    Whether the recipient has opted out in Povikvane.
	 */
	public function __construct(
		public readonly float $credits,
		public readonly float $initial_debit,
		public readonly ?string $message_type,
		public readonly bool $suppressed
	) {}

	/**
	 * Read an estimate answer.
	 *
	 * @param ApiResult $result Answer to the estimate request.
	 * @return self|null Null when Povikvane did not price the message.
	 */
	public static function from_result( ApiResult $result ): ?self {
		$attributes = $result->attributes();

		if ( Outcome::Success !== $result->outcome || ! isset( $attributes['credits'] ) || ! is_numeric( $attributes['credits'] ) ) {
			return null;
		}

		$recipient = is_array( $attributes['recipient'] ?? null ) ? $attributes['recipient'] : array();
		$type      = $attributes['message_type'] ?? null;

		return new self(
			(float) $attributes['credits'],
			isset( $attributes['initial_debit'] ) && is_numeric( $attributes['initial_debit'] ) ? (float) $attributes['initial_debit'] : (float) $attributes['credits'],
			is_string( $type ) ? $type : null,
			true === ( $recipient['suppressed'] ?? false )
		);
	}
}
