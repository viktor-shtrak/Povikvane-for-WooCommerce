<?php
/**
 * Settings page.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Admin;

use Povikvane\WooCommerce\Api\Capabilities;
use Povikvane\WooCommerce\Balance\BalanceStore;
use Povikvane\WooCommerce\Log\Message;
use Povikvane\WooCommerce\Log\Repository;
use Povikvane\WooCommerce\Messages\Enqueuer;
use Povikvane\WooCommerce\Messages\Reference;
use Povikvane\WooCommerce\Messages\Status;
use Povikvane\WooCommerce\Orders\TriggerRepository;
use Povikvane\WooCommerce\Phone\Normalizer;
use Povikvane\WooCommerce\Support\ActionSchedulerScheduler;
use Povikvane\WooCommerce\Support\Countries;
use Povikvane\WooCommerce\Support\Mask;
use Povikvane\WooCommerce\Support\SendingState;
use Povikvane\WooCommerce\Support\SkipLabels;
use Povikvane\WooCommerce\Support\Settings;
use Povikvane\WooCommerce\Support\SiteIdentity;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce > Povikvane SMS: credentials, connection status, a test message
 * and the most recent messages.
 */
final class SettingsPage {

	public const SLUG = 'povikvane-sms';

	private const GROUP      = 'povikvane_settings_group';
	private const CAPABILITY = 'manage_woocommerce';

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
	 * @param Enqueuer          $enqueuer   Queues messages.
	 * @param SendingState      $state      Pause switch.
	 * @param Normalizer        $normalizer Phone normaliser.
	 * @param TriggerRepository $triggers  Trigger configuration.
	 * @param TemplatesSection  $templates Template editor.
	 * @param BalanceStore      $balances  Stored credit balance.
	 * @param callable          $probe      Runs a capability probe.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly Repository $repository,
		private readonly Enqueuer $enqueuer,
		private readonly SendingState $state,
		private readonly Normalizer $normalizer,
		private readonly TriggerRepository $triggers,
		private readonly TemplatesSection $templates,
		private readonly BalanceStore $balances,
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
			__( 'Povikvane SMS', 'povikvane-for-woocommerce' ),
			__( 'Povikvane SMS', 'povikvane-for-woocommerce' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Load the template editor script on this page only.
	 *
	 * @param string $hook_suffix Current admin screen.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( '' !== $this->hook_suffix && $hook_suffix === $this->hook_suffix ) {
			$this->templates->enqueue();

			wp_enqueue_script( 'povikvane-status', plugins_url( 'assets/js/status.js', \Povikvane\WooCommerce\PLUGIN_FILE ), array(), \Povikvane\WooCommerce\VERSION, true );
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
	}

	/**
	 * Sanitise submitted settings and store the API key (encrypted) as a side effect.
	 *
	 * WordPress has already removed slashes from the value at this point.
	 *
	 * @param mixed $input Submitted settings.
	 * @return array<string,mixed>
	 */
	public function sanitize( mixed $input ): array {
		$input   = is_array( $input ) ? $input : array();
		$current = $this->settings->all();
		$changed = false;

		$service_id = isset( $input['service_id'] ) ? sanitize_text_field( (string) $input['service_id'] ) : $current['service_id'];
		$country    = isset( $input['default_country'] ) ? strtoupper( sanitize_text_field( (string) $input['default_country'] ) ) : $current['default_country'];
		$max_age    = isset( $input['max_age_hours'] ) ? (int) $input['max_age_hours'] : $current['max_age_hours'];

		if ( ! array_key_exists( $country, Countries::all() ) ) {
			$country = $current['default_country'];
		}

		if ( $service_id !== $current['service_id'] ) {
			$changed = true;
		}

		if ( ! empty( $input['remove_api_key'] ) ) {
			$this->settings->clear_api_key();
			$changed = true;
		} elseif ( isset( $input['api_key'] ) && '' !== trim( (string) $input['api_key'] ) ) {
			$this->settings->set_api_key( (string) preg_replace( '/\s+/', '', (string) $input['api_key'] ) );
			$changed = true;
		}

		if ( $changed ) {
			// New credentials deserve a fresh start: clear a pause and re-check the connection.
			$this->state->resume();
			as_enqueue_async_action( ActionSchedulerScheduler::PROBE_HOOK, array(), ActionSchedulerScheduler::GROUP );
		}

		return array(
			'service_id'            => $service_id,
			'default_country'       => $country,
			'max_age_hours'         => min( 168, max( 1, $max_age ) ),
			'debug_log'             => ! empty( $input['debug_log'] ),
			'max_order_age_days'    => min( 365, max( 0, (int) ( $input['max_order_age_days'] ?? $current['max_order_age_days'] ) ) ),
			'skip_admin_created'    => ! empty( $input['skip_admin_created'] ),
			'mobile_only'           => ! empty( $input['mobile_only'] ),
			'storm_threshold'       => min( 10000, max( 0, (int) ( $input['storm_threshold'] ?? $current['storm_threshold'] ) ) ),
			'low_balance_threshold' => min( 1000000, max( 0, (int) ( $input['low_balance_threshold'] ?? $current['low_balance_threshold'] ) ) ),
		);
	}

	/**
	 * Render the page.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'povikvane-for-woocommerce' ) );
		}

		$settings = $this->settings->all();
		$caps     = get_option( Capabilities::OPTION, array() );
		$caps     = is_array( $caps ) ? $caps : array();

		echo '<div class="wrap"><h1>' . esc_html__( 'Povikvane SMS', 'povikvane-for-woocommerce' ) . '</h1>';

		Flash::render();

		echo '<p>' . esc_html__( 'Send order SMS notifications through your Povikvane account. A Povikvane account with message credits is required.', 'povikvane-for-woocommerce' ) . ' ';
		printf(
			'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a></p>',
			esc_url( 'https://app.povikvane.com/' ),
			esc_html__( 'Open Povikvane (API key: Settings > API)', 'povikvane-for-woocommerce' )
		);

		$this->render_settings_form( $settings );
		$this->render_connection( $caps );
		$this->render_test_form();
		$this->render_recent();

		echo '</div>';
	}

	/**
	 * Credentials form.
	 *
	 * @param array<string,mixed> $settings Current settings.
	 */
	private function render_settings_form( array $settings ): void {
		$source = $this->settings->api_key_source();
		$name   = Settings::OPTION;

		echo '<h2>' . esc_html__( 'Connection settings', 'povikvane-for-woocommerce' ) . '</h2>';
		echo '<form method="post" action="options.php">';
		settings_fields( self::GROUP );
		echo '<table class="form-table" role="presentation">';

		// API key.
		echo '<tr><th scope="row"><label for="povikvane_api_key">' . esc_html__( 'API key', 'povikvane-for-woocommerce' ) . '</label></th><td>';
		if ( Settings::KEY_SOURCE_CONSTANT === $source ) {
			echo '<em>' . esc_html__( 'Set in wp-config.php (POVIKVANE_API_KEY).', 'povikvane-for-woocommerce' ) . '</em>';
		} else {
			printf(
				'<input type="password" id="povikvane_api_key" name="%1$s[api_key]" value="" class="regular-text" autocomplete="new-password" placeholder="%2$s">',
				esc_attr( $name ),
				esc_attr( Settings::KEY_SOURCE_STORED === $source ? __( 'Saved (leave blank to keep)', 'povikvane-for-woocommerce' ) : '' )
			);
			if ( Settings::KEY_SOURCE_UNREADABLE === $source ) {
				echo '<p class="description">' . esc_html__( 'The saved key can no longer be read (the site security keys changed). Enter it again.', 'povikvane-for-woocommerce' ) . '</p>';
			}
			if ( Settings::KEY_SOURCE_STORED === $source ) {
				printf(
					'<p><label><input type="checkbox" name="%1$s[remove_api_key]" value="1"> %2$s</label></p>',
					esc_attr( $name ),
					esc_html__( 'Remove the saved key', 'povikvane-for-woocommerce' )
				);
			}
		}
		echo '</td></tr>';

		// Service ID.
		printf(
			'<tr><th scope="row"><label for="povikvane_service_id">%1$s</label></th><td><input type="text" id="povikvane_service_id" name="%2$s[service_id]" value="%3$s" class="regular-text" autocomplete="off"></td></tr>',
			esc_html__( 'Service ID', 'povikvane-for-woocommerce' ),
			esc_attr( $name ),
			esc_attr( $settings['service_id'] )
		);

		// Default country.
		echo '<tr><th scope="row"><label for="povikvane_default_country">' . esc_html__( 'Default country', 'povikvane-for-woocommerce' ) . '</label></th><td>';
		printf( '<select id="povikvane_default_country" name="%s[default_country]">', esc_attr( $name ) );
		foreach ( Countries::all() as $iso => $label ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( (string) $iso ), selected( $settings['default_country'], (string) $iso, false ), esc_html( (string) $label ) );
		}
		echo '</select><p class="description">' . esc_html__( 'Used to read phone numbers written without a country code.', 'povikvane-for-woocommerce' ) . '</p></td></tr>';

		// Maximum age.
		printf(
			'<tr><th scope="row"><label for="povikvane_max_age">%1$s</label></th><td><input type="number" id="povikvane_max_age" name="%2$s[max_age_hours]" value="%3$d" min="1" max="168" class="small-text"> %4$s<p class="description">%5$s</p></td></tr>',
			esc_html__( 'Drop waiting messages after', 'povikvane-for-woocommerce' ),
			esc_attr( $name ),
			(int) $settings['max_age_hours'],
			esc_html__( 'hours', 'povikvane-for-woocommerce' ),
			esc_html__( 'A status message that arrives a day late is worse than none, so messages stuck longer than this are not sent.', 'povikvane-for-woocommerce' )
		);

		// Debug logging.
		printf(
			'<tr><th scope="row">%1$s</th><td><label><input type="checkbox" name="%2$s[debug_log]" value="1"%3$s> %4$s</label><p class="description">%5$s <a href="%6$s">%7$s</a></p></td></tr>',
			esc_html__( 'Debug logging', 'povikvane-for-woocommerce' ),
			esc_attr( $name ),
			checked( $settings['debug_log'], true, false ),
			esc_html__( 'Write detailed diagnostics to the WooCommerce log', 'povikvane-for-woocommerce' ),
			esc_html__( 'Never includes the API key or message text; phone numbers are shortened. Warnings and errors are always logged.', 'povikvane-for-woocommerce' ),
			esc_url( admin_url( 'admin.php?page=wc-status&tab=logs' ) ),
			esc_html__( 'View logs', 'povikvane-for-woocommerce' )
		);

		echo '</table>';

		$this->render_safety( $settings );
		$this->templates->render();

		submit_button();
		echo '</form>';
	}

	/**
	 * Safety options.
	 *
	 * @param array<string,mixed> $settings Current settings.
	 */
	private function render_safety( array $settings ): void {
		$name = Settings::OPTION;

		echo '<h2>' . esc_html__( 'Safety', 'povikvane-for-woocommerce' ) . '</h2><table class="form-table" role="presentation">';

		printf(
			'<tr><th scope="row"><label for="povikvane_order_age">%1$s</label></th><td><input type="number" id="povikvane_order_age" name="%2$s[max_order_age_days]" value="%3$d" min="0" max="365" class="small-text"> %4$s<p class="description">%5$s</p></td></tr>',
			esc_html__( 'Do not message orders older than', 'povikvane-for-woocommerce' ),
			esc_attr( $name ),
			(int) $settings['max_order_age_days'],
			esc_html__( 'days', 'povikvane-for-woocommerce' ),
			esc_html__( 'Protects against messages for old orders after imports or bulk changes. 0 means no limit.', 'povikvane-for-woocommerce' )
		);

		printf(
			'<tr><th scope="row">%1$s</th><td><label><input type="checkbox" name="%2$s[mobile_only]" value="1"%3$s> %4$s</label><p class="description">%5$s</p></td></tr>',
			esc_html__( 'Landline numbers', 'povikvane-for-woocommerce' ),
			esc_attr( $name ),
			checked( $settings['mobile_only'], true, false ),
			esc_html__( 'Skip numbers that are not mobile (checked for Bulgaria)', 'povikvane-for-woocommerce' ),
			esc_html__( 'A message to a landline is charged but cannot be delivered.', 'povikvane-for-woocommerce' )
		);

		printf(
			'<tr><th scope="row">%1$s</th><td><label><input type="checkbox" name="%2$s[skip_admin_created]" value="1"%3$s> %4$s</label></td></tr>',
			esc_html__( 'Orders created in the admin', 'povikvane-for-woocommerce' ),
			esc_attr( $name ),
			checked( $settings['skip_admin_created'], true, false ),
			esc_html__( 'Do not send automatic messages for orders created by hand', 'povikvane-for-woocommerce' )
		);

		printf(
			'<tr><th scope="row"><label for="povikvane_storm">%1$s</label></th><td><input type="number" id="povikvane_storm" name="%2$s[storm_threshold]" value="%3$d" min="0" max="10000" class="small-text"> %4$s<p class="description">%5$s</p></td></tr>',
			esc_html__( 'Pause when more than', 'povikvane-for-woocommerce' ),
			esc_attr( $name ),
			(int) $settings['storm_threshold'],
			esc_html__( 'messages are created within 10 minutes', 'povikvane-for-woocommerce' ),
			esc_html__( 'Stops accidental mass messaging, for example after changing many orders at once. The messages are kept for you to send or discard. 0 turns this off.', 'povikvane-for-woocommerce' )
		);

		printf(
			'<tr><th scope="row"><label for="povikvane_low_balance">%1$s</label></th><td><input type="number" id="povikvane_low_balance" name="%2$s[low_balance_threshold]" value="%3$d" min="0" class="small-text"> %4$s<p class="description">%5$s</p></td></tr>',
			esc_html__( 'Warn me when credits fall below', 'povikvane-for-woocommerce' ),
			esc_attr( $name ),
			(int) $settings['low_balance_threshold'],
			esc_html__( 'credits', 'povikvane-for-woocommerce' ),
			esc_html__( 'Shows a notice in the admin and sends the site administrator one email a day while credits stay below this level. 0 turns the warning off.', 'povikvane-for-woocommerce' )
		);

		echo '</table>';
	}

	/**
	 * Connection status.
	 *
	 * @param array<string,mixed> $caps Stored capabilities.
	 */
	private function render_connection( array $caps ): void {
		$connection = (string) ( $caps['connection'] ?? 'none' );
		$balance    = $this->balances->get();
		$checked    = isset( $caps['checked_at'] ) ? (int) $caps['checked_at'] : 0;
		$paused     = $this->state->paused_reason();

		echo '<h2>' . esc_html__( 'Status', 'povikvane-for-woocommerce' ) . '</h2><table class="widefat striped" style="max-width:640px"><tbody>';

		$this->row( __( 'Connection', 'povikvane-for-woocommerce' ), $this->connection_label( $connection ) );

		if ( true === ( $caps['account'] ?? null ) ) {
			printf(
				'<tr><th scope="row" style="width:40%%">%1$s</th><td><span id="povikvane-balance" data-refresh="1" data-age="%2$d" data-estimated="%3$d">%4$s</span></td></tr>',
				esc_html__( 'Credits available', 'povikvane-for-woocommerce' ),
				(int) ( null === $balance ? -1 : max( 0, time() - $balance->fetched_at ) ),
				null !== $balance && $balance->estimated ? 1 : 0,
				esc_html( BalanceView::text( $balance ) )
			);
		} elseif ( Capabilities::CONNECTION_OK === $connection ) {
			$this->row( __( 'Credits available', 'povikvane-for-woocommerce' ), __( 'Not available from the service yet', 'povikvane-for-woocommerce' ) );
		}

		if ( Capabilities::CONNECTION_OK === $connection ) {
			$this->row( __( 'Balance endpoint', 'povikvane-for-woocommerce' ), $this->capability_label( $caps['account'] ?? null ) );
			$this->row( __( 'Lookup by reference', 'povikvane-for-woocommerce' ), $this->capability_label( $caps['lookup'] ?? null ) );
		}

		$this->row( __( 'Sending', 'povikvane-for-woocommerce' ), null === $paused ? __( 'Active', 'povikvane-for-woocommerce' ) : __( 'Paused', 'povikvane-for-woocommerce' ) );
		$this->row( __( 'Last checked', 'povikvane-for-woocommerce' ), $checked > 0 ? wp_date( 'Y-m-d H:i', $checked ) : __( 'Never', 'povikvane-for-woocommerce' ) );

		echo '</tbody></table>';

		echo '<p>';
		$this->action_form( 'povikvane_check_connection', __( 'Check connection', 'povikvane-for-woocommerce' ) );
		if ( in_array( $paused, array( SendingState::REASON_AUTH, SendingState::REASON_CREDITS ), true ) ) {
			$this->action_form( 'povikvane_resume', __( 'Resume sending', 'povikvane-for-woocommerce' ) );
		}
		echo '</p>';
	}

	/**
	 * Test message form.
	 */
	private function render_test_form(): void {
		echo '<h2>' . esc_html__( 'Send a test message', 'povikvane-for-woocommerce' ) . '</h2>';
		echo '<p>' . esc_html__( 'Sends one real SMS and uses message credits.', 'povikvane-for-woocommerce' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="povikvane_send_test">';
		wp_nonce_field( 'povikvane_send_test' );
		printf(
			'<input type="text" name="test_phone" class="regular-text" placeholder="%s" required> ',
			esc_attr__( 'Phone number, e.g. 0888 123 456', 'povikvane-for-woocommerce' )
		);
		submit_button( __( 'Send test message', 'povikvane-for-woocommerce' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * Recent messages.
	 */
	private function render_recent(): void {
		$rows = $this->repository->recent( 10 );

		echo '<h2>' . esc_html__( 'Recent messages', 'povikvane-for-woocommerce' ) . '</h2>';

		if ( array() === $rows ) {
			echo '<p>' . esc_html__( 'No messages yet.', 'povikvane-for-woocommerce' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Time', 'povikvane-for-woocommerce' ), __( 'Event', 'povikvane-for-woocommerce' ), __( 'Recipient', 'povikvane-for-woocommerce' ), __( 'Status', 'povikvane-for-woocommerce' ), __( 'Credits', 'povikvane-for-woocommerce' ), __( 'Detail', 'povikvane-for-woocommerce' ) ) as $heading ) {
			echo '<th>' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			printf(
				'<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td><td>%4$s</td><td>%5$s</td><td>%6$s</td></tr>',
				esc_html( wp_date( 'Y-m-d H:i', $row->created_at ) ),
				esc_html( $row->event ),
				esc_html( Mask::phone( $row->recipient ) ),
				esc_html( $this->status_label( $row->status ) ),
				esc_html( null === $row->credits ? '' : number_format_i18n( $row->credits, 1 ) ),
				esc_html( null !== $row->skip_reason ? SkipLabels::text( $row->skip_reason ) : (string) $row->error_detail )
			);
		}

		echo '</tbody></table>';
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

		$phone = isset( $_POST['test_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['test_phone'] ) ) : '';

		if ( ! $this->settings->credentials()->is_complete() ) {
			Flash::add( 'error', __( 'Enter and save your API key and service ID first.', 'povikvane-for-woocommerce' ) );
			$this->redirect();
		}

		$number = $this->normalizer->normalize( $phone, Countries::calling_code( $this->settings->default_country() ) );
		if ( ! $number->is_valid() ) {
			Flash::add( 'error', __( 'That phone number does not look valid.', 'povikvane-for-woocommerce' ) );
			$this->redirect();
		}

		$message            = new Message();
		$message->event     = 'test';
		$message->recipient = (string) $number->e164;
		$message->reference = Reference::for_test( SiteIdentity::token(), time() );
		$message->body      = sprintf(
			/* translators: %s: shop name. */
			__( 'Test message from %s, sent with Povikvane.', 'povikvane-for-woocommerce' ),
			wp_html_excerpt( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), 30 )
		);

		if ( null === $this->enqueuer->enqueue( $message ) ) {
			Flash::add( 'error', __( 'A test message was just sent. Wait a second and try again.', 'povikvane-for-woocommerce' ) );
		} else {
			Flash::add( 'success', __( 'Test message queued. It appears in the list below within a minute.', 'povikvane-for-woocommerce' ) );
		}

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
	 * Settings page URL.
	 */
	private function page_url(): string {
		return admin_url( 'admin.php?page=' . self::SLUG );
	}

	/**
	 * Redirect back to the settings page.
	 */
	private function redirect(): never {
		wp_safe_redirect( $this->page_url() );
		exit;
	}

	/**
	 * One table row.
	 *
	 * @param string $label Label.
	 * @param string $value Value.
	 */
	private function row( string $label, string $value ): void {
		printf( '<tr><th scope="row" style="width:40%%">%1$s</th><td>%2$s</td></tr>', esc_html( $label ), esc_html( $value ) );
	}

	/**
	 * A small POST form with a nonce and one button.
	 *
	 * @param string $action Admin-post action and nonce action.
	 * @param string $label  Button label.
	 */
	private function action_form( string $action, string $label ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:8px">';
		printf( '<input type="hidden" name="action" value="%s">', esc_attr( $action ) );
		wp_nonce_field( $action );
		submit_button( $label, 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * Human label for a connection state.
	 *
	 * @param string $connection Connection state.
	 */
	private function connection_label( string $connection ): string {
		return match ( $connection ) {
			Capabilities::CONNECTION_OK          => __( 'Connected', 'povikvane-for-woocommerce' ),
			Capabilities::CONNECTION_AUTH        => __( 'API key or service ID rejected', 'povikvane-for-woocommerce' ),
			Capabilities::CONNECTION_UNREACHABLE => __( 'The service could not be reached', 'povikvane-for-woocommerce' ),
			Capabilities::CONNECTION_CONFIG      => __( 'The service rejected the request; check the service ID', 'povikvane-for-woocommerce' ),
			default                              => __( 'Not checked yet', 'povikvane-for-woocommerce' ),
		};
	}

	/**
	 * Human label for an optional endpoint.
	 *
	 * @param mixed $available True, false, or null when unknown.
	 */
	private function capability_label( mixed $available ): string {
		if ( true === $available ) {
			return __( 'Available', 'povikvane-for-woocommerce' );
		}

		return false === $available
			? __( 'Not enabled on the service yet', 'povikvane-for-woocommerce' )
			: __( 'Unknown', 'povikvane-for-woocommerce' );
	}

	/**
	 * Human label for a status.
	 *
	 * @param Status $status Status.
	 */
	private function status_label( Status $status ): string {
		return match ( $status ) {
			Status::Pending     => __( 'Waiting', 'povikvane-for-woocommerce' ),
			Status::Sending     => __( 'Sending', 'povikvane-for-woocommerce' ),
			Status::Queued      => __( 'Accepted', 'povikvane-for-woocommerce' ),
			Status::Delivered   => __( 'Delivered', 'povikvane-for-woocommerce' ),
			Status::Failed      => __( 'Failed', 'povikvane-for-woocommerce' ),
			Status::Unconfirmed => __( 'Unconfirmed', 'povikvane-for-woocommerce' ),
			Status::Skipped     => __( 'Skipped', 'povikvane-for-woocommerce' ),
			Status::HeldCredits => __( 'On hold (no credits)', 'povikvane-for-woocommerce' ),
			Status::Expired     => __( 'Expired', 'povikvane-for-woocommerce' ),
		};
	}
}
