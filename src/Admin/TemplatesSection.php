<?php
/**
 * Message template editor.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Admin;

use Povikvane\WooCommerce\Account\AccountInfo;
use Povikvane\WooCommerce\Api\Capabilities;
use Povikvane\WooCommerce\Messages\Placeholders;
use Povikvane\WooCommerce\Orders\Trigger;
use Povikvane\WooCommerce\Orders\TriggerRepository;
use Povikvane\WooCommerce\Support\Dates;
use Povikvane\WooCommerce\Support\Settings;
use Povikvane\WooCommerce\Viber\TemplateCatalog;
use Povikvane\WooCommerce\Viber\TemplateDraft;
use Povikvane\WooCommerce\Viber\TemplateFiller;
use Povikvane\WooCommerce\Viber\TemplateMatch;
use Povikvane\WooCommerce\Viber\TemplateMatcher;
use Povikvane\WooCommerce\Viber\TemplateRequests;
use Povikvane\WooCommerce\Viber\TemplateRequestService;
use Povikvane\WooCommerce\Viber\TemplateSubmitter;
use Povikvane\WooCommerce\Viber\UnavailableSubmitter;

use const Povikvane\WooCommerce\PLUGIN_FILE;
use const Povikvane\WooCommerce\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Viber on or off for the whole shop, then the table of order statuses with an
 * on/off switch, delay and text for each, a live estimate of how many SMS segments
 * a text costs, and whether each text is approved as a Viber template.
 */
final class TemplatesSection {

	/**
	 * Constructor.
	 *
	 * @param TriggerRepository     $triggers  Trigger configuration.
	 * @param Settings              $settings  Plugin settings.
	 * @param TemplateCatalog       $catalog   Viber templates.
	 * @param TemplateRequests      $requests  Texts already sent for review.
	 * @param TemplateSubmitter     $submitter Sends templates for review.
	 * @param FieldLabelsPanel|null $labels Custom field label check.
	 */
	public function __construct(
		private readonly TriggerRepository $triggers,
		private readonly Settings $settings,
		private readonly TemplateCatalog $catalog = new TemplateCatalog(),
		private readonly TemplateRequests $requests = new TemplateRequests(),
		private readonly TemplateSubmitter $submitter = new UnavailableSubmitter(),
		private readonly ?FieldLabelsPanel $labels = null
	) {}

	/**
	 * Print the editor. Must be called inside the settings form.
	 */
	public function render(): void {
		$caps    = get_option( Capabilities::OPTION, array() );
		$enabled = $this->settings->viber_enabled();

		$this->render_viber_card( $caps, $enabled );

		echo '<section class="pvk-card"><div class="pvk-card__head"><h2 class="pvk-card__title">' . esc_html__( 'Order messages', 'povikvane-for-woocommerce' ) . '</h2><p class="pvk-card__intro">' . esc_html__( 'Choose which order statuses send a text message to the customer, and what it says. Only order-related information belongs here; use the Promotions tab for anything promotional.', 'povikvane-for-woocommerce' ) . '</p>';

		echo '<p class="pvk-card__intro">' . esc_html__( 'Details you can add:', 'povikvane-for-woocommerce' ) . ' ';
		foreach ( Placeholders::NAMES as $name ) {
			if ( 'optout_link' !== $name ) {
				echo '<code>{' . esc_html( $name ) . '}</code> ';
			}
		}
		echo '</p></div>';

		$this->render_request_bar( $enabled );

		echo '<div style="margin-top:12px">';

		foreach ( $this->ordered_statuses() as $slug => $label ) {
			$this->render_row( $slug, $label, $this->triggers->for_status( $slug ) );
		}

		echo '</div><div class="pvk-card__foot">' . esc_html__( 'A delay lets you cancel by changing the status again. A message is only sent if the order still has the status it was created for.', 'povikvane-for-woocommerce' ) . '</div></section>';
	}

	/**
	 * The shop-wide Viber switch and what the account offers.
	 *
	 * @param mixed $caps    Stored capabilities.
	 * @param bool  $enabled Whether Viber is switched on.
	 * @param bool  $in_wizard Whether the setup wizard prints it: plain field names, and the labels get a card of their own.
	 */
	public function render_viber_card( mixed $caps, bool $enabled, bool $in_wizard = false ): void {
		$name     = $in_wizard ? '' : Settings::OPTION;
		$fallback = $this->settings->viber_sms_fallback();
		$status   = Capabilities::viber_status( $caps, $fallback );
		$approved = $this->approved_count();
		$ajax     = array(
			'url'         => admin_url( 'admin-ajax.php' ),
			'action'      => TemplateRequestAjax::ACTION,
			'nonce'       => wp_create_nonce( TemplateRequestAjax::NONCE ),
			'available'   => $this->submitter->available(),
			'confirm'     => __( 'Send the selected messages to Povikvane for approval? The Povikvane team will review them as soon as possible. Until a message is approved it keeps going out as an SMS.', 'povikvane-for-woocommerce' ),
			'confirm_one' => __( 'Send this message to Povikvane for approval? The Povikvane team will review it as soon as possible. Until it is approved it keeps going out as an SMS.', 'povikvane-for-woocommerce' ),
			'working'     => __( 'Sending...', 'povikvane-for-woocommerce' ),
			'nothing'     => __( 'Nothing to send: every selected message already has a template or was already sent.', 'povikvane-for-woocommerce' ),
			'error'       => __( 'Could not reach the server. Try again.', 'povikvane-for-woocommerce' ),
			'sent'        => __( 'Sent for approval', 'povikvane-for-woocommerce' ),
		);

		if ( $in_wizard ) {
			// Sending for approval also saves the choices on the page.
			$ajax['save_action'] = SetupWizard::SAVE_ACTION;
			$ajax['saved']       = __( 'Your choices are saved.', 'povikvane-for-woocommerce' );
		}

		echo '<section class="pvk-card" data-pvk-viber-card data-pvk-requests="' . esc_attr( (string) wp_json_encode( $ajax ) ) . '"><div class="pvk-card__head"><h2 class="' . ( $in_wizard ? 'pvk-step__title' : 'pvk-card__title' ) . '">' . esc_html__( 'Viber', 'povikvane-for-woocommerce' ) . '</h2><p class="' . ( $in_wizard ? 'pvk-step__lead' : 'pvk-card__intro' ) . '">' . esc_html__( 'Send order updates over Viber instead of SMS. A message goes out over Viber when an approved Viber template has the same wording as the message; otherwise it goes out as an SMS.', 'povikvane-for-woocommerce' ) . '</p></div><div class="pvk-card__body">';

		ViberControls::render_switch( $enabled, $in_wizard ? 'viber_enabled' : $name . '[viber_enabled]' );

		echo '<div data-pvk-viber-only' . ( $enabled ? '' : ' hidden' ) . '>';

		Ui::field_open( __( 'Status', 'povikvane-for-woocommerce' ) );
		echo Ui::pill( Capabilities::VIBER_READY === $status ? __( 'Available', 'povikvane-for-woocommerce' ) : __( 'Not available', 'povikvane-for-woocommerce' ), Capabilities::VIBER_READY === $status ? 'ok' : 'warn' ) . ' ' . esc_html( ViberView::reason( $status ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by Ui::pill() and esc_html().
		Ui::field_close();

		if ( ! $in_wizard ) {
			// The wizard leaves this row out: the lists under each message are enough there.
			Ui::field_open( __( 'Your templates', 'povikvane-for-woocommerce' ) );

			if ( 0 === $approved ) {
				echo Ui::pill( __( 'None approved', 'povikvane-for-woocommerce' ), 'warn' ) . ' ' . esc_html__( 'You have no approved Viber templates yet, so every message goes out as an SMS. Send your messages for approval below.', 'povikvane-for-woocommerce' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by Ui::pill() and esc_html__().
			} else {
				echo Ui::pill( sprintf( /* translators: %d: number of templates. */ _n( '%d approved', '%d approved', $approved, 'povikvane-for-woocommerce' ), $approved ), 'ok' ) . ' ' . esc_html__( 'Messages with the same wording as one of these go out over Viber. The badge under each message below shows which.', 'povikvane-for-woocommerce' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by Ui::pill() and esc_html__().
			}

			$this->template_list();

			echo '<p class="pvk-field__action">';
			Layout::action_button( __( 'Refresh templates', 'povikvane-for-woocommerce' ), 'povikvane_check_connection' );
			echo '</p>';
			Ui::field_close();
		}

		ViberControls::render_fallback( $fallback, AccountInfo::sms_fallback_allowed( $caps ), $in_wizard ? 'viber_sms_fallback' : $name . '[viber_sms_fallback]' );

		if ( ! $in_wizard && null !== $this->labels ) {
			$this->labels->render( admin_url( 'admin.php?page=' . SettingsPage::SLUG . '&tab=messages' ) );
		}

		echo '</div></div></section>';
	}

	/**
	 * One order status.
	 *
	 * @param string  $slug    Status slug.
	 * @param string  $label   Status name.
	 * @param Trigger $trigger Stored configuration.
	 */
	private function render_row( string $slug, string $label, Trigger $trigger ): void {
		$name  = TriggerRepository::OPTION . '[' . $slug . ']';
		$match = TemplateMatcher::find( $trigger->template, $this->catalog );

		printf(
			'<div class="pvk-trigger%1$s" data-pvk-status="%2$s" data-pvk-initial="%3$s" data-pvk-match="%4$s"><div>',
			$trigger->enabled ? ' is-on' : '',
			esc_attr( $slug ),
			esc_attr( $trigger->template ),
			esc_attr( $match->state )
		);
		Ui::toggle( $name . '[enabled]', $trigger->enabled, $label );
		$this->template_picker( $slug, $this->settings->viber_enabled() );
		printf(
			'<div class="pvk-trigger__field"><label for="pvk-delay-%1$s">%2$s</label><div class="pvk-inline"><input type="number" id="pvk-delay-%1$s" name="%3$s[delay]" value="%4$d" min="0" max="1440"><span>%5$s</span></div></div>',
			esc_attr( $slug ),
			esc_html__( 'Wait', 'povikvane-for-woocommerce' ),
			esc_attr( $name ),
			(int) $trigger->delay_minutes,
			esc_html__( 'minutes first', 'povikvane-for-woocommerce' )
		);
		echo '</div><div>';
		printf(
			'<label class="screen-reader-text" for="pvk-text-%1$s">%2$s</label><textarea id="pvk-text-%1$s" name="%3$s[template]" rows="3" maxlength="1000" data-povikvane-template>%4$s</textarea><div class="pvk-estimate povikvane-estimate" aria-live="polite"></div>',
			esc_attr( $slug ),
			esc_html(
				sprintf(
					/* translators: %s: order status name. */
					__( 'Message when an order becomes "%s"', 'povikvane-for-woocommerce' ),
					$label
				)
			),
			esc_attr( $name ),
			esc_textarea( $trigger->template )
		);

		$this->price_check();
		$this->viber_notes( $slug, $trigger->template, $this->settings->viber_enabled(), $match );

		echo '</div></div>';
	}

	/**
	 * Under a message's switch: a list of the approved templates, to fill the message with one.
	 *
	 * @param string $slug     Status slug.
	 * @param bool   $viber_on Whether Viber is switched on, so the list is shown.
	 */
	public function template_picker( string $slug, bool $viber_on ): void {
		$options = '';

		foreach ( $this->catalog->all() as $template ) {
			$text = $template->is_transactional() ? TemplateFiller::text_for( $template ) : null;

			if ( null === $text ) {
				continue;
			}

			$found = TemplateMatcher::find( $text, $this->catalog );

			if ( ! $found->is_ready() ) {
				continue;
			}

			$options .= sprintf(
				'<option value="%1$s" data-text="%2$s" data-match="%3$s" data-badge="%4$s">%5$s</option>',
				esc_attr( $template->id ),
				esc_attr( $text ),
				esc_attr( $found->state ),
				esc_attr( $this->badge( $slug, $text, $found ) ),
				esc_html( $template->name )
			);
		}

		if ( '' === $options ) {
			return;
		}

		// The way back to the wording the plugin suggests for this status.
		$suggested = $this->triggers->default_text( $slug );
		$found     = TemplateMatcher::find( $suggested, $this->catalog );
		$options   = sprintf(
			'<option value="%1$s" data-text="%2$s" data-match="%3$s" data-badge="%4$s">%5$s</option>',
			'suggested',
			esc_attr( $suggested ),
			esc_attr( $found->state ),
			esc_attr( $this->badge( $slug, $suggested, $found ) ),
			esc_html__( 'The message the plugin suggests', 'povikvane-for-woocommerce' )
		) . $options;

		printf(
			'<div class="pvk-pick" data-pvk-viber-only%1$s><label for="pvk-pick-%2$s">%3$s</label><select id="pvk-pick-%2$s" data-pvk-pick data-confirm="%4$s"><option value="">%5$s</option>%6$s</select></div>',
			$viber_on ? '' : ' hidden',
			esc_attr( $slug ),
			esc_html__( 'Or choose a different message', 'povikvane-for-woocommerce' ),
			esc_attr__( 'Replace the text you changed?', 'povikvane-for-woocommerce' ),
			esc_html__( 'Choose...', 'povikvane-for-woocommerce' ),
			$options // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Every part is escaped above.
		);
	}

	/**
	 * The account's templates with their language and state, folded away until asked for.
	 */
	private function template_list(): void {
		$templates = $this->catalog->all();

		if ( array() === $templates ) {
			return;
		}

		$state = array(
			'approved' => array( __( 'Approved', 'povikvane-for-woocommerce' ), 'ok' ),
			'pending'  => array( __( 'Awaiting approval', 'povikvane-for-woocommerce' ), 'warn' ),
			'rejected' => array( __( 'Rejected', 'povikvane-for-woocommerce' ), 'bad' ),
		);

		echo '<details class="pvk-templates"><summary>' . esc_html__( 'Show all templates', 'povikvane-for-woocommerce' ) . '</summary>';
		echo '<table class="pvk-table"><thead><tr><th scope="col">' . esc_html__( 'Name', 'povikvane-for-woocommerce' ) . '</th><th scope="col">' . esc_html__( 'Language', 'povikvane-for-woocommerce' ) . '</th><th scope="col">' . esc_html__( 'Status', 'povikvane-for-woocommerce' ) . '</th></tr></thead><tbody>';

		foreach ( $templates as $template ) {
			$label = $state[ $template->status ] ?? array( $template->status, '' );

			echo '<tr><td>' . esc_html( $template->name ) . '</td><td>' . esc_html( self::language_name( $template->lang ) ) . '</td><td>' . Ui::pill( $label[0], $label[1] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by Ui::pill() and esc_html().
		}

		echo '</tbody></table></details>';
	}

	/**
	 * A template's language in words.
	 *
	 * @param string|null $lang Code such as "bg" or "en", or null when the account does not say.
	 */
	private static function language_name( ?string $lang ): string {
		return match ( strtolower( (string) $lang ) ) {
			'bg'    => 'Български',
			'en'    => 'English',
			''      => '-',
			default => strtoupper( (string) $lang ),
		};
	}

	/**
	 * Under a message: a link that asks Povikvane what it costs, when the account can say.
	 */
	public function price_check(): void {
		if ( true !== AccountInfo::feature( get_option( Capabilities::OPTION, array() ), 'estimate' ) ) {
			return;
		}

		echo '<div class="pvk-price"><button type="button" class="pvk-btn pvk-btn--link" data-pvk-price>' . esc_html__( 'Check price', 'povikvane-for-woocommerce' ) . '</button> <span class="pvk-field__hint" data-pvk-price-result role="status" aria-live="polite"></span></div>';
	}

	/**
	 * Under a message: whether it can go out over Viber, and the offer to send an edited one for approval.
	 *
	 * @param string             $slug     Status slug.
	 * @param string             $template Message text.
	 * @param bool               $viber_on Whether Viber is switched on, so the block is shown.
	 * @param TemplateMatch|null $found    Matching template; looked up when not given.
	 */
	public function viber_notes( string $slug, string $template, bool $viber_on, ?TemplateMatch $found = null ): void {
		$found ??= TemplateMatcher::find( $template, $this->catalog );

		echo '<div data-pvk-viber-only' . ( $viber_on ? '' : ' hidden' ) . '>';
		echo '<div class="pvk-viber-badge" data-pvk-badge>' . $this->badge( $slug, $template, $found ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge().
		echo '<div class="pvk-viber-changed" data-pvk-changed hidden><span>' . esc_html__( 'You changed a message that is approved for Viber. As edited it goes out as an SMS until Povikvane approves it.', 'povikvane-for-woocommerce' ) . '</span> ';

		if ( $this->submitter->available() ) {
			echo '<button type="button" class="pvk-btn" data-pvk-request-one="' . esc_attr( $slug ) . '">' . esc_html__( 'Send for approval', 'povikvane-for-woocommerce' ) . '</button>';
		}

		echo '</div></div>';
	}

	/**
	 * The line under a message that says whether it can go out over Viber.
	 *
	 * @param string        $slug     Status slug.
	 * @param string        $template Message text.
	 * @param TemplateMatch $found    Matching template, if any.
	 */
	private function badge( string $slug, string $template, TemplateMatch $found ): string {
		$name = null === $found->template ? '' : $found->template->name;

		switch ( $found->state ) {
			case TemplateMatch::READY:
				return Ui::pill( __( 'Approved for Viber', 'povikvane-for-woocommerce' ), 'ok' ) . ' ' . esc_html(
					sprintf(
						/* translators: %s: template name. */
						__( 'Matches the template "%s".', 'povikvane-for-woocommerce' ),
						$name
					)
				);
			case TemplateMatch::NOT_TRANSACTION:
				return Ui::pill( __( 'Not usable', 'povikvane-for-woocommerce' ), 'warn' ) . ' ' . esc_html(
					sprintf(
						/* translators: %s: template name. */
						__( 'Matches the template "%s", but it is not registered as a transactional template, so this message goes out as an SMS.', 'povikvane-for-woocommerce' ),
						$name
					)
				);
			case TemplateMatch::PENDING:
				return Ui::pill( __( 'Awaiting approval', 'povikvane-for-woocommerce' ), 'warn' ) . ' ' . esc_html(
					sprintf(
						/* translators: %s: template name. */
						__( 'Matches the template "%s", which is still being reviewed. Until then it goes out as an SMS.', 'povikvane-for-woocommerce' ),
						$name
					)
				);
			case TemplateMatch::REJECTED:
				return Ui::pill( __( 'Rejected', 'povikvane-for-woocommerce' ), 'bad' ) . ' ' . esc_html(
					sprintf(
						/* translators: %s: template name. */
						__( 'The template "%s" was rejected, so this message goes out as an SMS. Change the wording to send a new one.', 'povikvane-for-woocommerce' ),
						$name
					)
				);
		}

		$sent = $this->requests->sent_at( $slug, $template );

		if ( 0 !== $sent ) {
			return Ui::pill( __( 'Sent for approval', 'povikvane-for-woocommerce' ), 'info' ) . ' ' . esc_html(
				sprintf(
					/* translators: %s: date and time. */
					__( 'Sent on %s. Until it is approved it goes out as an SMS.', 'povikvane-for-woocommerce' ),
					Dates::format( $sent )
				)
			);
		}

		$draft = TemplateDraft::from_text( $template );

		return Ui::pill( __( 'Not approved', 'povikvane-for-woocommerce' ), 'warn' ) . ' ' . esc_html(
			null === $draft->problem
				? __( 'No approved template has this wording, so it goes out as an SMS. Send it for approval to use Viber.', 'povikvane-for-woocommerce' )
				: TemplateRequestService::problem_text( $draft->problem )
		);
	}

	/**
	 * The button that sends every selected message without a template for review.
	 *
	 * @param bool $enabled Whether Viber is switched on.
	 */
	public function render_request_bar( bool $enabled ): void {
		echo '<div class="pvk-request-bar" data-pvk-viber-only' . ( $enabled ? '' : ' hidden' ) . '>';

		if ( $this->submitter->available() ) {
			echo '<button type="button" class="pvk-btn pvk-btn--primary" data-pvk-request-all>' . esc_html__( 'Send selected messages for approval', 'povikvane-for-woocommerce' ) . '</button>';
			echo '<p class="pvk-field__hint">' . esc_html__( 'Sends every switched-on message that has no approved template. The Povikvane team reviews them as soon as possible.', 'povikvane-for-woocommerce' ) . '</p>';
		} else {
			echo '<button type="button" class="pvk-btn pvk-btn--primary" disabled>' . esc_html__( 'Send selected messages for approval', 'povikvane-for-woocommerce' ) . '</button>';
			echo '<p class="pvk-field__hint">' . esc_html__( 'Sending messages for approval from here is coming soon. Until then, create the templates in Povikvane; they appear here once approved.', 'povikvane-for-woocommerce' ) . '</p>';
		}

		echo '<p class="pvk-request-result" data-pvk-request-result role="status" aria-live="polite"></p></div>';
	}

	/**
	 * Whether messages can be sent to Povikvane for approval from here.
	 */
	public function can_request(): bool {
		return $this->submitter->available();
	}

	/**
	 * How many templates are approved and transactional.
	 */
	private function approved_count(): int {
		return count( array_filter( $this->catalog->all(), static fn ( $template ): bool => $template->is_transactional() ) );
	}

	/**
	 * How many switched-on order messages go out as SMS although Viber is on, because
	 * no approved template has their wording. 0 when Viber is off.
	 */
	public function viber_fallback_count(): int {
		if ( ! $this->settings->viber_enabled() ) {
			return 0;
		}

		$count = 0;

		foreach ( array_keys( $this->triggers->statuses() ) as $slug ) {
			$trigger = $this->triggers->for_status( $slug );

			if ( $trigger->enabled && ! TemplateMatcher::find( $trigger->template, $this->catalog )->is_ready() ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Statuses with the common ones first, so the list starts with what most shops use.
	 *
	 * @return array<string,string>
	 */
	private function ordered_statuses(): array {
		$statuses = $this->triggers->statuses();
		$priority = array_flip( array( 'processing', 'completed', 'on-hold', 'cancelled', 'refunded', 'failed' ) );

		uksort( $statuses, static fn( string $a, string $b ): int => ( $priority[ $a ] ?? 99 ) <=> ( $priority[ $b ] ?? 99 ) );

		return $statuses;
	}

	/**
	 * Load the editor scripts.
	 */
	public function enqueue(): void {
		wp_enqueue_script( 'povikvane-segments', plugins_url( 'assets/js/segments.js', PLUGIN_FILE ), array(), VERSION, true );
		wp_enqueue_script( 'povikvane-admin', plugins_url( 'assets/js/admin.js', PLUGIN_FILE ), array( 'povikvane-segments' ), VERSION, true );
		wp_enqueue_script( 'povikvane-viber', plugins_url( 'assets/js/viber.js', PLUGIN_FILE ), array(), VERSION, true );
		wp_enqueue_script( 'povikvane-price', plugins_url( 'assets/js/price.js', PLUGIN_FILE ), array(), VERSION, true );
		wp_localize_script(
			'povikvane-price',
			'povikvanePrice',
			array(
				'url'     => admin_url( 'admin-ajax.php' ),
				'action'  => PriceEstimateAjax::ACTION,
				'nonce'   => wp_create_nonce( PriceEstimateAjax::NONCE ),
				'working' => __( 'Checking...', 'povikvane-for-woocommerce' ),
				'error'   => __( 'Could not reach the server. Try again.', 'povikvane-for-woocommerce' ),
			)
		);

		$shop = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		$common = array(
			'order_number' => '1234',
			'order_total'  => '45.00 EUR',
			'shop_name'    => $shop,
			'tracking_url' => 'https://track.example.com/TRK1234567890',
			'shop_url'     => untrailingslashit( home_url() ),
			'optout_link'  => home_url( '/?po=0123456789ab-0123456789ab' ),
		);

		wp_localize_script(
			'povikvane-admin',
			'povikvaneAdmin',
			array(
				'names'   => Placeholders::NAMES,
				'samples' => array(
					'latin'    => $common,
					'cyrillic' => array_merge( $common, array( 'shop_name' => 'Моят магазин' ) ),
				),
				'i18n'    => array(
					'gsm7'       => __( 'Normal text', 'povikvane-for-woocommerce' ),
					'ucs2'       => __( 'Text with special letters (e.g. Cyrillic)', 'povikvane-for-woocommerce' ),
					'characters' => __( 'characters', 'povikvane-for-woocommerce' ),
					'segment'    => __( 'message', 'povikvane-for-woocommerce' ),
					'segments'   => __( 'messages', 'povikvane-for-woocommerce' ),
					'credit'     => __( 'credit', 'povikvane-for-woocommerce' ),
					'credits'    => __( 'credits', 'povikvane-for-woocommerce' ),
					'cyrillic'   => __( 'With a Cyrillic shop name:', 'povikvane-for-woocommerce' ),
					'unknown'    => __( 'Unknown placeholder:', 'povikvane-for-woocommerce' ),
					'empty'      => __( 'Empty message: nothing will be sent.', 'povikvane-for-woocommerce' ),
				),
			)
		);
	}
}
