<?php
/**
 * Settings screen.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Admin;

use Povikvane\WooCommerce\Api\Capabilities;
use Povikvane\WooCommerce\Balance\BalanceStore;
use Povikvane\WooCommerce\Log\Repository;
use Povikvane\WooCommerce\Marketing\MarketingSettings;
use Povikvane\WooCommerce\Orders\TriggerRepository;
use Povikvane\WooCommerce\Setup\SetupProgress;
use Povikvane\WooCommerce\Support\ActionSchedulerScheduler;
use Povikvane\WooCommerce\Support\SettingsSanitizer;
use Povikvane\WooCommerce\Support\SendingState;
use Povikvane\WooCommerce\Support\Settings;
use Povikvane\WooCommerce\Webhook\WebhookActivity;

use const Povikvane\WooCommerce\PLUGIN_FILE;
use const Povikvane\WooCommerce\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce > Povikvane - Viber & SMS: one page with tabs (Overview, Connection,
 * Messages, Promotions, Safety and data). Every tab lives in the same form, so
 * saving on one tab can never discard settings from another.
 */
final class SettingsPage {

	public const SLUG = 'povikvane-sms';

	private const GROUP      = 'povikvane_settings_group';
	private const CAPABILITY = 'manage_woocommerce';

	/**
	 * Tab IDs, in order.
	 */
	private const TABS = array( 'overview', 'connection', 'messages', 'promotions', 'safety' );

	/**
	 * Runs a capability probe now.
	 *
	 * @var callable
	 */
	private $probe;

	/**
	 * Admin screen hook suffix, set when the menu is added.
	 *
	 * @var string
	 */
	private string $hook_suffix = '';

	/**
	 * Constructor.
	 *
	 * @param Settings          $settings   Settings.
	 * @param Repository        $repository Message log.
	 * @param SendingState      $state      Pause switch.
	 * @param TriggerRepository $triggers   Trigger configuration.
	 * @param TemplatesSection  $templates  Template editor.
	 * @param BalanceStore      $balances   Stored credit balance.
	 * @param MarketingSection  $marketing  Promotional settings.
	 * @param OverviewPanel     $overview   Overview tab.
	 * @param ConnectionPanel   $connection Connection tab.
	 * @param SafetyPanel       $safety     Safety and data tab.
	 * @param WebhookActivity   $activity   Last delivery report.
	 * @param TestSender        $tester     Sends test messages.
	 * @param SettingsSanitizer $sanitizer  Validates submitted settings.
	 * @param SetupProgress     $progress   Setup checklist.
	 * @param callable          $probe      Runs a capability probe.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly Repository $repository,
		private readonly SendingState $state,
		private readonly TriggerRepository $triggers,
		private readonly TemplatesSection $templates,
		private readonly BalanceStore $balances,
		private readonly MarketingSection $marketing,
		private readonly OverviewPanel $overview,
		private readonly ConnectionPanel $connection,
		private readonly SafetyPanel $safety,
		private readonly WebhookActivity $activity,
		private readonly TestSender $tester,
		private readonly SettingsSanitizer $sanitizer,
		private readonly SetupProgress $progress,
		callable $probe
	) {
		$this->probe = $probe;
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		// options.php asks for manage_options by default; shop managers use this page too.
		add_filter( 'option_page_capability_' . self::GROUP, static fn(): string => self::CAPABILITY );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_povikvane_check_connection', array( $this, 'handle_check_connection' ) );
		add_action( 'admin_post_povikvane_send_test', array( $this, 'handle_send_test' ) );
	}

	/**
	 * Add the page under the WooCommerce menu.
	 */
	public function add_menu(): void {
		$this->hook_suffix = (string) add_submenu_page(
			'woocommerce',
			__( 'Povikvane - Viber & SMS', 'povikvane-for-woocommerce' ),
			__( 'Povikvane - Viber & SMS', 'povikvane-for-woocommerce' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Load styles and scripts on this page only.
	 *
	 * @param string $hook_suffix Current admin screen.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( '' === $this->hook_suffix || $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		Assets::base();
		Assets::confirm();
		MessageStatusAjax::enqueue();
		$this->templates->enqueue();

		wp_enqueue_script( 'povikvane-tabs', plugins_url( 'assets/js/tabs.js', PLUGIN_FILE ), array(), VERSION, true );
		wp_enqueue_script( 'povikvane-status', plugins_url( 'assets/js/status.js', PLUGIN_FILE ), array(), VERSION, true );
		wp_localize_script(
			'povikvane-status',
			'povikvaneStatus',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( BalanceAjax::NONCE ),
				'maxAge'  => 300,
			)
		);
	}

	/**
	 * Register the settings options.
	 */
	public function register_settings(): void {
		register_setting(
			self::GROUP,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => array(),
				'show_in_rest'      => false,
			)
		);

		register_setting(
			self::GROUP,
			TriggerRepository::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this->triggers, 'sanitize' ),
				'default'           => array(),
				'show_in_rest'      => false,
			)
		);

		register_setting(
			self::GROUP,
			MarketingSettings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this->marketing, 'sanitize' ),
				'default'           => array(),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Sanitise submitted settings (the rules live in SettingsSanitizer).
	 *
	 * @param mixed $input Submitted settings.
	 * @return array<string,mixed>
	 */
	public function sanitize( mixed $input ): array {
		return $this->sanitizer->sanitize( $input );
	}

	/**
	 * Render the page.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'povikvane-for-woocommerce' ) );
		}

		$values = $this->settings->all();
		$caps   = get_option( Capabilities::OPTION, array() );
		$caps   = is_array( $caps ) ? $caps : array();
		$tab    = $this->requested_tab();
		$data   = $this->overview_data( $caps );

		Layout::open( '', 'data-pvk-tabs data-pvk-initial="' . esc_attr( $tab ) . '"' );

		Layout::tabs(
			array(
				array(
					'id'    => 'overview',
					'label' => __( 'Overview', 'povikvane-for-woocommerce' ),
				),
				array(
					'id'    => 'connection',
					'label' => __( 'Connection', 'povikvane-for-woocommerce' ),
					'dot'   => Capabilities::CONNECTION_OK !== $data->connection,
				),
				array(
					'id'    => 'messages',
					'label' => __( 'Messages', 'povikvane-for-woocommerce' ),
				),
				array(
					'id'    => 'promotions',
					'label' => __( 'Promotions', 'povikvane-for-woocommerce' ),
				),
				array(
					'id'    => 'safety',
					'label' => __( 'Safety & data', 'povikvane-for-woocommerce' ),
				),
				array(
					'id'    => 'log',
					'label' => __( 'Message log', 'povikvane-for-woocommerce' ),
					'url'   => $data->log_url,
				),
			),
			$tab
		);

		echo '<form method="post" action="options.php" data-pvk-form>';
		settings_fields( self::GROUP );

		$this->panel_open( 'overview', true );
		$this->overview->render( $data );
		echo '</div>';

		$this->panel_open( 'connection' );
		$this->connection->render( $values );
		echo '</div>';

		$this->panel_open( 'messages' );
		$this->templates->render();
		echo '</div>';

		$this->panel_open( 'promotions' );
		$this->marketing->render();
		echo '</div>';

		$this->panel_open( 'safety' );
		$this->safety->render( $values );
		echo '</div>';

		printf(
			'<div class="pvk-savebar" data-pvk-savebar><button type="submit" class="pvk-btn pvk-btn--primary">%1$s</button><span class="pvk-savebar__status" data-dirty="%2$s">%3$s</span></div>',
			esc_html__( 'Save changes', 'povikvane-for-woocommerce' ),
			esc_attr__( 'You have unsaved changes', 'povikvane-for-woocommerce' ),
			esc_html__( 'All changes saved', 'povikvane-for-woocommerce' )
		);

		echo '</form>';

		Layout::close();
	}

	/**
	 * Open a tab panel.
	 *
	 * @param string $id      Tab ID.
	 * @param bool   $no_save Whether the panel has nothing to save (the save bar is hidden for it).
	 */
	private function panel_open( string $id, bool $no_save = false ): void {
		printf(
			'<div class="pvk-panel" id="pvk-panel-%1$s" role="tabpanel" aria-labelledby="pvk-tab-%1$s"%2$s>',
			esc_attr( $id ),
			$no_save ? ' data-pvk-nosave="1"' : ''
		);
	}

	/**
	 * The tab to show first.
	 */
	private function requested_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only choice of which tab to show.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

		return in_array( $tab, self::TABS, true ) ? $tab : 'overview';
	}

	/**
	 * Gather what the Overview tab shows.
	 *
	 * @param array<string,mixed> $caps Stored capabilities.
	 */
	private function overview_data( array $caps ): OverviewData {
		$connection = (string) ( $caps['connection'] ?? 'none' );

		$last_report = $this->activity->last();
		$overdue     = $this->repository->oldest_overdue( time() );

		return new OverviewData(
			$connection,
			isset( $caps['checked_at'] ) ? (int) $caps['checked_at'] : 0,
			true === ( $caps['account'] ?? null ),
			$this->balances->get(),
			$this->settings->low_balance_threshold(),
			$this->state->paused_reason(),
			$last_report,
			null === $overdue ? 0 : time() - $overdue,
			$this->repository->recent( 5 ),
			$this->progress->checklist(),
			admin_url( 'admin.php?page=' . SetupWizard::SLUG ),
			admin_url( 'admin.php?page=' . MessageLogPage::SLUG )
		);
	}

	/**
	 * Check the connection now.
	 */
	public function handle_check_connection(): void {
		check_admin_referer( 'povikvane_check_connection' );
		$this->require_capability();

		( $this->probe )();

		$caps       = get_option( Capabilities::OPTION, array() );
		$connection = is_array( $caps ) ? (string) ( $caps['connection'] ?? 'unknown' ) : 'unknown';

		Flash::add( Capabilities::CONNECTION_OK === $connection ? 'success' : 'error', $this->connection_label( $connection ) );
		$this->redirect();
	}

	/**
	 * Queue a test message.
	 */
	public function handle_send_test(): void {
		check_admin_referer( 'povikvane_send_test' );
		$this->require_capability();

		$phone  = isset( $_POST['test_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['test_phone'] ) ) : '';
		$result = $this->tester->send( $phone );

		Flash::add( $result['ok'] ? 'success' : 'error', $result['message'] );
		$this->redirect();
	}

	/**
	 * Stop unless the user may manage the shop.
	 */
	private function require_capability(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'povikvane-for-woocommerce' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Redirect back to the settings page.
	 */
	private function redirect(): never {
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}

	/**
	 * Human label for a connection state.
	 *
	 * @param string $connection Connection state.
	 */
	private function connection_label( string $connection ): string {
		return match ( $connection ) {
			Capabilities::CONNECTION_OK          => __( 'Connected.', 'povikvane-for-woocommerce' ),
			Capabilities::CONNECTION_AUTH        => __( 'The API key or service ID was rejected.', 'povikvane-for-woocommerce' ),
			Capabilities::CONNECTION_UNREACHABLE => __( 'The service could not be reached.', 'povikvane-for-woocommerce' ),
			Capabilities::CONNECTION_CONFIG      => __( 'The service rejected the request; check the service ID.', 'povikvane-for-woocommerce' ),
			default                              => __( 'Not checked yet.', 'povikvane-for-woocommerce' ),
		};
	}
}
