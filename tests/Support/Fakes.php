<?php
/**
 * Test doubles for the dispatcher and client tests.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Tests\Support;

use LogicException;
use Povikvane\WooCommerce\Api\ApiResult;
use Povikvane\WooCommerce\Api\Gateway;
use Povikvane\WooCommerce\Api\Response;
use Povikvane\WooCommerce\Api\SendRequest;
use Povikvane\WooCommerce\Api\Transport;
use Povikvane\WooCommerce\Log\Message;
use Povikvane\WooCommerce\Log\MessageStore;
use Povikvane\WooCommerce\Support\Clock;
use Povikvane\WooCommerce\Support\Notifier;
use Povikvane\WooCommerce\Support\Scheduler;
use Povikvane\WooCommerce\Support\SendingState;

/**
 * Transport returning queued responses and recording requests.
 */
final class FakeTransport implements Transport {

	/**
	 * Recorded requests.
	 *
	 * @var array<int,array{method:string,url:string,headers:array<string,string>,body:?string}>
	 */
	public array $requests = array();

	/**
	 * Responses to return in order.
	 *
	 * @var Response[]
	 */
	private array $queue = array();

	/**
	 * Queue a response.
	 *
	 * @param Response $response Response.
	 */
	public function will_return( Response $response ): self {
		$this->queue[] = $response;

		return $this;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $method  Method.
	 * @param string               $url     URL.
	 * @param array<string,string> $headers Headers.
	 * @param string|null          $body    Body.
	 */
	public function request( string $method, string $url, array $headers, ?string $body ): Response {
		$this->requests[] = compact( 'method', 'url', 'headers', 'body' );

		$response = array_shift( $this->queue );
		if ( null === $response ) {
			throw new LogicException( 'No response queued' );
		}

		return $response;
	}
}

/**
 * Gateway returning queued results.
 */
final class FakeGateway implements Gateway {

	/**
	 * Sent requests.
	 *
	 * @var SendRequest[]
	 */
	public array $sent = array();

	/**
	 * Queued results per operation.
	 *
	 * @var array<string,ApiResult[]>
	 */
	private array $queues = array(
		'send'    => array(),
		'status'  => array(),
		'lookup'  => array(),
		'account' => array(),
	);

	/**
	 * Calls made, in order, for example "status", "account".
	 *
	 * @var string[]
	 */
	public array $calls = array();

	/**
	 * Queue a send result.
	 *
	 * @param ApiResult $result Result.
	 */
	public function will_return( ApiResult $result ): self {
		$this->queues['send'][] = $result;

		return $this;
	}

	/**
	 * Queue a result for another operation.
	 *
	 * @param string    $operation "status", "lookup" or "account".
	 * @param ApiResult $result    Result.
	 */
	public function will_return_for( string $operation, ApiResult $result ): self {
		$this->queues[ $operation ][] = $result;

		return $this;
	}

	/**
	 * Take the next queued result.
	 *
	 * @param string $operation Operation name.
	 */
	private function next( string $operation ): ApiResult {
		$this->calls[] = $operation;

		$result = array_shift( $this->queues[ $operation ] );
		if ( null === $result ) {
			throw new LogicException( "No {$operation} result queued" );
		}

		return $result;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param SendRequest $request Request.
	 */
	public function send( SendRequest $request ): ApiResult {
		$this->sent[] = $request;

		return $this->next( 'send' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $message_id ID.
	 */
	public function message_status( string $message_id ): ApiResult {
		return $this->next( 'status' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $reference Reference.
	 */
	public function find_by_reference( string $reference ): ApiResult {
		return $this->next( 'lookup' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function account(): ApiResult {
		return $this->next( 'account' );
	}
}

/**
 * In-memory message store.
 */
final class InMemoryStore implements MessageStore {

	/**
	 * Rows by ID.
	 *
	 * @var array<int,Message>
	 */
	public array $rows = array();

	/**
	 * Number of save() calls.
	 *
	 * @var int
	 */
	public int $saves = 0;

	/**
	 * {@inheritDoc}
	 *
	 * @param Message $message Message.
	 */
	public function create( Message $message ): ?int {
		if ( null !== $this->find_by_reference( $message->reference ) ) {
			return null;
		}

		$id                = count( $this->rows ) + 1;
		$message->id       = $id;
		$this->rows[ $id ] = clone $message;

		return $id;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $id ID.
	 */
	public function find( int $id ): ?Message {
		return isset( $this->rows[ $id ] ) ? clone $this->rows[ $id ] : null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $reference Reference.
	 */
	public function find_by_reference( string $reference ): ?Message {
		foreach ( $this->rows as $row ) {
			if ( $row->reference === $reference ) {
				return clone $row;
			}
		}

		return null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Message $message Message.
	 */
	public function save( Message $message ): void {
		++$this->saves;
		$this->rows[ (int) $message->id ] = clone $message;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $since Unix time.
	 */
	public function count_created_since( int $since ): int {
		$count = 0;
		foreach ( $this->rows as $row ) {
			if ( $row->created_at >= $since && \Povikvane\WooCommerce\Messages\Status::Skipped !== $row->status ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int    $order_id Order ID.
	 * @param string $event    Event.
	 */
	public function count_for_order_event( int $order_id, string $event ): int {
		return count( array_filter( $this->rows, static fn( Message $row ): bool => $row->order_id === $order_id && $row->event === $event ) );
	}
}

/**
 * Adjustable clock.
 */
final class FixedClock implements Clock {

	/**
	 * Constructor.
	 *
	 * @param int $time Current time.
	 */
	public function __construct( public int $time = 1760000000 ) {}

	/**
	 * {@inheritDoc}
	 */
	public function now(): int {
		return $this->time;
	}
}

/**
 * Scheduler recording requested runs.
 */
final class RecordingScheduler implements Scheduler {

	/**
	 * Scheduled runs as [id, delay].
	 *
	 * @var array<int,array{int,int}>
	 */
	public array $runs = array();

	/**
	 * {@inheritDoc}
	 *
	 * @param int $message_id    ID.
	 * @param int $delay_seconds Delay.
	 */
	public function schedule_send( int $message_id, int $delay_seconds = 0 ): void {
		$this->runs[] = array( $message_id, $delay_seconds );
	}

	/**
	 * Delays of requested balance refreshes.
	 *
	 * @var int[]
	 */
	public array $balance_refreshes = array();

	/**
	 * {@inheritDoc}
	 *
	 * @param int $delay_seconds Delay.
	 */
	public function schedule_balance_refresh( int $delay_seconds ): void {
		$this->balance_refreshes[] = $delay_seconds;
	}
}

/**
 * Notifier recording note codes.
 */
final class RecordingNotifier implements Notifier {

	/**
	 * Recorded note codes.
	 *
	 * @var string[]
	 */
	public array $codes = array();

	/**
	 * {@inheritDoc}
	 *
	 * @param Message             $message Message.
	 * @param string              $code    Code.
	 * @param array<string,mixed> $params  Params.
	 */
	public function note( Message $message, string $code, array $params = array() ): void {
		$this->codes[] = $code;
	}
}

/**
 * In-memory pause switch.
 */
final class InMemoryState implements SendingState {

	/**
	 * Current reason.
	 *
	 * @var string|null
	 */
	public ?string $reason = null;

	/**
	 * {@inheritDoc}
	 */
	public function paused_reason(): ?string {
		return $this->reason;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $reason Reason.
	 */
	public function pause( string $reason ): void {
		$this->reason = $reason;
	}

	/**
	 * {@inheritDoc}
	 */
	public function resume(): void {
		$this->reason = null;
	}
}

/**
 * Logger recording formatted lines.
 */
final class RecordingLogger implements \Povikvane\WooCommerce\Support\Logger {

	/**
	 * Formatted lines, prefixed with the level.
	 *
	 * @var string[]
	 */
	public array $lines = array();

	/**
	 * {@inheritDoc}
	 *
	 * @param string              $level   Level.
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Context.
	 */
	public function log( string $level, string $message, array $context = array() ): void {
		$this->lines[] = $level . ': ' . \Povikvane\WooCommerce\Support\LogFormatter::format( $message, $context );
	}
}

/**
 * In-memory balance store.
 */
final class InMemoryBalanceStore implements \Povikvane\WooCommerce\Balance\BalanceStore {

	/**
	 * Stored balance.
	 *
	 * @var \Povikvane\WooCommerce\Balance\Balance|null
	 */
	public ?\Povikvane\WooCommerce\Balance\Balance $balance = null;

	/**
	 * When the last low-balance alert went out.
	 *
	 * @var int|null
	 */
	public ?int $alert_at = null;

	/**
	 * {@inheritDoc}
	 */
	public function get(): ?\Povikvane\WooCommerce\Balance\Balance {
		return $this->balance;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param \Povikvane\WooCommerce\Balance\Balance $balance Balance.
	 */
	public function save( \Povikvane\WooCommerce\Balance\Balance $balance ): void {
		$this->balance = $balance;
	}

	/**
	 * {@inheritDoc}
	 */
	public function alert_sent_at(): ?int {
		return $this->alert_at;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $time Time.
	 */
	public function mark_alert_sent( int $time ): void {
		$this->alert_at = $time;
	}

	/**
	 * {@inheritDoc}
	 */
	public function clear_alert(): void {
		$this->alert_at = null;
	}
}

/**
 * Mailer recording low-balance emails.
 */
final class RecordingMailer implements \Povikvane\WooCommerce\Balance\LowBalanceMailer {

	/**
	 * Sent emails as [available, threshold].
	 *
	 * @var array<int,array{float,int}>
	 */
	public array $sent = array();

	/**
	 * {@inheritDoc}
	 *
	 * @param float $available Credits left.
	 * @param int   $threshold Warning threshold.
	 */
	public function send( float $available, int $threshold ): void {
		$this->sent[] = array( $available, $threshold );
	}
}
