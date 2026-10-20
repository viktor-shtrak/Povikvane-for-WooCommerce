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
use Povikvane\WooCommerce\Api\Outcome;
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
		'send'      => array(),
		'status'    => array(),
		'lookup'    => array(),
		'account'   => array(),
		'templates' => array(),
		'rules'     => array(),
		'create'    => array(),
		'labels'    => array(),
		'group'     => array(),
		'contacts'  => array(),
		'erase'     => array(),
		'suppress'  => array(),
		'webhook'   => array(),
		'wtest'     => array(),
		'estimate'  => array(),
	);

	/**
	 * Payloads of write calls, in order.
	 *
	 * @var array<int,array{op:string,args:array<mixed>}>
	 */
	public array $writes = array();

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

		// The templates endpoint is not released yet, so it answers "not found" unless a test says otherwise.
		if ( null === $result && 'templates' === $operation ) {
			return new ApiResult( Outcome::NotFound, 404 );
		}

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

	/**
	 * {@inheritDoc}
	 */
	public function templates(): ApiResult {
		return $this->next( 'templates' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function template_rules(): ApiResult {
		return $this->next( 'rules' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $name    Name.
	 * @param string $lang    Language.
	 * @param string $content Text.
	 */
	public function create_template( string $name, string $lang, string $content ): ApiResult {
		$this->writes[] = array(
			'op'   => 'create',
			'args' => compact( 'name', 'lang', 'content' ),
		);

		return $this->next( 'create' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string,string> $labels Labels.
	 */
	public function set_custom_fields( array $labels ): ApiResult {
		$this->writes[] = array(
			'op'   => 'labels',
			'args' => $labels,
		);

		return $this->next( 'labels' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $name Name.
	 */
	public function ensure_group( string $name ): ApiResult {
		return $this->next( 'group' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string                         $group_id Group.
	 * @param array<int,array<string,mixed>> $contacts Contacts.
	 */
	public function upsert_contacts( string $group_id, array $contacts ): ApiResult {
		$this->writes[] = array(
			'op'   => 'contacts',
			'args' => $contacts,
		);

		return $this->next( 'contacts' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $phone Phone.
	 */
	public function delete_contact( string $phone ): ApiResult {
		$this->writes[] = array(
			'op'   => 'erase',
			'args' => array( $phone ),
		);

		return $this->next( 'erase' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string|null $url Address.
	 */
	public function set_webhook( ?string $url ): ApiResult {
		$this->writes[] = array(
			'op'   => 'webhook',
			'args' => array( $url ),
		);

		return $this->next( 'webhook' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function test_webhook(): ApiResult {
		return $this->next( 'wtest' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string,mixed> $message Message.
	 */
	public function estimate( array $message ): ApiResult {
		return $this->next( 'estimate' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<int,array<string,string>> $numbers Numbers.
	 */
	public function add_suppressions( array $numbers ): ApiResult {
		$this->writes[] = array(
			'op'   => 'suppress',
			'args' => $numbers,
		);

		return $this->next( 'suppress' );
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
	 * @param string $recipient Number.
	 * @param int    $since     Time.
	 */
	public function count_marketing_since( string $recipient, int $since ): int {
		$counted = array( \Povikvane\WooCommerce\Messages\Status::Queued, \Povikvane\WooCommerce\Messages\Status::Delivered, \Povikvane\WooCommerce\Messages\Status::Unconfirmed, \Povikvane\WooCommerce\Messages\Status::Sending );

		return count(
			array_filter(
				$this->rows,
				static fn( Message $row ): bool => \Povikvane\WooCommerce\Messages\Kind::Marketing === $row->kind && $row->recipient === $recipient && $row->created_at >= $since && in_array( $row->status, $counted, true )
			)
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $api_message_id API ID.
	 */
	public function find_by_api_message_id( string $api_message_id ): ?Message {
		foreach ( $this->rows as $row ) {
			if ( $row->api_message_id === $api_message_id ) {
				return clone $row;
			}
		}

		return null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $now   Now.
	 * @param int $limit Limit.
	 */
	public function due_for_check( int $now, int $limit ): array {
		$due = array_filter(
			$this->rows,
			static fn( Message $row ): bool => in_array( $row->status, array( \Povikvane\WooCommerce\Messages\Status::Queued, \Povikvane\WooCommerce\Messages\Status::Unconfirmed ), true )
				&& null !== $row->api_message_id
				&& null !== $row->next_attempt_at
				&& $row->next_attempt_at <= $now
		);

		return array_map( static fn( Message $row ): Message => clone $row, array_slice( array_values( $due ), 0, $limit ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $before Time.
	 * @param int $limit  Limit.
	 */
	public function stuck_sending( int $before, int $limit ): array {
		$stuck = array_filter(
			$this->rows,
			static fn( Message $row ): bool => \Povikvane\WooCommerce\Messages\Status::Sending === $row->status && $row->updated_at < $before
		);

		return array_map( static fn( Message $row ): Message => clone $row, array_slice( array_values( $stuck ), 0, $limit ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $before Time.
	 */
	public function purge_bodies( int $before ): int {
		$count = 0;
		foreach ( $this->rows as $row ) {
			if ( $this->purgeable( $row, $before ) && '' !== $row->body ) {
				$row->body  = '';
				$row->viber = null;
				++$count;
			}
		}

		return $count;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $before Time.
	 */
	public function delete_rows( int $before ): int {
		$count = 0;
		foreach ( $this->rows as $id => $row ) {
			if ( $this->purgeable( $row, $before ) ) {
				unset( $this->rows[ $id ] );
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Whether a row is old and finished.
	 *
	 * @param Message $row    Row.
	 * @param int     $before Cutoff.
	 */
	private function purgeable( Message $row, int $before ): bool {
		return $row->created_at < $before
			&& ! in_array( $row->status, array( \Povikvane\WooCommerce\Messages\Status::Pending, \Povikvane\WooCommerce\Messages\Status::Sending, \Povikvane\WooCommerce\Messages\Status::HeldCredits ), true );
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

/**
 * In-memory webhook activity.
 */
final class InMemoryActivity implements \Povikvane\WooCommerce\Webhook\WebhookActivity {

	/**
	 * Recorded requests.
	 *
	 * @var array<int,array{int,string}>
	 */
	public array $records = array();

	/**
	 * {@inheritDoc}
	 *
	 * @param int    $time   Time.
	 * @param string $result Result.
	 */
	public function record( int $time, string $result ): void {
		$this->records[] = array( $time, $result );
	}

	/**
	 * {@inheritDoc}
	 */
	public function last(): ?array {
		$last = end( $this->records );

		return false === $last ? null : array(
			'time'   => $last[0],
			'result' => $last[1],
		);
	}
}

/**
 * In-memory consent ledger.
 */
final class InMemoryLedger implements \Povikvane\WooCommerce\Marketing\ConsentLedger {

	/**
	 * Events in the order recorded.
	 *
	 * @var \Povikvane\WooCommerce\Marketing\ConsentEvent[]
	 */
	public array $events = array();

	/**
	 * {@inheritDoc}
	 *
	 * @param string $phone_hash Hash.
	 */
	public function latest( string $phone_hash ): ?\Povikvane\WooCommerce\Marketing\ConsentEvent {
		$found = null;
		foreach ( $this->events as $event ) {
			if ( $event->phone_hash === $phone_hash ) {
				$found = $event;
			}
		}

		return $found;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param \Povikvane\WooCommerce\Marketing\ConsentEvent $event Event.
	 */
	public function append( \Povikvane\WooCommerce\Marketing\ConsentEvent $event ): void {
		$this->events[] = $event;
	}
}
