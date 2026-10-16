<?php
/**
 * A public form, placed on any page with a shortcode, where anyone can stop promotional SMS.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Marketing;

use Povikvane\WooCommerce\Phone\Normalizer;
use Povikvane\WooCommerce\Support\Countries;

defined( 'ABSPATH' ) || exit;

/**
 * [povikvane_optout] shows a phone number box. A valid number is recorded as having
 * withdrawn consent, whether or not it ever agreed, and the answer is the same either
 * way, so the form reveals nothing about who is on the list. It works even when
 * promotional messages are switched off.
 */
final class OptOutForm {

	public const SHORTCODE = 'povikvane_optout';
	public const ACTION    = 'povikvane_optout';
	public const PARAM     = 'povikvane_optout';

	public const DONE    = 'done';
	public const INVALID = 'invalid';
	public const WAIT    = 'wait';

	private const MAX_PER_HOUR = 10;

	/**
	 * Constructor.
	 *
	 * @param Consent    $consent         Consent rules.
	 * @param Normalizer $normalizer      Phone normaliser.
	 * @param string     $default_country Country used for numbers written without a country code.
	 */
	public function __construct(
		private readonly Consent $consent,
		private readonly Normalizer $normalizer,
		private readonly string $default_country
	) {}

	/**
	 * Register the shortcode and the form handler.
	 */
	public function register(): void {
		add_shortcode( self::SHORTCODE, array( $this, 'render' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Record a request.
	 *
	 * @param string $phone    What the visitor typed.
	 * @param string $trap     Value of the hidden field; real people leave it empty.
	 * @param bool   $limited  Whether this visitor made too many requests lately.
	 * @return string One of DONE, INVALID or WAIT.
	 */
	public function process( string $phone, string $trap, bool $limited ): string {
		if ( $limited ) {
			return self::WAIT;
		}

		// A robot filled the hidden field: pretend it worked and record nothing.
		if ( '' !== $trap ) {
			return self::DONE;
		}

		$number = $this->normalizer->normalize( $phone, Countries::calling_code( $this->default_country ) );

		if ( ! $number->is_valid() ) {
			return self::INVALID;
		}

		$this->consent->revoke( (string) $number->e164, ConsentEvent::SOURCE_OPTOUT );

		return self::DONE;
	}

	/**
	 * The shortcode output.
	 */
	public function render(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only chooses which message to show.
		$result = isset( $_GET[ self::PARAM ] ) ? sanitize_key( wp_unslash( $_GET[ self::PARAM ] ) ) : '';

		$message = match ( $result ) {
			self::DONE    => sprintf(
				/* translators: %s: shop name. */
				__( 'Done. This number will not get promotional SMS messages from %s any more. You will still receive messages about your orders.', 'povikvane-for-woocommerce' ),
				wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
			),
			self::INVALID => __( 'That does not look like a mobile phone number. Check it and try again.', 'povikvane-for-woocommerce' ),
			self::WAIT    => __( 'Too many attempts. Please try again later.', 'povikvane-for-woocommerce' ),
			default       => '',
		};

		$out = '<form class="povikvane-optout" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';

		if ( '' !== $message ) {
			$out .= '<p class="povikvane-optout__message" role="status">' . esc_html( $message ) . '</p>';
		}

		$out .= '<p><label for="povikvane-optout-phone">' . esc_html__( 'Your mobile phone number', 'povikvane-for-woocommerce' ) . '</label><br>';
		$out .= '<input type="tel" id="povikvane-optout-phone" name="phone" autocomplete="tel" required placeholder="' . esc_attr__( 'e.g. 0888 123 456', 'povikvane-for-woocommerce' ) . '"></p>';
		// Hidden from people, tempting to robots.
		$out .= '<p style="position:absolute;left:-9999px" aria-hidden="true"><label>' . esc_html__( 'Leave this empty', 'povikvane-for-woocommerce' ) . '<input type="text" name="website" tabindex="-1" autocomplete="off"></label></p>';
		$out .= '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		$here = get_permalink();
		$out .= '<input type="hidden" name="back" value="' . esc_url( false === $here ? home_url( '/' ) : $here ) . '">';
		$out .= '<p><button type="submit">' . esc_html__( 'Stop promotional SMS', 'povikvane-for-woocommerce' ) . '</button></p></form>';

		return $out;
	}

	/**
	 * Handle the form.
	 */
	public function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- A public opt-out form cannot carry a nonce: pages with the form are cached. It is limited by a hidden field and a rate limit instead, and it can only withdraw consent.
		$phone = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		$trap  = isset( $_POST['website'] ) ? sanitize_text_field( wp_unslash( $_POST['website'] ) ) : '';
		$back  = isset( $_POST['back'] ) ? esc_url_raw( wp_unslash( $_POST['back'] ) ) : '';
		// phpcs:enable

		$key     = 'povikvane_optout_' . md5( isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '' );
		$count   = (int) get_transient( $key );
		$limited = $count >= self::MAX_PER_HOUR;

		set_transient( $key, $count + 1, HOUR_IN_SECONDS );

		$result = $this->process( $phone, $trap, $limited );

		wp_safe_redirect( add_query_arg( self::PARAM, $result, '' !== $back ? $back : home_url( '/' ) ) );
		exit;
	}
}
