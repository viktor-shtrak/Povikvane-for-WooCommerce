<?php
/**
 * Plugin bootstrap.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce;

defined( 'ABSPATH' ) || exit;

use Povikvane\WooCommerce\Admin\BalanceAjax;
use Povikvane\WooCommerce\Admin\BalanceNotice;
use Povikvane\WooCommerce\Admin\ConnectionPanel;
use Povikvane\WooCommerce\Admin\DeliveryPanel;
use Povikvane\WooCommerce\Admin\MarketingSection;
use Povikvane\WooCommerce\Admin\MessageLogPage;
use Povikvane\WooCommerce\Admin\MessageStatusAjax;
use Povikvane\WooCommerce\Admin\OrderMetabox;
use Povikvane\WooCommerce\Admin\OverviewPanel;
use Povikvane\WooCommerce\Admin\PauseControls;
use Povikvane\WooCommerce\Admin\SafetyPanel;
use Povikvane\WooCommerce\Admin\SettingsPage;
use Povikvane\WooCommerce\Admin\SetupNotice;
use Povikvane\WooCommerce\Admin\SetupWizard;
use Povikvane\WooCommerce\Admin\TemplatesSection;
use Povikvane\WooCommerce\Admin\TestSender;
use Povikvane\WooCommerce\Api\Capabilities;
use Povikvane\WooCommerce\Api\Client;
use Povikvane\WooCommerce\Api\WpTransport;
use Povikvane\WooCommerce\Balance\BalanceRefresher;
use Povikvane\WooCommerce\Balance\BalanceTracker;
use Povikvane\WooCommerce\Balance\LowBalanceAlert;
use Povikvane\WooCommerce\Balance\OptionBalanceStore;
use Povikvane\WooCommerce\Balance\WpMailer;
use Povikvane\WooCommerce\Log\Message;
use Povikvane\WooCommerce\Log\Repository;
use Povikvane\WooCommerce\Log\Retention;
use Povikvane\WooCommerce\Log\Schema;
use Povikvane\WooCommerce\Marketing\AccountConsent;
use Povikvane\WooCommerce\Marketing\CheckoutConsent;
use Povikvane\WooCommerce\Marketing\Consent;
use Povikvane\WooCommerce\Marketing\ConsentRecorder;
use Povikvane\WooCommerce\Marketing\ConsentRepository;
use Povikvane\WooCommerce\Marketing\FollowupHandler;
use Povikvane\WooCommerce\Marketing\FrequencyCap;
use Povikvane\WooCommerce\Marketing\MarketingGate;
use Povikvane\WooCommerce\Marketing\MarketingSettings;
use Povikvane\WooCommerce\Marketing\OptOutEndpoint;
use Povikvane\WooCommerce\Marketing\OptOutToken;
use Povikvane\WooCommerce\Messages\CompositeGate;
use Povikvane\WooCommerce\Messages\DeliveryUpdater;
use Povikvane\WooCommerce\Messages\Dispatcher;
use Povikvane\WooCommerce\Messages\Enqueuer;
use Povikvane\WooCommerce\Messages\Reconciler;
use Povikvane\WooCommerce\Messages\StuckRecovery;
use Povikvane\WooCommerce\Orders\OrderContext;
use Povikvane\WooCommerce\Orders\OrderEventHandler;
use Povikvane\WooCommerce\Orders\OrderFacts;
use Povikvane\WooCommerce\Orders\OrderStatusGate;
use Povikvane\WooCommerce\Orders\StatusHooks;
use Povikvane\WooCommerce\Orders\Trigger;
use Povikvane\WooCommerce\Orders\TriggerRepository;
use Povikvane\WooCommerce\Phone\Normalizer;
use Povikvane\WooCommerce\Privacy\Privacy;
use Povikvane\WooCommerce\Safety\SiteGuard;
use Povikvane\WooCommerce\Safety\StormGuard;
use Povikvane\WooCommerce\Support\ActionSchedulerScheduler;
use Povikvane\WooCommerce\Setup\ActivationRedirect;
use Povikvane\WooCommerce\Setup\SetupProgress;
use Povikvane\WooCommerce\Setup\SetupState;
use Povikvane\WooCommerce\Support\Crypto;
use Povikvane\WooCommerce\Support\Language;
use Povikvane\WooCommerce\Support\Logger;
use Povikvane\WooCommerce\Support\OptionSendingState;
use Povikvane\WooCommerce\Support\SendingState;
use Povikvane\WooCommerce\Support\SiteIdentity;
use Povikvane\WooCommerce\Support\Settings;
use Povikvane\WooCommerce\Support\SettingsSanitizer;
use Povikvane\WooCommerce\Support\SystemClock;
use Povikvane\WooCommerce\Support\WcLogger;
use Povikvane\WooCommerce\Support\WpNotifier;
use Povikvane\WooCommerce\Webhook\OptionActivity;
use Povikvane\WooCommerce\Webhook\RestController;
use Povikvane\WooCommerce\Webhook\Token;
use Povikvane\WooCommerce\Viber\TemplateCatalog;
use Povikvane\WooCommerce\Viber\TemplateSync;
use Povikvane\WooCommerce\Viber\ViberPlan;
use Povikvane\WooCommerce\Viber\ViberPlanner;
use Povikvane\WooCommerce\Webhook\WebhookHandler;

/**
 * Wires the plugin together once WooCommerce is available and compatible.
 */
final class Plugin {

	/**
	 * Single instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Settings, built on first use.
	 *
	 * @var Settings|null
	 */
	private ?Settings $settings = null;

	/**
	 * Message store, built on first use.
	 *
	 * @var Repository|null
	 */
	private ?Repository $repository = null;

	/**
	 * Pause switch, built on first use.
	 *
	 * @var OptionSendingState|null
	 */
	private ?OptionSendingState $state = null;

	/**
	 * Get the single instance.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Prevent direct construction.
	 */
	private function __construct() {}

	/**
	 * Register hooks if the environment is supported.
	 */
	public function boot(): void {
		Language::register();

		if ( ! $this->woocommerce_is_compatible() ) {
			add_action( 'admin_notices', array( $this, 'render_incompatible_notice' ) );
			return;
		}

		Schema::maybe_upgrade();

		( new SiteGuard( $this->state() ) )->check();

		add_action( ActionSchedulerScheduler::SEND_HOOK, array( $this, 'run_send_job' ) );
		add_action( ActionSchedulerScheduler::PROBE_HOOK, array( $this, 'run_probe' ) );
		add_action( ActionSchedulerScheduler::BALANCE_HOOK, array( $this, 'run_balance_refresh' ) );
		add_action( ActionSchedulerScheduler::RECONCILE_HOOK, array( $this, 'run_reconcile' ) );
		add_action( ActionSchedulerScheduler::PURGE_HOOK, array( $this, 'run_purge' ) );

		( new RestController(
			new WebhookHandler( $this->repository(), $this->delivery_updater(), new OptionActivity(), new SystemClock() ),
			new Token()
		) )->register();
		add_action( 'action_scheduler_init', array( $this, 'schedule_recurring_jobs' ) );

		( new StatusHooks(
			$this->order_handler(),
			$this->triggers(),
			new OrderContext( $this->settings()->default_country() ),
			$this->marketing()->enabled() ? $this->followup_handler() : null
		) )->register();

		$this->register_marketing_hooks();

		( new Privacy(
			$this->repository(),
			new ConsentRepository(),
			$this->consent(),
			new Normalizer(),
			$this->settings()->default_country()
		) )->register();

		if ( is_admin() ) {
			$templates = new TemplatesSection( $this->triggers() );
			$delivery  = new DeliveryPanel( new Token(), new OptionActivity(), $this->repository() );
			$delivery->register();

			$marketing_section = new MarketingSection( $this->marketing(), new ConsentRepository(), Language::content_locale() );
			$marketing_section->register();

			( new MessageLogPage( $this->repository(), $this->enqueuer(), $this->settings(), $this->state() ) )->register();

			$activity   = new OptionActivity();
			$progress   = new SetupProgress( $this->triggers(), $this->repository(), $activity );
			$sanitizer  = new SettingsSanitizer( $this->settings(), $this->state() );
			$tester     = new TestSender( $this->settings(), new Normalizer(), $this->enqueuer() );
			$setup_flag = new SetupState();

			( new SettingsPage(
				$this->settings(),
				$this->repository(),
				$this->state(),
				$this->triggers(),
				$templates,
				new OptionBalanceStore(),
				$marketing_section,
				new OverviewPanel(),
				new ConnectionPanel( $this->settings(), $delivery ),
				new SafetyPanel(),
				$activity,
				$tester,
				$sanitizer,
				$progress,
				array( $this, 'run_probe' )
			) )->register();

			( new SetupWizard(
				$this->settings(),
				$sanitizer,
				$this->triggers(),
				$this->repository(),
				$tester,
				$setup_flag,
				$progress,
				new Token(),
				$activity,
				$templates,
				array( $this, 'run_probe' )
			) )->register();

			( new SetupNotice( $setup_flag, $progress ) )->register();
			( new ActivationRedirect( $setup_flag ) )->register();

			( new BalanceNotice( new OptionBalanceStore(), $this->settings(), $this->state() ) )->register();

			( new BalanceAjax( new OptionBalanceStore(), fn() => $this->balance_refresher()->refresh() ) )->register();

			( new MessageStatusAjax(
				$this->repository(),
				fn( Message $message ): bool => ( new Reconciler( $this->repository(), $this->client(), $this->delivery_updater(), $this->state(), new SystemClock(), $this->logger() ) )->check_now( $message )
			) )->register();

			( new PauseControls( $this->state(), new SiteGuard( $this->state() ), $this->repository() ) )->register();

			( new OrderMetabox(
				$this->settings(),
				$this->repository(),
				$this->enqueuer(),
				new Normalizer(),
				new OrderContext( $this->settings()->default_country() ),
				$this->state()
			) )->register();
		}

		/**
		 * Fires when the plugin has finished loading and WooCommerce is compatible.
		 */
		do_action( 'povikvane_loaded' );
	}

	/**
	 * Action Scheduler job: send one message.
	 *
	 * @param mixed $message_id Row ID.
	 */
	public function run_send_job( mixed $message_id ): void {
		// Missing credentials behave like rejected ones: pause and keep messages waiting.
		if ( ! $this->settings()->credentials()->is_complete() && null === $this->state()->paused_reason() ) {
			$this->state()->pause( SendingState::REASON_AUTH );
		}

		( new Dispatcher(
			$this->repository(),
			$this->client(),
			new ActionSchedulerScheduler(),
			new SystemClock(),
			new WpNotifier(),
			$this->state(),
			$this->settings()->max_age_seconds(),
			$this->logger(),
			new CompositeGate( new OrderStatusGate(), $this->marketing_gate() ),
			new BalanceTracker( new OptionBalanceStore(), new ActionSchedulerScheduler() ),
			$this->marketing()->quiet_hours( wp_timezone() ),
			static function (): void {
				$caps = get_option( Capabilities::OPTION, array() );
				$caps = is_array( $caps ) ? $caps : array();

				update_option( Capabilities::OPTION, array_merge( $caps, array( 'fallback' => false ) ), false );
			}
		) )->handle( (int) $message_id );
	}

	/**
	 * Action Scheduler job and settings action: check credentials and optional endpoints.
	 */
	public function run_probe(): void {
		$credentials = $this->settings()->credentials();
		$previous    = get_option( Capabilities::OPTION, array() );
		$previous    = is_array( $previous ) ? $previous : array();

		if ( ! $credentials->is_complete() ) {
			update_option(
				Capabilities::OPTION,
				array_merge(
					$previous,
					array(
						'connection' => Capabilities::CONNECTION_UNKNOWN,
						'checked_at' => time(),
					)
				),
				false
			);
			return;
		}

		$caps = Capabilities::evaluate( $this->client(), $previous, time() );
		update_option( Capabilities::OPTION, $caps, false );

		$this->logger()->log(
			Logger::INFO,
			'Capability check',
			array(
				'connection' => $caps['connection'],
				'account'    => $caps['account'],
				'lookup'     => $caps['lookup'],
				'viber'      => Capabilities::viber_status( $caps ),
				'paused'     => $this->state()->paused_reason(),
			)
		);

		$paused = $this->state()->paused_reason();

		if ( Capabilities::CONNECTION_AUTH === $caps['connection'] ) {
			$this->state()->pause( SendingState::REASON_AUTH );
		} elseif ( SendingState::REASON_AUTH === $paused && Capabilities::CONNECTION_OK === $caps['connection'] ) {
			$this->state()->resume();
		}

		// The probe already fetched the balance; store it without a second call.
		if ( true === $caps['account'] && is_array( $caps['balance'] ) ) {
			$this->balance_refresher()->apply( $caps['balance'] );
		}

		if ( Capabilities::CONNECTION_OK === $caps['connection'] ) {
			$this->template_sync()->refresh( time() );
		}
	}

	/**
	 * Action Scheduler job: recover interrupted sends and check delivery results.
	 */
	public function run_reconcile(): void {
		if ( ! $this->settings()->credentials()->is_complete() || SendingState::REASON_AUTH === $this->state()->paused_reason() ) {
			return;
		}

		$clock = new SystemClock();

		( new StuckRecovery( $this->repository(), $this->client(), new ActionSchedulerScheduler(), new WpNotifier(), $clock, $this->logger() ) )->run();

		( new Reconciler( $this->repository(), $this->client(), $this->delivery_updater(), $this->state(), $clock, $this->logger() ) )->run();
	}

	/**
	 * Action Scheduler job: erase old message text and delete old records.
	 */
	public function run_purge(): void {
		$result = ( new Retention( $this->repository(), new SystemClock(), $this->settings()->retention_body_days(), $this->settings()->retention_row_days() ) )->run();

		$this->logger()->log( Logger::INFO, 'Retention run', $result );
	}

	/**
	 * Action Scheduler job: refresh the credit balance.
	 */
	public function run_balance_refresh(): void {
		$caps = get_option( Capabilities::OPTION, array() );

		if ( ! $this->settings()->credentials()->is_complete() || ! is_array( $caps ) || true !== ( $caps['account'] ?? null ) ) {
			return;
		}

		$this->balance_refresher()->refresh();

		// Templates are approved within minutes, so the list is kept fresh alongside the balance.
		if ( Capabilities::viber_ready( $caps ) ) {
			$this->template_sync()->refresh_if_stale( time(), 15 * MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Keeps the local list of Viber templates current.
	 */
	private function template_sync(): TemplateSync {
		return new TemplateSync( $this->client(), new TemplateCatalog() );
	}

	/**
	 * Keep a daily capability probe and a regular balance refresh scheduled.
	 */
	public function schedule_recurring_jobs(): void {
		if ( false === as_has_scheduled_action( ActionSchedulerScheduler::PROBE_HOOK, array(), ActionSchedulerScheduler::GROUP ) ) {
			as_schedule_recurring_action( time() + 120, DAY_IN_SECONDS, ActionSchedulerScheduler::PROBE_HOOK, array(), ActionSchedulerScheduler::GROUP );
		}

		if ( false === as_has_scheduled_action( ActionSchedulerScheduler::RECONCILE_HOOK, array(), ActionSchedulerScheduler::GROUP ) ) {
			as_schedule_recurring_action( time() + 120, 5 * MINUTE_IN_SECONDS, ActionSchedulerScheduler::RECONCILE_HOOK, array(), ActionSchedulerScheduler::GROUP );
		}

		if ( false === as_has_scheduled_action( ActionSchedulerScheduler::PURGE_HOOK, array(), ActionSchedulerScheduler::GROUP ) ) {
			as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, ActionSchedulerScheduler::PURGE_HOOK, array(), ActionSchedulerScheduler::GROUP );
		}

		if ( false === as_has_scheduled_action( ActionSchedulerScheduler::BALANCE_HOOK, array(), ActionSchedulerScheduler::GROUP ) ) {
			as_schedule_recurring_action( time() + 300, 15 * MINUTE_IN_SECONDS, ActionSchedulerScheduler::BALANCE_HOOK, array(), ActionSchedulerScheduler::GROUP );
		}
	}

	/**
	 * Tell administrators why the plugin is inactive.
	 */
	public function render_incompatible_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: minimum required WooCommerce version. */
					__( 'Povikvane SMS for WooCommerce requires WooCommerce %s or newer and has not been loaded.', 'povikvane-for-woocommerce' ),
					MIN_WC_VERSION
				)
			)
		);
	}

	/**
	 * Whether the active WooCommerce meets the minimum version.
	 */
	private function woocommerce_is_compatible(): bool {
		return defined( 'WC_VERSION' ) && version_compare( WC_VERSION, MIN_WC_VERSION, '>=' );
	}

	/**
	 * Settings.
	 */
	private function settings(): Settings {
		if ( null === $this->settings ) {
			$this->settings = new Settings( new Crypto( wp_salt( 'auth' ) ) );
		}

		return $this->settings;
	}

	/**
	 * Message store.
	 */
	private function repository(): Repository {
		if ( null === $this->repository ) {
			$this->repository = new Repository();
		}

		return $this->repository;
	}

	/**
	 * Pause switch.
	 */
	private function state(): OptionSendingState {
		if ( null === $this->state ) {
			$this->state = new OptionSendingState();
		}

		return $this->state;
	}

	/**
	 * Diagnostic logger (WooCommerce > Status > Logs, source "povikvane").
	 */
	private function logger(): Logger {
		return new WcLogger( $this->settings() );
	}

	/**
	 * Records delivery results and adds the order notes.
	 */
	private function delivery_updater(): DeliveryUpdater {
		return new DeliveryUpdater( $this->repository(), new WpNotifier(), new SystemClock(), $this->logger() );
	}

	/**
	 * Refreshes the credit balance and raises the low-balance alert.
	 */
	private function balance_refresher(): BalanceRefresher {
		$store = new OptionBalanceStore();
		$clock = new SystemClock();

		return new BalanceRefresher(
			$this->client(),
			$store,
			$this->state(),
			$clock,
			new LowBalanceAlert( $store, new WpMailer(), $clock, $this->settings()->low_balance_threshold() ),
			$this->logger()
		);
	}

	/**
	 * Settings for promotional messages.
	 */
	public function marketing(): MarketingSettings {
		return new MarketingSettings( Language::content_locale() );
	}

	/**
	 * Secret for hashing phone numbers and signing unsubscribe links. It belongs to
	 * this installation and does not change when the WordPress salts do.
	 */
	private function consent_secret(): string {
		return hash_hmac( 'sha256', 'povikvane-consent', SiteIdentity::salt() );
	}

	/**
	 * Consent rules.
	 */
	private function consent(): Consent {
		return new Consent( new ConsentRepository(), new SystemClock(), $this->consent_secret() );
	}

	/**
	 * Last-moment checks for promotional messages.
	 */
	private function marketing_gate(): MarketingGate {
		$marketing = $this->marketing();

		return new MarketingGate(
			$marketing->enabled(),
			$this->consent(),
			new FrequencyCap( $this->repository(), $marketing->cap_max(), $marketing->cap_days() ),
			new SystemClock()
		);
	}

	/**
	 * Schedules review requests after completed orders.
	 */
	private function followup_handler(): FollowupHandler {
		$clock = new SystemClock();

		return new FollowupHandler(
			$this->repository(),
			$this->enqueuer(),
			new Normalizer(),
			$this->consent(),
			new OptOutToken( $this->consent_secret() ),
			new StormGuard( $this->repository(), $this->state(), $clock, $this->settings()->storm_threshold() ),
			$this->state(),
			new WpNotifier(),
			$clock,
			SiteIdentity::token(),
			$this->settings()->rules(),
			$this->marketing()->followup(),
			home_url()
		);
	}

	/**
	 * The unsubscribe link always works; the checkbox and account setting only exist while promotions are on.
	 */
	private function register_marketing_hooks(): void {
		( new OptOutEndpoint( $this->consent(), new OptOutToken( $this->consent_secret() ) ) )->register();

		if ( ! $this->marketing()->enabled() ) {
			return;
		}

		$recorder = new ConsentRecorder( $this->consent(), new Normalizer(), $this->marketing(), $this->settings()->default_country() );

		( new CheckoutConsent( $recorder, $this->marketing() ) )->register();
		( new AccountConsent( $this->consent(), new Normalizer(), $this->marketing(), $this->settings()->default_country() ) )->register();
	}

	/**
	 * Trigger configuration.
	 */
	private function triggers(): TriggerRepository {
		return new TriggerRepository( Language::content_locale() );
	}

	/**
	 * The handler that turns order status changes into queued messages.
	 */
	private function order_handler(): OrderEventHandler {
		$clock = new SystemClock();

		return new OrderEventHandler(
			$this->repository(),
			$this->enqueuer(),
			new Normalizer(),
			new StormGuard( $this->repository(), $this->state(), $clock, $this->settings()->storm_threshold() ),
			$this->state(),
			new WpNotifier(),
			$clock,
			SiteIdentity::token(),
			$this->settings()->rules(),
			/**
			 * Filters whether an automatic message may be sent for an order event.
			 *
			 * @param bool   $allow    Whether to send. Default true.
			 * @param int    $order_id Order ID.
			 * @param string $status   New order status slug without "wc-".
			 */
			static fn( OrderFacts $facts ): bool => (bool) apply_filters( 'povikvane_should_send', true, $facts->order_id, $facts->status ),
			static fn( Trigger $trigger, array $values, string $sms_text ): ?ViberPlan => ViberPlanner::plan(
				$trigger->channel,
				$trigger->viber_template,
				$values,
				$sms_text,
				Capabilities::viber_ready( get_option( Capabilities::OPTION, array() ) ),
				new TemplateCatalog(),
				$trigger->viber_map
			)
		);
	}

	/**
	 * Enqueuer.
	 */
	private function enqueuer(): Enqueuer {
		return new Enqueuer( $this->repository(), new ActionSchedulerScheduler(), new SystemClock() );
	}

	/**
	 * API client with the current credentials.
	 */
	private function client(): Client {
		return new Client( new WpTransport(), $this->settings()->credentials(), $this->settings()->base_url(), $this->logger() );
	}
}
