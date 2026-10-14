<?php
/**
 * Sends the test message.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Admin;

use Povikvane\WooCommerce\Log\Message;
use Povikvane\WooCommerce\Messages\Enqueuer;
use Povikvane\WooCommerce\Messages\Reference;
use Povikvane\WooCommerce\Phone\Normalizer;
use Povikvane\WooCommerce\Support\Countries;
use Povikvane\WooCommerce\Support\Settings;
use Povikvane\WooCommerce\Support\SiteIdentity;

defined( 'ABSPATH' ) || exit;

/**
 * Queues one real SMS to a number the shop owner types in. Used by the settings
 * screen and the setup wizard.
 */
final class TestSender {

	/**
	 * Constructor.
	 *
	 * @param Settings   $settings   Settings.
	 * @param Normalizer $normalizer Phone normaliser.
	 * @param Enqueuer   $enqueuer   Queues messages.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly Normalizer $normalizer,
		private readonly Enqueuer $enqueuer
	) {}

	/**
	 * Queue a test message.
	 *
	 * @param string $phone What the user typed.
	 * @return array{ok:bool,message:string,id:int|null} Outcome, text for the user, and the log row ID when queued.
	 */
	public function send( string $phone ): array {
		if ( ! $this->settings->credentials()->is_complete() ) {
			return $this->fail( __( 'Enter and save your API key and service ID first.', 'povikvane-for-woocommerce' ) );
		}

		$number = $this->normalizer->normalize( $phone, Countries::calling_code( $this->settings->default_country() ) );

		if ( ! $number->is_valid() ) {
			return $this->fail( __( 'That phone number does not look valid.', 'povikvane-for-woocommerce' ) );
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

		$id = $this->enqueuer->enqueue( $message );

		if ( null === $id ) {
			return $this->fail( __( 'A test message was just sent. Wait a second and try again.', 'povikvane-for-woocommerce' ) );
		}

		return array(
			'ok'      => true,
			'message' => __( 'Test message queued. It should reach your phone within a minute.', 'povikvane-for-woocommerce' ),
			'id'      => $id,
		);
	}

	/**
	 * A failed outcome.
	 *
	 * @param string $message Text for the user.
	 * @return array{ok:bool,message:string,id:int|null}
	 */
	private function fail( string $message ): array {
		return array(
			'ok'      => false,
			'message' => $message,
			'id'      => null,
		);
	}
}
