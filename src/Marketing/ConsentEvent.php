<?php
/**
 * One consent decision.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Marketing;

defined( 'ABSPATH' ) || exit;

/**
 * A customer agreeing to, or withdrawing from, promotional messages. Events are
 * only ever appended; the newest event for a number decides.
 */
final class ConsentEvent {

	public const SOURCE_CHECKOUT = 'checkout';
	public const SOURCE_ACCOUNT  = 'account';
	public const SOURCE_OPTOUT   = 'optout-link';
	public const SOURCE_PLATFORM = 'povikvane';

	/**
	 * Constructor.
	 *
	 * @param string      $phone_hash   Keyed hash of the number; survives erasure of the number itself.
	 * @param bool        $granted      True for agreeing, false for withdrawing.
	 * @param string      $source       One of the SOURCE_* constants.
	 * @param string|null $phone        Number in E.164, or null once erased.
	 * @param string|null $email        Email at the time, or null once erased.
	 * @param int         $order_id     Order the decision was made on, if any.
	 * @param int         $user_id      Customer account, if any.
	 * @param string      $wording_hash Short hash of the wording the customer saw.
	 * @param int         $created_at   Unix time.
	 * @param int|null    $id           Row ID once stored.
	 */
	public function __construct(
		public readonly string $phone_hash,
		public readonly bool $granted,
		public readonly string $source,
		public readonly ?string $phone = null,
		public readonly ?string $email = null,
		public readonly int $order_id = 0,
		public readonly int $user_id = 0,
		public readonly string $wording_hash = '',
		public readonly int $created_at = 0,
		public readonly ?int $id = null
	) {}
}
