<?php
/**
 * Plugin bootstrap.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce;

use Povikvane\WooCommerce\Admin\SettingsPage;
use Povikvane\WooCommerce\Api\Capabilities;
use Povikvane\WooCommerce\Api\Client;
use Povikvane\WooCommerce\Api\WpTransport;
use Povikvane\WooCommerce\Log\Repository;
use Povikvane\WooCommerce\Log\Schema;
use Povikvane\WooCommerce\Messages\Dispatcher;
use Povikvane\WooCommerce\Messages\Enqueuer;
use Povikvane\WooCommerce\Phone\Normalizer;
use Povikvane\WooCommerce\Support\ActionSchedulerScheduler;
use Povikvane\WooCommerce\Support\Crypto;
use Povikvane\WooCommerce\Support\Logger;
use Povikvane\WooCommerce\Support\OptionSendingState;
use Povikvane\WooCommerce\Support\SendingState;
use Povikvane\WooCommerce\Support\Settings;
use Povikvane\WooCommerce\Support\SystemClock;
use Povikvane\WooCommerce\Support\WcLogger;
use Povikvane\WooCommerce\Support\WpNotifier;

defined( 'ABSPATH' ) || exit;

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
		if ( ! $this->woocommerce_is_compatible() ) {
			add_action( 'admin_notices', array( $this, 'render_incompatible_notice' ) );
			return;
		}

		Schema::maybe_upgrade();

		add_action( ActionSchedulerScheduler::SEND_HOOK, array( $this, 'run_send_job' ) );
		add_action( ActionSchedulerScheduler::PROBE_HOOK, array( $this, 'run_probe' ) );
		add_action( 'action_scheduler_init', array( $this, 'schedule_recurring_jobs' ) );

		if ( is_admin() ) {
			( new SettingsPage(
				$this->settings(),
				$this->repository(),
				$this->enqueuer(),
				$this->state(),
				new Normalizer(),
				array( $this, 'run_probe' )
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
			$this->logger()
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
				'paused'     => $this->state()->paused_reason(),
			)
		);

		$paused = $this->state()->paused_reason();

		if ( Capabilities::CONNECTION_AUTH === $caps['connection'] ) {
			$this->state()->pause( SendingState::REASON_AUTH );
		} elseif ( SendingState::REASON_AUTH === $paused && Capabilities::CONNECTION_OK === $caps['connection'] ) {
			$this->state()->resume();
		} elseif ( SendingState::REASON_CREDITS === $paused && (float) ( $caps['balance']['credits-available'] ?? 0 ) > 0 ) {
			$this->state()->resume();
		}
	}

	/**
	 * Keep a daily capability probe scheduled.
	 */
	public function schedule_recurring_jobs(): void {
		if ( false === as_has_scheduled_action( ActionSchedulerScheduler::PROBE_HOOK, array(), ActionSchedulerScheduler::GROUP ) ) {
			as_schedule_recurring_action( time() + 120, DAY_IN_SECONDS, ActionSchedulerScheduler::PROBE_HOOK, array(), ActionSchedulerScheduler::GROUP );
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
