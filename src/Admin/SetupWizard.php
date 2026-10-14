<?php
/**
 * The setup wizard.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Admin;

use Povikvane\WooCommerce\Api\Capabilities;
use Povikvane\WooCommerce\Log\Repository;
use Povikvane\WooCommerce\Messages\Placeholders;
use Povikvane\WooCommerce\Messages\Status;
use Povikvane\WooCommerce\Orders\TriggerRepository;
use Povikvane\WooCommerce\Setup\SetupChecklist;
use Povikvane\WooCommerce\Setup\SetupProgress;
use Povikvane\WooCommerce\Setup\SetupState;
use Povikvane\WooCommerce\Setup\WizardSteps;
use Povikvane\WooCommerce\Support\Countries;
use Povikvane\WooCommerce\Support\Dates;
use Povikvane\WooCommerce\Support\Settings;
use Povikvane\WooCommerce\Support\SettingsSanitizer;
use Povikvane\WooCommerce\Webhook\Token;
use Povikvane\WooCommerce\Webhook\WebhookActivity;

use const Povikvane\WooCommerce\PLUGIN_FILE;
use const Povikvane\WooCommerce\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * A guided first-time setup: connect, choose messages, send a test, add the webhook.
 * Each step saves with an ordinary form post and moves on, so it works without
 * JavaScript; the script only watches the test message arrive.
 */
final class SetupWizard {

	public const SLUG = 'povikvane-sms-setup';

	private const CAPABILITY = 'manage_woocommerce';
	private const NONCE      = 'povikvane_wizard';

	/**
	 * The statuses offered in the wizard; the full list is on the Messages tab.
	 */
	private const CORE_STATUSES = array( 'processing', 'completed', 'on-hold', 'cancelled', 'refunded', 'failed' );

	/**
	 * Runs a capability probe now.
	 *
	 * @var callable
	 */
	private $probe;

	/**
	 * Admin screen hook suffix.
	 *
	 * @var string
	 */
	private string $hook_suffix = '';

	/**
	 * Constructor.
	 *
	 * @param Settings          $settings   Settings.
	 * @param SettingsSanitizer $sanitizer  Validates settings.
	 * @param TriggerRepository $triggers   Trigger configuration.
	 * @param Repository        $repository Message log.
	 * @param TestSender        $tester     Sends test messages.
	 * @param SetupState        $state      Setup flags.
	 * @param SetupProgress     $progress   Setup checklist.
	 * @param Token             $token      Webhook secret.
	 * @param WebhookActivity   $activity   Last delivery report.
	 * @param TemplatesSection  $templates  Provides the message length estimate script.
	 * @param callable          $probe      Runs a capability probe.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly SettingsSanitizer $sanitizer,
		private readonly TriggerRepository $triggers,
		private readonly Repository $repository,
		private readonly TestSender $tester,
		private readonly SetupState $state,
		private readonly SetupProgress $progress,
		private readonly Token $token,
		private readonly WebhookActivity $activity,
		private readonly TemplatesSection $templates,
		callable $probe
	) {
		$this->probe = $probe;
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_head', array( $this, 'hide_menu_entry' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_povikvane_wizard_connect', array( $this, 'handle_connect' ) );
		add_action( 'admin_post_povikvane_wizard_messages', array( $this, 'handle_messages' ) );
		add_action( 'admin_post_povikvane_wizard_test', array( $this, 'handle_test' ) );
		add_action( 'admin_post_povikvane_wizard_finish', array( $this, 'handle_finish' ) );
		add_action( 'wp_ajax_povikvane_wizard_status', array( $this, 'ajax_status' ) );
	}

	/**
	 * Register the page. It has no menu entry of its own: people arrive from the
	 * activation redirect, the reminder or a button.
	 */
	public function add_menu(): void {
		$this->hook_suffix = (string) add_submenu_page(
			'woocommerce',
			__( 'Povikvane SMS setup', 'povikvane-for-woocommerce' ),
			__( 'Povikvane SMS setup', 'povikvane-for-woocommerce' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Hide the menu entry after WordPress has checked access to the page.
	 */
	public function hide_menu_entry(): void {
		remove_submenu_page( 'woocommerce', self::SLUG );
	}

	/**
	 * Load styles and scripts on this page only.
	 *
	 * @param string $hook_suffix Current admin screen.
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( '' === $this->hook_suffix || $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		Assets::base();
		$this->templates->enqueue();

		wp_enqueue_script( 'povikvane-status', plugins_url( 'assets/js/status.js', PLUGIN_FILE ), array(), VERSION, true );
		wp_enqueue_script( 'povikvane-wizard', plugins_url( 'assets/js/wizard.js', PLUGIN_FILE ), array(), VERSION, true );
		wp_localize_script(
			'povikvane-wizard',
			'povikvaneWizard',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( self::NONCE ),
				'messageId' => $this->sent_id(),
				'i18n'      => array(
					'waiting' => __( 'Waiting for the result…', 'povikvane-for-woocommerce' ),
					'stopped' => __( 'Still waiting. You can carry on; the result will appear in the message log.', 'povikvane-for-woocommerce' ),
				),
			)
		);
	}

	/**
	 * Render the current step.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'povikvane-for-woocommerce' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only choice of which step to show.
		$step = WizardSteps::normalize( isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : '' );

		Layout::open( 'pvk-wizard' );
		$this->stepper( $step );

		echo '<div class="pvk-step">';

		match ( $step ) {
			WizardSteps::CONNECT  => $this->step_connect(),
			WizardSteps::MESSAGES => $this->step_messages(),
			WizardSteps::TEST     => $this->step_test(),
			WizardSteps::REPORTS  => $this->step_reports(),
			WizardSteps::DONE     => $this->step_done(),
			default               => $this->step_welcome(),
		};

		echo '</div>';

		Layout::close();
	}

	// ---- Steps ----

	/**
	 * Step 1: welcome.
	 */
	private function step_welcome(): void {
		Ui::card_open();

		echo '<h2 class="pvk-step__title">' . esc_html__( 'Let\'s get your order messages going', 'povikvane-for-woocommerce' ) . '</h2>';
		echo '<p class="pvk-step__lead">' . esc_html__( 'In a few minutes your customers can get a text message when their order is completed. This takes about five minutes.', 'povikvane-for-woocommerce' ) . '</p>';

		echo '<h3 style="margin-top:20px;font-weight:600">' . esc_html__( 'What you will need', 'povikvane-for-woocommerce' ) . '</h3><ul class="pvk-need">';
		echo '<li><span>' . esc_html__( 'A Povikvane account with message credits.', 'povikvane-for-woocommerce' ) . ' <a href="' . esc_url( 'https://app.povikvane.com/' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open Povikvane', 'povikvane-for-woocommerce' ) . '</a></span></li>';
		echo '<li><span>' . esc_html__( 'Your API key and service ID, found in Povikvane under Settings > API.', 'povikvane-for-woocommerce' ) . '</span></li>';
		echo '<li><span>' . esc_html__( 'Your own phone, to receive a test message.', 'povikvane-for-woocommerce' ) . '</span></li>';
		echo '</ul>';

		Ui::card_close();

		printf(
			'<div class="pvk-step__nav"><a href="%1$s">%2$s</a><a class="pvk-btn pvk-btn--primary" href="%3$s">%4$s</a></div>',
			esc_url( admin_url( 'admin.php?page=' . SettingsPage::SLUG ) ),
			esc_html__( 'Skip setup', 'povikvane-for-woocommerce' ),
			esc_url( $this->step_url( WizardSteps::CONNECT ) ),
			esc_html__( 'Start', 'povikvane-for-woocommerce' )
		);
	}

	/**
	 * Step 2: connect.
	 */
	private function step_connect(): void {
		$source    = $this->settings->api_key_source();
		$caps      = get_option( Capabilities::OPTION, array() );
		$connected = is_array( $caps ) && Capabilities::CONNECTION_OK === ( $caps['connection'] ?? '' );
		$form      = 'pvk-wizard-connect';

		Ui::card_open();

		echo '<h2 class="pvk-step__title">' . esc_html__( 'Connect your Povikvane account', 'povikvane-for-woocommerce' ) . '</h2>';
		echo '<p class="pvk-step__lead">' . esc_html__( 'Paste your API key and service ID. The key is stored encrypted on this site.', 'povikvane-for-woocommerce' ) . '</p>';

		if ( $connected ) {
			echo '<div class="pvk-result">';
			Ui::note( __( 'Already connected. You can continue, or enter new details below to change them.', 'povikvane-for-woocommerce' ), 'ok' );
			echo '</div>';
		}

		echo '<form method="post" id="' . esc_attr( $form ) . '" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="povikvane_wizard_connect">';
		wp_nonce_field( 'povikvane_wizard_connect' );

		if ( Settings::KEY_SOURCE_CONSTANT === $source ) {
			Ui::field_open( __( 'API key', 'povikvane-for-woocommerce' ) );
			echo '<p>' . Ui::pill( __( 'Set in wp-config.php', 'povikvane-for-woocommerce' ), 'info' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by Ui::pill().
			Ui::field_close();
		} else {
			Ui::field_open( __( 'API key', 'povikvane-for-woocommerce' ), 'pvk-api-key' );
			printf(
				'<input type="password" id="pvk-api-key" name="api_key" autocomplete="new-password" placeholder="%1$s"%2$s>',
				esc_attr( Settings::KEY_SOURCE_STORED === $source ? __( 'Saved. Type a new key to replace it.', 'povikvane-for-woocommerce' ) : '' ),
				Settings::KEY_SOURCE_STORED === $source ? '' : ' required'
			);
			Ui::field_close();
		}

		Ui::field_open( __( 'Service ID', 'povikvane-for-woocommerce' ), 'pvk-service-id' );
		printf( '<input type="text" id="pvk-service-id" name="service_id" class="pvk-mono" autocomplete="off" value="%s" required>', esc_attr( $this->settings->service_id() ) );
		Ui::field_close();

		Ui::field_open( __( 'Your country', 'povikvane-for-woocommerce' ), 'pvk-country', __( 'Used to read phone numbers written without a country code.', 'povikvane-for-woocommerce' ) );
		echo '<select id="pvk-country" name="default_country">';
		foreach ( Countries::all() as $iso => $label ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( (string) $iso ), selected( $this->settings->default_country(), (string) $iso, false ), esc_html( (string) $label ) );
		}
		echo '</select>';
		Ui::field_close();

		echo '</form>';

		Ui::card_close();

		printf(
			'<div class="pvk-step__nav"><a href="%1$s">%2$s</a><span class="pvk-actions">%3$s<button type="submit" form="%4$s" class="pvk-btn pvk-btn--primary">%5$s</button></span></div>',
			esc_url( $this->step_url( WizardSteps::WELCOME ) ),
			esc_html__( 'Back', 'povikvane-for-woocommerce' ),
			$connected ? '<a class="pvk-btn" href="' . esc_url( $this->step_url( WizardSteps::MESSAGES ) ) . '">' . esc_html__( 'Continue', 'povikvane-for-woocommerce' ) . '</a>' : '', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped on this line.
			esc_attr( $form ),
			esc_html__( 'Save and test connection', 'povikvane-for-woocommerce' )
		);
	}

	/**
	 * Step 3: choose messages.
	 */
	private function step_messages(): void {
		$form = 'pvk-wizard-messages';

		echo '<form method="post" id="' . esc_attr( $form ) . '" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="povikvane_wizard_messages">';
		wp_nonce_field( 'povikvane_wizard_messages' );

		echo '<section class="pvk-card"><div class="pvk-card__head"><h2 class="pvk-step__title">' . esc_html__( 'Choose your messages', 'povikvane-for-woocommerce' ) . '</h2><p class="pvk-step__lead">' . esc_html__( 'Switch on the order updates your customers should receive, and adjust the wording if you like. You can change all of this later.', 'povikvane-for-woocommerce' ) . '</p><p class="pvk-card__intro" style="margin-top:8px">' . esc_html__( 'You can use:', 'povikvane-for-woocommerce' ) . ' ';
		foreach ( Placeholders::NAMES as $name ) {
			if ( ! in_array( $name, array( 'optout_link', 'tracking_url', 'tracking_number' ), true ) ) {
				echo '<code>{' . esc_html( $name ) . '}</code> ';
			}
		}
		echo '</p></div><div style="margin-top:12px">';

		$all = $this->triggers->statuses();

		foreach ( self::CORE_STATUSES as $slug ) {
			if ( ! isset( $all[ $slug ] ) ) {
				continue;
			}

			$trigger = $this->triggers->for_status( $slug );
			$name    = TriggerRepository::OPTION . '[' . $slug . ']';

			echo '<div class="pvk-trigger' . ( $trigger->enabled ? ' is-on' : '' ) . '"><div>';
			Ui::toggle( $name . '[enabled]', $trigger->enabled, $all[ $slug ] );
			echo '</div><div>';
			printf(
				'<label class="screen-reader-text" for="pvk-text-%1$s">%2$s</label><textarea id="pvk-text-%1$s" name="%3$s[template]" rows="3" maxlength="1000" data-povikvane-template>%4$s</textarea><div class="pvk-estimate povikvane-estimate" aria-live="polite"></div>',
				esc_attr( $slug ),
				esc_html( $all[ $slug ] ),
				esc_attr( $name ),
				esc_textarea( $trigger->template )
			);
			echo '</div></div>';
		}

		echo '</div></section></form>';

		printf(
			'<div class="pvk-step__nav"><a href="%1$s">%2$s</a><button type="submit" form="%3$s" class="pvk-btn pvk-btn--primary">%4$s</button></div>',
			esc_url( $this->step_url( WizardSteps::CONNECT ) ),
			esc_html__( 'Back', 'povikvane-for-woocommerce' ),
			esc_attr( $form ),
			esc_html__( 'Save and continue', 'povikvane-for-woocommerce' )
		);
	}

	/**
	 * Step 4: send a test message and watch it arrive.
	 */
	private function step_test(): void {
		$sent = $this->sent_id();
		$form = 'pvk-wizard-test';

		Ui::card_open();

		echo '<h2 class="pvk-step__title">' . esc_html__( 'Send yourself a test message', 'povikvane-for-woocommerce' ) . '</h2>';
		echo '<p class="pvk-step__lead">' . esc_html__( 'This sends one real SMS and uses message credits. Use your own number.', 'povikvane-for-woocommerce' ) . '</p>';

		echo '<form method="post" id="' . esc_attr( $form ) . '" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:16px"><input type="hidden" name="action" value="povikvane_wizard_test">';
		wp_nonce_field( 'povikvane_wizard_test' );
		printf(
			'<div class="pvk-inline"><label class="screen-reader-text" for="pvk-test-phone">%1$s</label><input type="tel" id="pvk-test-phone" name="test_phone" autocomplete="tel" placeholder="%2$s" required> <button type="submit" class="pvk-btn pvk-btn--primary">%3$s</button></div>',
			esc_html__( 'Your phone number', 'povikvane-for-woocommerce' ),
			esc_attr__( 'Your phone, e.g. 0888 123 456', 'povikvane-for-woocommerce' ),
			esc_html( null === $sent ? __( 'Send test message', 'povikvane-for-woocommerce' ) : __( 'Send another', 'povikvane-for-woocommerce' ) )
		);
		echo '</form>';

		if ( null !== $sent ) {
			$message = $this->repository->find( $sent );

			if ( null !== $message && 'test' === $message->event ) {
				$finished = $message->status->is_final() || Status::Unconfirmed === $message->status;

				echo '<div class="pvk-result" id="pvk-test-result" data-message-id="' . (int) $sent . '" data-final="' . ( $finished ? '1' : '0' ) . '"><div class="pvk-status-line">';
				echo $finished ? '' : '<span class="pvk-spinner" aria-hidden="true"></span>';
				echo '<span id="pvk-test-status">' . esc_html( $finished ? StatusView::label( $message->status ) : __( 'Waiting for the result…', 'povikvane-for-woocommerce' ) ) . '</span><span id="pvk-test-pill">' . StatusView::pill( $message->status ) . '</span></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by StatusView.
				echo '<div class="pvk-result"><div class="pvk-note" role="note"><div>' . esc_html__( 'Did it arrive? If nothing shows up within a minute, check the number, your credits and the Message log.', 'povikvane-for-woocommerce' ) . '</div></div></div>';
			}
		}

		Ui::card_close();

		printf(
			'<div class="pvk-step__nav"><a href="%1$s">%2$s</a><span class="pvk-actions"><a href="%3$s">%4$s</a><a class="pvk-btn pvk-btn--primary" href="%5$s">%6$s</a></span></div>',
			esc_url( $this->step_url( WizardSteps::MESSAGES ) ),
			esc_html__( 'Back', 'povikvane-for-woocommerce' ),
			esc_url( $this->step_url( WizardSteps::REPORTS ) ),
			esc_html__( 'Skip', 'povikvane-for-woocommerce' ),
			esc_url( $this->step_url( WizardSteps::REPORTS ) ),
			esc_html__( 'Continue', 'povikvane-for-woocommerce' )
		);
	}

	/**
	 * Step 5: delivery reports.
	 */
	private function step_reports(): void {
		$last = $this->activity->last();

		Ui::card_open();

		echo '<h2 class="pvk-step__title">' . esc_html__( 'See deliveries as they happen', 'povikvane-for-woocommerce' ) . '</h2>';
		echo '<p class="pvk-step__lead">' . esc_html__( 'Optional. Povikvane can tell this shop the moment a message is delivered. Without it the plugin still finds out by checking regularly, only a little later.', 'povikvane-for-woocommerce' ) . '</p>';

		echo '<ol style="margin:16px 0 0 18px;padding:0"><li>' . esc_html__( 'Open Povikvane and go to Settings > API.', 'povikvane-for-woocommerce' ) . '</li><li>' . esc_html__( 'Paste this address into the Webhook URL box and save.', 'povikvane-for-woocommerce' ) . '</li></ol>';

		printf(
			'<div class="pvk-inline" style="margin-top:12px"><input type="text" id="povikvane-webhook-url" class="pvk-mono" readonly value="%1$s" aria-label="%2$s"> <button type="button" class="pvk-btn" data-povikvane-copy="#povikvane-webhook-url" data-copied="%3$s">%4$s</button></div>',
			esc_attr( $this->token->url() ),
			esc_attr__( 'Webhook address', 'povikvane-for-woocommerce' ),
			esc_attr__( 'Copied', 'povikvane-for-woocommerce' ),
			esc_html__( 'Copy', 'povikvane-for-woocommerce' )
		);

		echo '<div class="pvk-result">';
		if ( null === $last ) {
			Ui::note( __( 'No report has arrived yet. After a test message is delivered, reload this page to see it here.', 'povikvane-for-woocommerce' ) );
		} else {
			Ui::note(
				sprintf(
					/* translators: %s: date and time. */
					__( 'It works: the last delivery report arrived %s.', 'povikvane-for-woocommerce' ),
					Dates::format( $last['time'] )
				),
				'ok'
			);
		}
		echo '</div>';

		Ui::card_close();

		printf(
			'<div class="pvk-step__nav"><a href="%1$s">%2$s</a><span class="pvk-actions"><a href="%3$s">%4$s</a><a class="pvk-btn pvk-btn--primary" href="%3$s">%5$s</a></span></div>',
			esc_url( $this->step_url( WizardSteps::TEST ) ),
			esc_html__( 'Back', 'povikvane-for-woocommerce' ),
			esc_url( $this->step_url( WizardSteps::DONE ) ),
			esc_html__( 'Skip for now', 'povikvane-for-woocommerce' ),
			esc_html__( 'Continue', 'povikvane-for-woocommerce' )
		);
	}

	/**
	 * Step 6: summary.
	 */
	private function step_done(): void {
		$items    = $this->progress->checklist();
		$complete = SetupChecklist::is_complete( $items );

		Ui::card_open();

		echo '<h2 class="pvk-step__title">' . esc_html( $complete ? __( 'You are all set', 'povikvane-for-woocommerce' ) : __( 'Almost there', 'povikvane-for-woocommerce' ) ) . '</h2>';
		echo '<p class="pvk-step__lead">' . esc_html( $complete ? __( 'Your customers will now get the messages you switched on.', 'povikvane-for-woocommerce' ) : __( 'A few things are still open. You can finish them from the Overview.', 'povikvane-for-woocommerce' ) ) . '</p>';

		echo '<ul class="pvk-checklist">';
		foreach ( $items as $item ) {
			$title = match ( $item['id'] ) {
				SetupChecklist::CONNECT  => __( 'Account connected', 'povikvane-for-woocommerce' ),
				SetupChecklist::MESSAGES => __( 'Order messages chosen', 'povikvane-for-woocommerce' ),
				SetupChecklist::TEST     => __( 'Test message sent', 'povikvane-for-woocommerce' ),
				default                  => __( 'Delivery reports (optional)', 'povikvane-for-woocommerce' ),
			};

			printf(
				'<li><span class="pvk-check%1$s" aria-hidden="true">%2$s</span><span class="pvk-checklist__text"><strong>%3$s</strong></span><span class="screen-reader-text">%4$s</span></li>',
				$item['done'] ? ' is-done' : '',
				$item['done'] ? '&#10003;' : '',
				esc_html( $title ),
				esc_html( $item['done'] ? __( 'Done', 'povikvane-for-woocommerce' ) : __( 'Not done', 'povikvane-for-woocommerce' ) )
			);
		}
		echo '</ul>';

		echo '<h3 style="margin-top:20px;font-weight:600">' . esc_html__( 'Good to know', 'povikvane-for-woocommerce' ) . '</h3><ul class="pvk-need">';
		echo '<li><span>' . esc_html__( 'Promotional messages are off. They need your customers\' agreement, and you can switch them on under Promotions.', 'povikvane-for-woocommerce' ) . '</span></li>';
		echo '<li><span>' . esc_html__( 'Safety rules are active: no messages for old orders or landline numbers, and a pause if many messages are created at once.', 'povikvane-for-woocommerce' ) . '</span></li>';
		echo '</ul>';

		Ui::card_close();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="povikvane_wizard_finish">';
		wp_nonce_field( 'povikvane_wizard_finish' );
		printf(
			'<div class="pvk-step__nav"><a href="%1$s">%2$s</a><button type="submit" class="pvk-btn pvk-btn--primary">%3$s</button></div>',
			esc_url( $this->step_url( WizardSteps::REPORTS ) ),
			esc_html__( 'Back', 'povikvane-for-woocommerce' ),
			esc_html__( 'Go to Overview', 'povikvane-for-woocommerce' )
		);
		echo '</form>';
	}

	// ---- Handlers ----

	/**
	 * Save the account details and check the connection.
	 */
	public function handle_connect(): void {
		check_admin_referer( 'povikvane_wizard_connect' );
		$this->require_capability();

		$api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';

		$input = array_merge(
			$this->settings->all(),
			array(
				'api_key'         => $api_key,
				'service_id'      => isset( $_POST['service_id'] ) ? sanitize_text_field( wp_unslash( $_POST['service_id'] ) ) : '',
				'default_country' => isset( $_POST['default_country'] ) ? sanitize_text_field( wp_unslash( $_POST['default_country'] ) ) : $this->settings->default_country(),
			)
		);

		update_option( Settings::OPTION, $this->sanitizer->sanitize( $input ) );

		( $this->probe )();

		$caps       = get_option( Capabilities::OPTION, array() );
		$connection = is_array( $caps ) ? (string) ( $caps['connection'] ?? 'unknown' ) : 'unknown';

		if ( Capabilities::CONNECTION_OK === $connection ) {
			Flash::add( 'success', __( 'Connected. Your account details work.', 'povikvane-for-woocommerce' ) );
			$this->go( WizardSteps::MESSAGES );
		}

		Flash::add(
			'error',
			Capabilities::CONNECTION_UNREACHABLE === $connection
				? __( 'The service could not be reached. Check your internet connection and try again.', 'povikvane-for-woocommerce' )
				: __( 'Those details were not accepted. Check the API key and service ID in Povikvane under Settings > API.', 'povikvane-for-woocommerce' )
		);
		$this->go( WizardSteps::CONNECT );
	}

	/**
	 * Save the chosen messages.
	 */
	public function handle_messages(): void {
		check_admin_referer( 'povikvane_wizard_messages' );
		$this->require_capability();

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- Nonce verified above; every value is sanitised by TriggerRepository::sanitize().
		$posted = isset( $_POST[ TriggerRepository::OPTION ] ) && is_array( $_POST[ TriggerRepository::OPTION ] ) ? wp_unslash( $_POST[ TriggerRepository::OPTION ] ) : array();
		$merged = $this->triggers->current_input();

		// Only the statuses shown in the wizard are changed; every other status keeps its settings.
		foreach ( self::CORE_STATUSES as $slug ) {
			if ( ! isset( $merged[ $slug ] ) ) {
				continue;
			}

			$row = isset( $posted[ $slug ] ) && is_array( $posted[ $slug ] ) ? $posted[ $slug ] : array();

			$merged[ $slug ]['enabled']  = ! empty( $row['enabled'] );
			$merged[ $slug ]['template'] = isset( $row['template'] ) ? (string) $row['template'] : $merged[ $slug ]['template'];
		}

		update_option( TriggerRepository::OPTION, $this->triggers->sanitize( $merged ) );

		Flash::add( 'success', __( 'Your messages are saved.', 'povikvane-for-woocommerce' ) );
		$this->go( WizardSteps::TEST );
	}

	/**
	 * Send the test message.
	 */
	public function handle_test(): void {
		check_admin_referer( 'povikvane_wizard_test' );
		$this->require_capability();

		$phone  = isset( $_POST['test_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['test_phone'] ) ) : '';
		$result = $this->tester->send( $phone );

		Flash::add( $result['ok'] ? 'success' : 'error', $result['message'] );

		wp_safe_redirect( add_query_arg( array( 'sent' => (int) $result['id'] ), $this->step_url( WizardSteps::TEST ) ) );
		exit;
	}

	/**
	 * Finish: remember that setup is done when it is, and go to the Overview.
	 */
	public function handle_finish(): void {
		check_admin_referer( 'povikvane_wizard_finish' );
		$this->require_capability();

		if ( SetupChecklist::is_complete( $this->progress->checklist() ) ) {
			$this->state->complete();
		} else {
			$this->state->dismiss();
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . SettingsPage::SLUG ) );
		exit;
	}

	/**
	 * AJAX: the status of the test message, so the page can show it arriving.
	 */
	public function ajax_status(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array(), 403 );
		}

		$id      = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
		$message = $this->repository->find( $id );

		// Only test messages are reported here, so this cannot be used to read the log.
		if ( null === $message || 'test' !== $message->event ) {
			wp_send_json_error( array(), 404 );
		}

		wp_send_json_success(
			array(
				'pill'  => StatusView::pill( $message->status ),
				'final' => $message->status->is_final() || Status::Unconfirmed === $message->status,
				'label' => StatusView::label( $message->status ),
			)
		);
	}

	// ---- Helpers ----

	/**
	 * The stepper along the top.
	 *
	 * @param string $current Current step.
	 */
	private function stepper( string $current ): void {
		$labels = array(
			WizardSteps::WELCOME  => __( 'Welcome', 'povikvane-for-woocommerce' ),
			WizardSteps::CONNECT  => __( 'Connect', 'povikvane-for-woocommerce' ),
			WizardSteps::MESSAGES => __( 'Messages', 'povikvane-for-woocommerce' ),
			WizardSteps::TEST     => __( 'Test', 'povikvane-for-woocommerce' ),
			WizardSteps::REPORTS  => __( 'Reports', 'povikvane-for-woocommerce' ),
			WizardSteps::DONE     => __( 'Done', 'povikvane-for-woocommerce' ),
		);

		$position = WizardSteps::position( $current );

		echo '<ol class="pvk-stepper" aria-label="' . esc_attr__( 'Setup steps', 'povikvane-for-woocommerce' ) . '">';

		foreach ( WizardSteps::all() as $index => $step ) {
			$class = ( $index + 1 ) < $position ? ' is-done' : ( ( $index + 1 ) === $position ? ' is-current' : '' );

			printf(
				'<li class="%1$s" data-n="%2$d"%3$s><span>%4$s</span></li>',
				esc_attr( trim( $class ) ),
				(int) ( $index + 1 ),
				( $index + 1 ) === $position ? ' aria-current="step"' : '',
				esc_html( $labels[ $step ] )
			);
		}

		echo '</ol>';
	}

	/**
	 * Address of a step.
	 *
	 * @param string $step Step.
	 */
	private function step_url( string $step ): string {
		return admin_url( 'admin.php?page=' . self::SLUG . '&step=' . $step );
	}

	/**
	 * Go to a step.
	 *
	 * @param string $step Step.
	 */
	private function go( string $step ): never {
		wp_safe_redirect( $this->step_url( $step ) );
		exit;
	}

	/**
	 * The test message the page is showing, from the address.
	 */
	private function sent_id(): ?int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only; the AJAX endpoint only reveals test messages.
		$id = isset( $_GET['sent'] ) ? absint( wp_unslash( $_GET['sent'] ) ) : 0;

		return $id > 0 ? $id : null;
	}

	/**
	 * Stop unless the user may manage the shop.
	 */
	private function require_capability(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'povikvane-for-woocommerce' ), '', array( 'response' => 403 ) );
		}
	}
}
