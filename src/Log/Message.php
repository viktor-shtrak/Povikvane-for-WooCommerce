<?php
/**
 * A logged message.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Log;

use Povikvane\WooCommerce\Messages\Kind;
use Povikvane\WooCommerce\Messages\Status;

defined( 'ABSPATH' ) || exit;

/**
 * One row of the message log. Timestamps are Unix seconds (UTC).
 */
final class Message {

	/**
	 * Row ID; null until stored.
	 *
	 * @var int|null
	 */
	public ?int $id = null;

	/**
	 * Order ID; 0 for messages not tied to an order (tests).
	 *
	 * @var int
	 */
	public int $order_id = 0;

	/**
	 * Event slug, for example "processing" or "test".
	 *
	 * @var string
	 */
	public string $event = '';

	/**
	 * Transactional or marketing.
	 *
	 * @var Kind
	 */
	public Kind $kind = Kind::Transactional;

	/**
	 * Channel; "sms" in v1.
	 *
	 * @var string
	 */
	public string $channel = 'sms';

	/**
	 * Recipient in E.164.
	 *
	 * @var string
	 */
	public string $recipient = '';

	/**
	 * Message body.
	 *
	 * @var string
	 */
	public string $body = '';

	/**
	 * Reference; also the idempotency key. Unique.
	 *
	 * @var string
	 */
	public string $reference = '';

	/**
	 * Message UUID returned by the API.
	 *
	 * @var string|null
	 */
	public ?string $api_message_id = null;

	/**
	 * Lifecycle status.
	 *
	 * @var Status
	 */
	public Status $status = Status::Pending;

	/**
	 * Why the message was skipped.
	 *
	 * @var string|null
	 */
	public ?string $skip_reason = null;

	/**
	 * Short machine-readable error code.
	 *
	 * @var string|null
	 */
	public ?string $error_code = null;

	/**
	 * Error detail; never contains secrets.
	 *
	 * @var string|null
	 */
	public ?string $error_detail = null;

	/**
	 * SMS segments charged.
	 *
	 * @var float|null
	 */
	public ?float $segments = null;

	/**
	 * Credits charged.
	 *
	 * @var float|null
	 */
	public ?float $credits = null;

	/**
	 * Send attempts made so far.
	 *
	 * @var int
	 */
	public int $attempts = 0;

	/**
	 * Earliest time the first send may happen (set when a message is delayed).
	 *
	 * @var int|null
	 */
	public ?int $send_after = null;

	/**
	 * Delivery status checks made so far.
	 *
	 * @var int
	 */
	public int $checks = 0;

	/**
	 * When the next attempt is due.
	 *
	 * @var int|null
	 */
	public ?int $next_attempt_at = null;

	/**
	 * Created at.
	 *
	 * @var int
	 */
	public int $created_at = 0;

	/**
	 * Last updated at.
	 *
	 * @var int
	 */
	public int $updated_at = 0;

	/**
	 * When the message reached a final status.
	 *
	 * @var int|null
	 */
	public ?int $final_at = null;
}
