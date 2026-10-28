<?php
/**
 * Tests for Viber: templates, planning, the request, the SMS reroute and the delivery channel.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Povikvane\WooCommerce\Api\ApiResult;
use Povikvane\WooCommerce\Api\Capabilities;
use Povikvane\WooCommerce\Api\Client;
use Povikvane\WooCommerce\Api\Credentials;
use Povikvane\WooCommerce\Api\Outcome;
use Povikvane\WooCommerce\Api\Response;
use Povikvane\WooCommerce\Api\SendRequest;
use Povikvane\WooCommerce\Log\Message;
use Povikvane\WooCommerce\Messages\Channel;
use Povikvane\WooCommerce\Messages\DeliveryUpdater;
use Povikvane\WooCommerce\Messages\Dispatcher;
use Povikvane\WooCommerce\Messages\Enqueuer;
use Povikvane\WooCommerce\Messages\Placeholders;
use Povikvane\WooCommerce\Messages\Reconciler;
use Povikvane\WooCommerce\Messages\Status;
use Povikvane\WooCommerce\Orders\OrderEventHandler;
use Povikvane\WooCommerce\Orders\OrderFacts;
use Povikvane\WooCommerce\Orders\Rules;
use Povikvane\WooCommerce\Orders\Trigger;
use Povikvane\WooCommerce\Orders\TriggerRepository;
use Povikvane\WooCommerce\Phone\Normalizer;
use Povikvane\WooCommerce\Safety\StormGuard;
use Povikvane\WooCommerce\Tests\Support\FakeGateway;
use Povikvane\WooCommerce\Tests\Support\FakeTransport;
use Povikvane\WooCommerce\Tests\Support\FixedClock;
use Povikvane\WooCommerce\Tests\Support\InMemoryActivity;
use Povikvane\WooCommerce\Tests\Support\InMemoryState;
use Povikvane\WooCommerce\Tests\Support\InMemoryStore;
use Povikvane\WooCommerce\Tests\Support\RecordingNotifier;
use Povikvane\WooCommerce\Tests\Support\RecordingScheduler;
use Povikvane\WooCommerce\Viber\TemplateCatalog;
use Povikvane\WooCommerce\Viber\TemplateSync;
use Povikvane\WooCommerce\Viber\TemplateDraft;
use Povikvane\WooCommerce\Viber\TemplateFiller;
use Povikvane\WooCommerce\Viber\TemplateMatch;
use Povikvane\WooCommerce\Viber\TemplateMatcher;
use Povikvane\WooCommerce\Viber\ViberPlanner;
use Povikvane\WooCommerce\Viber\ViberTemplate;
use Povikvane\WooCommerce\Webhook\SelfTest;
use Povikvane\WooCommerce\Webhook\WebhookHandler;

/**
 * Viber behaviour, against the API as documented on 14 October 2026.
 */
final class ViberTest extends TestCase {

	private const NOW = 1760000000;

	/**
	 * Start every test with no stored templates.
	 */
	protected function setUp(): void {
		delete_option( TemplateCatalog::OPTION );
		delete_option( TriggerRepository::OPTION );
	}

	/**
	 * One template as the API returns it.
	 *
	 * @param array<string,mixed> $attributes Attribute overrides.
	 * @param string              $id         Template ID.
	 * @return array<string,mixed>
	 */
	private function api_item( array $attributes = array(), string $id = 'tpl-1' ): array {
		return array(
			'type'       => 'template',
			'id'         => $id,
			'attributes' => array_merge(
				array(
					'name'                  => 'Order shipped',
					'channel'               => 'viber',
					'content'               => 'Order {customField1} from {customField2} is shipped.',
					'variables'             => array( 'customField1', 'customField2' ),
					'approval_status'       => 'approved',
					'provider_status'       => 'approved',
					'message_type'          => 'transactional',
					'why_not_transactional' => null,
				),
				$attributes
			),
		);
	}

	/**
	 * Store templates in the catalog.
	 *
	 * @param array<int,array<string,mixed>> $items API items.
	 */
	private function catalog( array $items ): TemplateCatalog {
		$templates = array();
		foreach ( $items as $item ) {
			$templates[] = ViberTemplate::from_api( $item );
		}

		$catalog = new TemplateCatalog();
		$catalog->replace( array_filter( $templates ), self::NOW );

		return $catalog;
	}

	/**
	 * Order values.
	 *
	 * @param array<string,string> $overrides Overrides.
	 * @return array<string,string>
	 */
	private function values( array $overrides = array() ): array {
		return array_merge(
			array(
				'order_number' => '1234',
				'shop_name'    => 'Demo Shop',
			),
			$overrides
		);
	}

	/**
	 * The message the shop writes, which the test template is the Viber form of.
	 */
	private function text(): string {
		return 'Order {order_number} from {shop_name} is shipped.';
	}

	// ---- Basics ----

	/**
	 * Unknown values are SMS.
	 */
	public function test_channel_parsing_defaults_to_sms(): void {
		$this->assertSame( Channel::Viber, Channel::from_value( 'viber' ) );
		$this->assertSame( Channel::Sms, Channel::from_value( 'telegram' ) );
		$this->assertSame( Channel::Sms, Channel::from_value( null ) );
		$this->assertSame( Channel::Sms, Channel::from_value( 5 ) );
	}

	/**
	 * A template is read from the API shape and filled, with either kind of braces.
	 */
	public function test_template_parsing_and_rendering(): void {
		$template = ViberTemplate::from_api( $this->api_item() );

		$this->assertNotNull( $template );
		$this->assertSame( 'tpl-1', $template->id );
		$this->assertSame( array( 'customField1', 'customField2' ), $template->variables );
		$this->assertTrue( $template->is_transactional() );
		$this->assertSame(
			'Order 1234 from Demo Shop is shipped.',
			$template->render(
				array(
					'customField1' => '1234',
					'customField2' => 'Demo Shop',
				)
			)
		);

		$double = ViberTemplate::from_api(
			$this->api_item(
				array(
					'content'   => 'Hi {{firstName}}!',
					'variables' => array( 'firstName' ),
				)
			)
		);
		$this->assertSame( 'Hi Maria!', $double?->render( array( 'firstName' => 'Maria' ) ) );

		$this->assertNull( ViberTemplate::from_api( array( 'id' => 'x' ) ) );
		$this->assertSame( 'marketing', ViberTemplate::from_api( $this->api_item( array( 'message_type' => 'marketing' ) ) )?->message_type );
		$this->assertFalse(
			ViberTemplate::from_api(
				$this->api_item(
					array(
						'approval_status' => 'pending',
						'message_type'    => null,
					)
				)
			)?->is_transactional()
		);
	}

	/**
	 * A text and a template are the same message when the fixed words and the variable spots line up.
	 */
	public function test_text_matches_a_template_by_structure(): void {
		$catalog = $this->catalog( array( $this->api_item() ) );
		$match   = TemplateMatcher::find( $this->text(), $catalog );

		$this->assertSame( TemplateMatch::READY, $match->state );
		$this->assertSame(
			array(
				'customField1' => 'order_number',
				'customField2' => 'shop_name',
			),
			$match->mapping
		);

		$this->assertSame( TemplateMatch::READY, TemplateMatcher::find( "Order  {ORDER_NUMBER}\nfrom {shop_name} is shipped. ", $catalog )->state, 'spacing and case do not matter' );
		$this->assertSame( TemplateMatch::NONE, TemplateMatcher::find( 'Order {order_number} from {shop_name} is delivered.', $catalog )->state, 'different words' );
		$this->assertSame( TemplateMatch::NONE, TemplateMatcher::find( 'Order {order_number} is shipped.', $catalog )->state, 'a spot fewer' );
		$this->assertSame( TemplateMatch::NONE, TemplateMatcher::find( 'Order {order_number} from {nonsense} is shipped.', $catalog )->state, 'unknown placeholder' );
		$this->assertSame( TemplateMatch::NONE, TemplateMatcher::find( '', $catalog )->state );
	}

	/**
	 * Names are filled by Povikvane itself, so a template that needs them is never matched.
	 */
	public function test_templates_with_names_do_not_match(): void {
		$catalog = $this->catalog(
			array(
				$this->api_item(
					array(
						'content'   => 'Hi {firstName}, order {customField1} is shipped.',
						'variables' => array( 'firstName', 'customField1' ),
					)
				),
			)
		);

		$this->assertSame( TemplateMatch::NONE, TemplateMatcher::find( 'Hi {first_name}, order {order_number} is shipped.', $catalog )->state );
	}

	/**
	 * Approved templates win over waiting or rejected ones, and the state is reported.
	 */
	public function test_match_state_follows_the_template(): void {
		$states = array(
			'approved'  => array( array(), TemplateMatch::READY ),
			'marketing' => array( array( 'message_type' => 'marketing' ), TemplateMatch::NOT_TRANSACTION ),
			'pending'   => array(
				array(
					'approval_status' => 'pending',
					'message_type'    => null,
				),
				TemplateMatch::PENDING,
			),
			'rejected'  => array(
				array(
					'approval_status' => 'rejected',
					'message_type'    => null,
				),
				TemplateMatch::REJECTED,
			),
		);

		foreach ( $states as $name => $case ) {
			$catalog = $this->catalog( array( $this->api_item( $case[0] ) ) );
			$this->assertSame( $case[1], TemplateMatcher::find( $this->text(), $catalog )->state, $name );
		}

		$both = $this->catalog(
			array(
				$this->api_item(
					array(
						'approval_status' => 'rejected',
						'message_type'    => null,
					),
					'old'
				),
				$this->api_item( array(), 'new' ),
			)
		);
		$this->assertSame( 'new', TemplateMatcher::find( $this->text(), $both )->template?->id );
	}

	/**
	 * A template is usable only once the carrier (MsgHub) has approved it, whatever Povikvane says.
	 */
	public function test_only_a_carrier_approved_template_is_usable(): void {
		foreach ( array( null, 'pending', 'submitted', '' ) as $provider ) {
			$catalog = $this->catalog( array( $this->api_item( array( 'provider_status' => $provider ) ) ) );
			$match   = TemplateMatcher::find( $this->text(), $catalog );

			$this->assertSame( TemplateMatch::PENDING, $match->state, 'carrier status: ' . wp_json_encode( $provider ) );
			$this->assertFalse( $match->is_ready() );
			$this->assertInstanceOf( ViberTemplate::class, $match->template );
			$this->assertFalse( $match->template->is_transactional() );
			$this->assertSame( ViberPlanner::PROBLEM_NOT_APPROVED, ViberPlanner::problem( $match->template ) );
		}

		$approved = $this->catalog( array( $this->api_item( array( 'provider_status' => 'Approved' ) ) ) );

		$this->assertTrue( TemplateMatcher::find( $this->text(), $approved )->is_ready() );
	}

	/**
	 * Approved by Povikvane but still with the carrier counts as waiting, not as a wrong kind of template.
	 */
	public function test_template_waiting_for_the_carrier_is_pending(): void {
		$waiting = $this->catalog(
			array(
				$this->api_item(
					array(
						'approval_status'       => 'approved',
						'internal_status'       => 'approved',
						'provider_status'       => 'pending',
						'message_type'          => 'marketing',
						'why_not_transactional' => 'awaiting provider approval',
					)
				),
			)
		);
		$this->assertSame( TemplateMatch::PENDING, TemplateMatcher::find( $this->text(), $waiting )->state );

		$declined = $this->catalog(
			array(
				$this->api_item(
					array(
						'approval_status' => 'approved',
						'provider_status' => 'rejected',
						'message_type'    => 'marketing',
					)
				),
			)
		);
		$this->assertSame( TemplateMatch::REJECTED, TemplateMatcher::find( $this->text(), $declined )->state );

		$internal = $this->catalog(
			array(
				$this->api_item(
					array(
						'approval_status' => 'pending',
						'internal_status' => 'rejected',
						'message_type'    => null,
					)
				),
			)
		);
		$this->assertSame( TemplateMatch::REJECTED, TemplateMatcher::find( $this->text(), $internal )->state );

		$marketing = $this->catalog(
			array(
				$this->api_item(
					array(
						'message_type'          => 'marketing',
						'why_not_transactional' => 'content looks promotional',
					)
				),
			)
		);
		$this->assertSame( TemplateMatch::NOT_TRANSACTION, TemplateMatcher::find( $this->text(), $marketing )->state );

		$template = ViberTemplate::from_api(
			$this->api_item(
				array(
					'lang'            => 'en',
					'provider_status' => 'approved',
				)
			)
		);
		$this->assertSame( 'en', $template?->lang );
		$this->assertSame( 'approved', ViberTemplate::from_array( (array) $template?->to_array() )?->provider_status );
	}

	/**
	 * Povikvane's own rules: punctuation after the last field does not make it text, and a field
	 * may follow a fixed web address but not be part of its domain.
	 */
	public function test_draft_follows_the_provider_rules(): void {
		foreach ( array( 'Order {order_number} from {shop_name}.', 'Order {order_number} from {shop_name}!?  ', 'Hello, order {order_number}.', 'Order is ready (ref {shop_name})', 'Say "{shop_name}"' ) as $text ) {
			$this->assertSame( TemplateDraft::PROBLEM_EDGE, TemplateDraft::from_text( $text )->problem, $text );
		}

		$this->assertNull( TemplateDraft::from_text( 'Order {order_number} from {shop_name} is ready.' )->problem );
		$this->assertNull( TemplateDraft::from_text( 'Order {order_number} from {shop_name}. Thanks!' )->problem );

		foreach ( array( 'Open https://{order_number}/details now', 'Open www.{order_number}/details now', 'Open https://portal.example.com{order_number}/details now', 'Open https://portal.example.com:{order_number}/details now' ) as $text ) {
			$this->assertSame( TemplateDraft::PROBLEM_URL_HOST, TemplateDraft::from_text( $text )->problem, $text );
		}

		foreach ( array( 'Open https://portal.example.com/p/{order_number} for details.', 'Open https://portal.example.com/view?id={order_number} for details.', 'Hello, order {order_number} from {shop_name}, thanks.' ) as $text ) {
			$this->assertNull( TemplateDraft::from_text( $text )->problem, $text );
		}
	}

	/**
	 * All four details work over Viber: the order number, total and shop name travel in fields 1, 2 and 3,
	 * and the shop address is written into the template as plain text.
	 */
	public function test_all_four_details_work_over_viber(): void {
		$text  = 'Order {order_number} for {order_total} from {shop_name}. See {shop_url} for details.';
		$draft = TemplateDraft::from_text( $text );

		$this->assertNull( $draft->problem );
		$this->assertSame( 'Order {customField1} for {customField2} from {customField3}. See https://shop.test for details.', $draft->content );

		// What Povikvane stores after the carrier approves it.
		$catalog = $this->catalog(
			array(
				$this->api_item(
					array(
						'content'   => $draft->content,
						'variables' => array( 'customField1', 'customField2', 'customField3' ),
					)
				),
			)
		);

		$this->assertTrue( TemplateMatcher::find( $text, $catalog )->is_ready() );

		$values = array(
			'order_number' => '1234',
			'order_total'  => '25,00 лв.',
			'shop_name'    => 'Demo Shop',
			'shop_url'     => 'https://shop.test',
		);
		$plan   = ViberPlanner::plan( true, true, false, $text, $values, Placeholders::render( $text, $values ), $catalog );

		$this->assertNotNull( $plan );
		$this->assertSame(
			array(
				'customField1' => '1234',
				'customField2' => '25,00 лв.',
				'customField3' => 'Demo Shop',
			),
			$plan->params,
			'the order values fill the fields; the address is not a field'
		);
		$this->assertSame( 'Order 1234 for 25,00 лв. from Demo Shop. See https://shop.test for details.', $plan->text );

		// Picking that template later brings back the message as it is written here.
		$this->assertSame( $text, TemplateFiller::text_for( $catalog->all()[0] ) );

		// The address may end a message; a changing link still may not be filled in.
		$this->assertNull( TemplateDraft::from_text( 'Order {order_number} is ready, see {shop_url}' )->problem );
		$this->assertSame( TemplateDraft::PROBLEM_LINK, TemplateDraft::from_text( 'Track {order_number} here: {tracking_url} please.' )->problem );
	}

	/**
	 * A text becomes a template with numbered fields, or is refused with a reason.
	 */
	public function test_draft_turns_a_text_into_a_template(): void {
		$draft = TemplateDraft::from_text( 'Order {order_number} from {shop_name} is shipped. Thanks, {shop_name} team.' );

		$this->assertNull( $draft->problem );
		$this->assertSame( 'Order {customField1} from {customField3} is shipped. Thanks, {customField3} team.', $draft->content );
		$this->assertSame(
			array(
				'customField1' => 'order_number',
				'customField3' => 'shop_name',
			),
			$draft->mapping,
			'the order number is always field 1, the total field 2, the shop name field 3'
		);

		$this->assertSame( TemplateDraft::PROBLEM_EDGE, TemplateDraft::from_text( '{shop_name}: your order is shipped.' )->problem, 'starts with a field' );
		$this->assertSame( TemplateDraft::PROBLEM_EDGE, TemplateDraft::from_text( 'Your order is shipped. {shop_name}' )->problem, 'ends with a field' );
		$this->assertSame( TemplateDraft::PROBLEM_LINK, TemplateDraft::from_text( 'Track it here: {tracking_url} please.' )->problem );
		$this->assertSame( TemplateDraft::PROBLEM_TOO_MANY, TemplateDraft::from_text( 'a {order_number} b {order_total} c {shop_name} d {other} e' )->problem );
		$this->assertSame( TemplateDraft::PROBLEM_EMPTY, TemplateDraft::from_text( '   ' )->problem );
		$this->assertSame( TemplateDraft::PROBLEM_TOO_LONG, TemplateDraft::from_text( str_repeat( 'a', 900 ) )->problem );
		$this->assertNull( TemplateDraft::from_text( 'Plain words without any field.' )->problem );
	}

	// ---- Template list ----

	/**
	 * A good answer replaces the stored list; a failed one keeps it; a disabled endpoint empties it.
	 */
	public function test_template_sync(): void {
		$catalog = new TemplateCatalog();

		$gateway = ( new FakeGateway() )->will_return_for( 'templates', new ApiResult( Outcome::Success, 200, array( $this->api_item(), $this->api_item( array( 'name' => 'Other' ), 'tpl-2' ) ) ) );
		$this->assertTrue( ( new TemplateSync( $gateway, $catalog ) )->refresh( self::NOW ) );
		$this->assertCount( 2, $catalog->all() );
		$this->assertSame( self::NOW, $catalog->fetched_at() );

		$gateway = ( new FakeGateway() )->will_return_for( 'templates', new ApiResult( Outcome::Retryable, 0 ) );
		$this->assertFalse( ( new TemplateSync( $gateway, $catalog ) )->refresh( self::NOW + 10 ) );
		$this->assertCount( 2, $catalog->all() );

		$gateway = ( new FakeGateway() )->will_return_for( 'templates', new ApiResult( Outcome::NotFound, 404 ) );
		$this->assertTrue( ( new TemplateSync( $gateway, $catalog ) )->refresh( self::NOW + 20 ) );
		$this->assertSame( array(), $catalog->all() );
	}

	/**
	 * A fresh list is not fetched again.
	 */
	public function test_refresh_if_stale(): void {
		$catalog = $this->catalog( array( $this->api_item() ) );
		$gateway = new FakeGateway();

		$this->assertFalse( ( new TemplateSync( $gateway, $catalog ) )->refresh_if_stale( self::NOW + 60, 900 ) );
		$this->assertSame( array(), $gateway->calls );

		$gateway->will_return_for( 'templates', new ApiResult( Outcome::Success, 200, array( $this->api_item() ) ) );
		$this->assertTrue( ( new TemplateSync( $gateway, $catalog ) )->refresh_if_stale( self::NOW + 1000, 900 ) );
	}

	// ---- Planning ----

	/**
	 * Everything in order: a Viber plan, with or without the SMS fallback.
	 */
	public function test_planner_builds_a_plan(): void {
		$catalog = $this->catalog( array( $this->api_item() ) );
		$plan    = ViberPlanner::plan( true, true, true, $this->text(), $this->values(), 'Order 1234 from Demo Shop is shipped.', $catalog );

		$this->assertNotNull( $plan );
		$this->assertSame( 'tpl-1', $plan->template_id );
		$this->assertSame(
			array(
				'customField1' => '1234',
				'customField2' => 'Demo Shop',
			),
			$plan->params
		);
		$this->assertSame( 'Order 1234 from Demo Shop is shipped.', $plan->text );
		$this->assertSame( 'Order 1234 from Demo Shop is shipped.', $plan->fallback_sms );

		$without = ViberPlanner::plan( true, true, false, $this->text(), $this->values(), 'Order 1234 from Demo Shop is shipped.', $catalog );
		$this->assertNotNull( $without );
		$this->assertNull( $without->fallback_sms, 'no fallback unless the account has it' );
		$this->assertNull( $without->payload()['fallback_sms'] );

		$long = ViberPlanner::plan( true, true, false, $this->text(), $this->values(), str_repeat( 'a', 300 ), $catalog );
		$this->assertNotNull( $long, 'the one-part limit only matters for the fallback' );
	}

	/**
	 * Any doubt means an ordinary SMS.
	 */
	public function test_planner_falls_back_to_sms_when_unsure(): void {
		$ok   = $this->catalog( array( $this->api_item() ) );
		$sms  = 'Order 1234 from Demo Shop is shipped.';
		$none = fn( bool $enabled, bool $ready, bool $fallback, string $text, array $values, string $filled, TemplateCatalog $catalog ): ?object => ViberPlanner::plan( $enabled, $ready, $fallback, $text, $values, $filled, $catalog );

		$this->assertNull( $none( false, true, true, $this->text(), $this->values(), $sms, $ok ), 'Viber switched off' );
		$this->assertNull( $none( true, false, true, $this->text(), $this->values(), $sms, $ok ), 'Viber not available' );
		$this->assertNull( $none( true, true, true, 'Another message {order_number}.', $this->values(), $sms, $ok ), 'no template with this wording' );
		$this->assertNull( $none( true, true, true, $this->text(), $this->values(), '  ', $ok ), 'empty SMS text' );
		$this->assertNull( $none( true, true, true, $this->text(), $this->values( array( 'order_number' => '' ) ), $sms, $ok ), 'a value is missing' );
		$this->assertNull( $none( true, true, true, $this->text(), $this->values( array( 'order_number' => str_repeat( '1', 126 ) ) ), $sms, $ok ), 'a value is too long' );
		$this->assertNull( $none( true, true, true, $this->text(), $this->values( array( 'shop_name' => 'see https://x.test' ) ), $sms, $ok ), 'a value has a link' );
		$this->assertNull( $none( true, true, true, $this->text(), $this->values(), str_repeat( 'a', 161 ), $ok ), 'SMS text longer than one part' );
		$this->assertNull( $none( true, true, true, $this->text(), $this->values(), str_repeat( 'Я', 71 ), $ok ), 'Cyrillic SMS text longer than one part' );

		$link = $this->catalog(
			array(
				$this->api_item(
					array(
						'content'   => 'Track {customField1} here.',
						'variables' => array( 'customField1' ),
					)
				),
			)
		);
		$this->assertNull( $none( true, true, false, 'Track {tracking_url} here.', array( 'tracking_url' => 'https://x.test/1' ), 'Track https://x.test/1 here.', $link ), 'a link would fill the field' );

		foreach ( array(
			array(
				'approval_status' => 'pending',
				'message_type'    => null,
			),
			array(
				'approval_status' => 'rejected',
				'message_type'    => null,
			),
			array( 'message_type' => 'marketing' ),
		) as $attributes ) {
			$catalog = $this->catalog( array( $this->api_item( $attributes ) ) );
			$this->assertNull( $none( true, true, true, $this->text(), $this->values(), $sms, $catalog ), (string) wp_json_encode( $attributes ) );
		}
	}

	/**
	 * The editor learns why a template is not usable.
	 */
	public function test_problem_reasons(): void {
		$problem = static fn( array $item ): ?string => ViberPlanner::problem( ViberTemplate::from_api( $item ) );

		$this->assertNull( $problem( $this->api_item() ) );
		$this->assertSame(
			ViberPlanner::PROBLEM_NOT_APPROVED,
			$problem(
				$this->api_item(
					array(
						'approval_status' => 'pending',
						'message_type'    => null,
					)
				)
			)
		);
		$this->assertSame( ViberPlanner::PROBLEM_NOT_TRANSACTIONAL, $problem( $this->api_item( array( 'message_type' => 'marketing' ) ) ) );
	}

	// ---- Request ----

	/**
	 * A Viber send carries the template, its values and the SMS fallback text.
	 */
	public function test_client_sends_template_and_fallback(): void {
		$transport = new FakeTransport();
		$client    = new Client( $transport, new Credentials( 'key', 'svc' ), 'https://api.example.test' );
		$body      = array(
			'data' => array(
				'id'         => 'x',
				'attributes' => array(),
			),
		);

		$transport->will_return( new Response( 200, array(), $body ) );
		$transport->will_return( new Response( 200, array(), $body ) );

		$client->send( new SendRequest( '+359888123456', 'Hello Maria', 'viber', 'ref-1', 'ref-1', 'tpl-1', array( 'firstName' => 'Maria' ), 'Order shipped.' ) );
		$client->send( new SendRequest( '+359888123456', 'Hi', 'sms', 'ref-2', 'ref-2' ) );

		$first  = json_decode( (string) $transport->requests[0]['body'], true );
		$second = json_decode( (string) $transport->requests[1]['body'], true );

		$this->assertSame( 'viber', $first['message']['channel'] );
		$this->assertSame( 'tpl-1', $first['message']['template_id'] );
		$this->assertSame( array( 'firstName' => 'Maria' ), $first['message']['params'] );
		$this->assertSame( array( 'sms' => 'Order shipped.' ), $first['message']['fallback'] );

		foreach ( array( 'template_id', 'params', 'fallback' ) as $key ) {
			$this->assertArrayNotHasKey( $key, $second['message'] );
		}
	}

	/**
	 * The templates request asks for every Viber template, so pending ones show in the editor.
	 */
	public function test_client_requests_templates(): void {
		$transport = new FakeTransport();
		$client    = new Client( $transport, new Credentials( 'key', 'svc' ), 'https://api.example.test' );
		$transport->will_return( new Response( 404, array(), array() ) );

		$client->templates();

		$this->assertSame( 'https://api.example.test/public-api/v1/templates?status=all&channel=viber', $transport->requests[0]['url'] );
	}

	// ---- Availability ----

	/**
	 * Viber needs the templates endpoint and no refusal of the fallback; the sender list does not matter.
	 */
	public function test_viber_ready(): void {
		$ready = array(
			'templates' => true,
			'fallback'  => null,
			'balance'   => array( 'channels' => array( 'sms', 'viber' ) ),
		);

		$this->assertTrue( Capabilities::viber_ready( $ready ) );
		$this->assertFalse( Capabilities::viber_ready( array_merge( $ready, array( 'fallback' => false ) ) ) );
		$this->assertTrue( Capabilities::viber_ready( array_merge( $ready, array( 'fallback' => false ) ), false ), 'a refused fallback does not matter when none is asked for' );
		$this->assertFalse( Capabilities::viber_ready( array_merge( $ready, array( 'templates' => false ) ) ) );
		$this->assertFalse( Capabilities::viber_ready( array_merge( $ready, array( 'templates' => null ) ) ) );
		$this->assertTrue( Capabilities::viber_ready( array_merge( $ready, array( 'balance' => array( 'channels' => array( 'sms' ) ) ) ) ), 'shared sender' );
		$this->assertTrue( Capabilities::viber_ready( array( 'templates' => true ) ), 'no account data needed' );
		$this->assertFalse( Capabilities::viber_ready( '' ) );
	}

	/**
	 * Each check records the templates endpoint and forgets an earlier refusal of the fallback.
	 */
	public function test_probe_records_templates_and_resets_the_fallback_flag(): void {
		$gateway = ( new FakeGateway() )
			->will_return_for( 'status', new ApiResult( Outcome::NotFound, 404 ) )
			->will_return_for( 'account', new ApiResult( Outcome::NotFound, 404 ) )
			->will_return_for( 'lookup', new ApiResult( Outcome::NotFound, 404 ) )
			->will_return_for( 'templates', new ApiResult( Outcome::Success, 200 ) );

		$caps = Capabilities::evaluate( $gateway, array( 'fallback' => false ), self::NOW );

		$this->assertTrue( $caps['templates'] );
		$this->assertNull( $caps['fallback'] );

		$gateway = ( new FakeGateway() )
			->will_return_for( 'status', new ApiResult( Outcome::NotFound, 404 ) )
			->will_return_for( 'account', new ApiResult( Outcome::NotFound, 404 ) )
			->will_return_for( 'lookup', new ApiResult( Outcome::NotFound, 404 ) )
			->will_return_for( 'templates', new ApiResult( Outcome::Retryable, 0 ) );

		$this->assertTrue( Capabilities::evaluate( $gateway, array( 'templates' => true ), self::NOW )['templates'] );
	}

	/**
	 * Old texts lose the placeholders that are no longer offered.
	 */
	public function test_trigger_drops_removed_placeholders_from_saved_texts(): void {
		update_option(
			TriggerRepository::OPTION,
			array(
				'processing' => array( 'template' => 'Hello, {first_name}! Order {order_number} {tracking_number} shipped.' ),
				'completed'  => array( 'template' => '{first_name}' ),
			)
		);

		$repository = new TriggerRepository( 'en_US' );

		$this->assertSame( 'Hello! Order {order_number} shipped.', $repository->for_status( 'processing' )->template );
		$this->assertSame( 'Your order #{order_number} from {shop_name} is complete. Thank you!', $repository->for_status( 'completed' )->template, 'an emptied text falls back to the default' );
	}

	/**
	 * The page can say why Viber is missing.
	 */
	public function test_viber_status_explains_why(): void {
		$channels = array( 'balance' => array( 'channels' => array( 'sms', 'viber' ) ) );

		$this->assertSame( Capabilities::VIBER_NOT_CHECKED, Capabilities::viber_status( '' ) );
		$this->assertSame( Capabilities::VIBER_NOT_CHECKED, Capabilities::viber_status( array( 'templates' => null ) ) );
		$this->assertSame( Capabilities::VIBER_TEMPLATES_OFF, Capabilities::viber_status( array( 'templates' => false ) + $channels ) );
		$this->assertSame( Capabilities::VIBER_READY, Capabilities::viber_status( array( 'templates' => true ) ) );
		$this->assertSame(
			Capabilities::VIBER_FALLBACK_REFUSED,
			Capabilities::viber_status(
				array(
					'templates' => true,
					'fallback'  => false,
				) + $channels
			)
		);
		$this->assertSame(
			Capabilities::VIBER_READY,
			Capabilities::viber_status(
				array(
					'templates' => true,
					'fallback'  => null,
				) + $channels
			)
		);
	}

	/**
	 * The webhook self-test result is read from the answer.
	 */
	public function test_webhook_self_test_results(): void {
		$this->assertSame( SelfTest::OK, SelfTest::interpret( 200, array( 'result' => 'test' ) ) );
		$this->assertSame( SelfTest::UNEXPECTED, SelfTest::interpret( 200, array( 'result' => 'updated' ) ) );
		$this->assertSame( SelfTest::NOT_FOUND, SelfTest::interpret( 404, array() ) );
		$this->assertSame( SelfTest::BLOCKED, SelfTest::interpret( 403, array() ) );
		$this->assertSame( SelfTest::UNREACHABLE, SelfTest::interpret( 0, array() ) );
		$this->assertSame( SelfTest::UNEXPECTED, SelfTest::interpret( 500, array() ) );
		$this->assertSame( 'povikvane.self_test', json_decode( SelfTest::body(), true )['event'] );
	}

	// ---- Order event ----

	/**
	 * Build a handler with a Viber planner.
	 *
	 * @param InMemoryStore      $store     Store.
	 * @param RecordingScheduler $scheduler Scheduler.
	 */
	private function handler( InMemoryStore $store, RecordingScheduler $scheduler ): OrderEventHandler {
		$clock   = new FixedClock( self::NOW );
		$state   = new InMemoryState();
		$catalog = $this->catalog( array( $this->api_item() ) );

		return new OrderEventHandler(
			$store,
			new Enqueuer( $store, $scheduler, $clock ),
			new Normalizer(),
			new StormGuard( $store, $state, $clock, 50, 600 ),
			$state,
			new RecordingNotifier(),
			$clock,
			'ab12cd34',
			new Rules(),
			static fn(): bool => true,
			static fn( Trigger $trigger, array $values, string $text ) => ViberPlanner::plan( true, true, true, $trigger->template, $values, $text, $catalog )
		);
	}

	/**
	 * Order facts.
	 */
	private function facts(): OrderFacts {
		return new OrderFacts( 1234, 'completed', self::NOW - 3600, 'checkout', '0888 123 456', '+359', $this->values() );
	}

	/**
	 * A message with the wording of an approved template queues a Viber message.
	 */
	public function test_order_event_queues_a_viber_message(): void {
		$store = new InMemoryStore();
		$this->handler( $store, new RecordingScheduler() )->handle( $this->facts(), new Trigger( 'completed', true, $this->text(), 0 ) );

		$message = $store->find( 1 );
		$this->assertNotNull( $message );
		$this->assertSame( 'viber', $message->channel );
		$this->assertSame( 'Order 1234 from Demo Shop is shipped.', $message->body );
		$this->assertSame(
			array(
				'template_id'  => 'tpl-1',
				'params'       => array(
					'customField1' => '1234',
					'customField2' => 'Demo Shop',
				),
				'fallback_sms' => 'Order 1234 from Demo Shop is shipped.',
			),
			$message->viber
		);
	}

	/**
	 * A message nobody approved as a template queues an SMS.
	 */
	public function test_order_event_queues_sms_otherwise(): void {
		$store = new InMemoryStore();
		$this->handler( $store, new RecordingScheduler() )->handle( $this->facts(), new Trigger( 'completed', true, 'Order {order_number} shipped.', 0 ) );

		$message = $store->find( 1 );
		$this->assertNotNull( $message );
		$this->assertSame( 'sms', $message->channel );
		$this->assertSame( 'Order 1234 shipped.', $message->body );
		$this->assertNull( $message->viber );
	}

	// ---- Sending ----

	/**
	 * Build a dispatcher with one pending Viber message.
	 *
	 * @param FakeGateway   $gateway Gateway.
	 * @param InMemoryStore $store   Store.
	 * @param callable|null $refused Called when the fallback is refused.
	 */
	private function viber_dispatcher( FakeGateway $gateway, InMemoryStore $store, ?callable $refused = null ): Dispatcher {
		$clock   = new FixedClock( self::NOW );
		$message = new Message();

		$message->order_id   = 1234;
		$message->event      = 'completed';
		$message->channel    = 'viber';
		$message->recipient  = '+359888123456';
		$message->body       = 'Hello Maria, order 1234 is shipped.';
		$message->reference  = 'woo:ab12cd34:1234:completed:1';
		$message->created_at = self::NOW;
		$message->updated_at = self::NOW;
		$message->viber      = array(
			'template_id'  => 'tpl-1',
			'params'       => array( 'firstName' => 'Maria' ),
			'fallback_sms' => 'Order 1234 shipped.',
		);
		$store->create( $message );

		return new Dispatcher( $store, $gateway, new RecordingScheduler(), $clock, new RecordingNotifier(), new InMemoryState(), 86400, new \Povikvane\WooCommerce\Support\NullLogger(), null, null, null, $refused );
	}

	/**
	 * The gateway sees the template, values and fallback.
	 */
	public function test_dispatcher_sends_the_viber_request(): void {
		$gateway = ( new FakeGateway() )->will_return(
			new ApiResult(
				Outcome::Success,
				200,
				array(
					'attributes' => array(
						'segments'        => 1,
						'credits-charged' => 1,
					),
				),
				null,
				null,
				null,
				'api-1'
			)
		);
		$store   = new InMemoryStore();

		$this->viber_dispatcher( $gateway, $store )->handle( 1 );

		$request = $gateway->sent[0];
		$this->assertSame( 'viber', $request->channel );
		$this->assertSame( 'tpl-1', $request->template_id );
		$this->assertSame( array( 'firstName' => 'Maria' ), $request->params );
		$this->assertSame( 'Order 1234 shipped.', $request->fallback_sms );
		$this->assertSame( Status::Queued, $store->find( 1 )?->status );
	}

	/**
	 * A refused fallback sends the message as an SMS and tells the plugin to stop offering Viber.
	 */
	public function test_refused_fallback_reroutes_to_sms(): void {
		$told    = 0;
		$gateway = ( new FakeGateway() )
			->will_return( new ApiResult( Outcome::PermanentFailure, 400, array(), 'SMS fallback is not enabled for this account', null, null, null, 'invalid_fallback' ) )
			->will_return(
				new ApiResult(
					Outcome::Success,
					200,
					array(
						'attributes' => array(
							'segments'        => 1,
							'credits-charged' => 1,
						),
					),
					null,
					null,
					null,
					'api-1'
				)
			);
		$store   = new InMemoryStore();
		$counter = static function () use ( &$told ): void {
			++$told;
		};

		$dispatcher = $this->viber_dispatcher( $gateway, $store, $counter );
		$dispatcher->handle( 1 );

		$message = $store->find( 1 );
		$this->assertNotNull( $message );
		$this->assertSame( 1, $told );
		$this->assertSame( Status::Pending, $message->status );
		$this->assertSame( 'sms', $message->channel );
		$this->assertSame( 'Order 1234 shipped.', $message->body );
		$this->assertNull( $message->viber );
		$this->assertSame( 0, $message->attempts );

		$dispatcher->handle( 1 );

		$this->assertSame( 'sms', $gateway->sent[1]->channel );
		$this->assertNull( $gateway->sent[1]->template_id );
		$this->assertNull( $gateway->sent[1]->fallback_sms );
		$this->assertSame( Status::Queued, $store->find( 1 )?->status );
	}

	/**
	 * Any other refusal of a Viber message also becomes an SMS, without changing availability.
	 */
	public function test_other_refusal_reroutes_without_changing_availability(): void {
		$told    = 0;
		$gateway = ( new FakeGateway() )->will_return( new ApiResult( Outcome::PermanentFailure, 400, array(), 'Template not approved' ) );
		$store   = new InMemoryStore();

		$this->viber_dispatcher(
			$gateway,
			$store,
			static function () use ( &$told ): void {
				++$told;
			}
		)->handle( 1 );

		$this->assertSame( 0, $told );
		$this->assertSame( 'sms', $store->find( 1 )?->channel );
		$this->assertSame( Status::Pending, $store->find( 1 )?->status );
	}

	/**
	 * A refused SMS stays failed.
	 */
	public function test_refused_sms_still_fails(): void {
		$gateway = ( new FakeGateway() )->will_return( new ApiResult( Outcome::PermanentFailure, 400, array(), 'Bad request' ) );
		$store   = new InMemoryStore();

		$dispatcher = $this->viber_dispatcher( $gateway, $store );
		$message    = $store->find( 1 );
		$this->assertNotNull( $message );
		$message->channel = 'sms';
		$message->viber   = null;
		$store->save( $message );

		$dispatcher->handle( 1 );

		$this->assertSame( Status::Failed, $store->find( 1 )?->status );
	}

	// ---- Delivery ----

	/**
	 * Build a queued message.
	 *
	 * @param InMemoryStore $store Store.
	 */
	private function queued( InMemoryStore $store ): Message {
		$message                 = new Message();
		$message->order_id       = 1;
		$message->event          = 'completed';
		$message->channel        = 'viber';
		$message->recipient      = '+359888123456';
		$message->body           = 'Hi';
		$message->reference      = 'woo:ab12cd34:1:completed:1';
		$message->status         = Status::Queued;
		$message->api_message_id = 'api-1';
		$message->credits        = 1.0;
		$message->created_at     = self::NOW - 60;
		$message->updated_at     = self::NOW - 60;
		$store->create( $message );

		return $message;
	}

	/**
	 * The webhook records which channel delivered.
	 */
	public function test_webhook_records_the_delivering_channel(): void {
		$store   = new InMemoryStore();
		$clock   = new FixedClock( self::NOW );
		$updater = new DeliveryUpdater( $store, new RecordingNotifier(), $clock );
		$this->queued( $store );

		$result = ( new WebhookHandler( $store, $updater, new InMemoryActivity(), $clock ) )->handle(
			array(
				'event' => 'message.status_updated',
				'data'  => array(
					'id'            => 'api-1',
					'status'        => 'delivered',
					'delivered_via' => 'sms',
				),
			)
		);

		$this->assertSame( WebhookHandler::RESULT_UPDATED, $result );
		$message = $store->find_by_api_message_id( 'api-1' );
		$this->assertNotNull( $message );
		$this->assertSame( 'viber', $message->channel );
		$this->assertSame( 'sms', $message->delivered_via );
	}

	/**
	 * A reported channel the plugin does not know is ignored.
	 */
	public function test_unknown_delivering_channel_is_ignored(): void {
		$store   = new InMemoryStore();
		$updater = new DeliveryUpdater( $store, new RecordingNotifier(), new FixedClock( self::NOW ) );
		$message = $this->queued( $store );

		$updater->delivered( $message, 'carrier-pigeon' );

		$this->assertSame( Status::Delivered, $message->status );
		$this->assertNull( $message->delivered_via );
	}

	/**
	 * The status check records the delivering channel and the settled charge.
	 */
	public function test_reconciler_records_channel_and_settled_credits(): void {
		$store   = new InMemoryStore();
		$clock   = new FixedClock( self::NOW );
		$updater = new DeliveryUpdater( $store, new RecordingNotifier(), $clock );
		$message = $this->queued( $store );

		$message->next_attempt_at = self::NOW - 1;
		$store->save( $message );

		$gateway = ( new FakeGateway() )->will_return_for(
			'status',
			new ApiResult(
				Outcome::Success,
				200,
				array(
					'attributes' => array(
						'status'          => 'delivered_to_handset',
						'delivered_via'   => 'viber',
						'credits-charged' => 0.8,
					),
				)
			)
		);

		( new Reconciler( $store, $gateway, $updater, new InMemoryState(), $clock ) )->run();

		$stored = $store->find( (int) $message->id );
		$this->assertNotNull( $stored );
		$this->assertSame( Status::Delivered, $stored->status );
		$this->assertSame( 'viber', $stored->delivered_via );
		$this->assertSame( 0.8, $stored->credits );
	}

	/**
	 * Old message text and Viber values are erased together.
	 */
	public function test_retention_erases_viber_values(): void {
		$store   = new InMemoryStore();
		$message = $this->queued( $store );

		$message->status = Status::Delivered;
		$message->viber  = array(
			'template_id'  => 'tpl-1',
			'params'       => array( 'firstName' => 'Maria' ),
			'fallback_sms' => 'Order shipped.',
		);
		$store->save( $message );

		$store->purge_bodies( self::NOW );

		$this->assertNull( $store->find( 1 )?->viber );
		$this->assertSame( '', $store->find( 1 )?->body );
	}
}
