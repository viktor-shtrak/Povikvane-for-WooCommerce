<?php
/**
 * The secret Povikvane signs webhooks with, kept encrypted on this site.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Webhook;

use Povikvane\WooCommerce\Support\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * Povikvane shows the secret once, so this is the only copy. It never goes to the
 * browser, the log or the support report.
 */
final class WebhookSecret {

	public const OPTION = 'povikvane_webhook_secret_enc';

	/**
	 * Constructor.
	 *
	 * @param Crypto $crypto Encrypts the secret.
	 */
	public function __construct( private readonly Crypto $crypto ) {}

	/**
	 * The stored secret, or null when there is none.
	 */
	public function get(): ?string {
		$stored = get_option( self::OPTION, '' );

		if ( ! is_string( $stored ) || '' === $stored ) {
			return null;
		}

		$plain = $this->crypto->decrypt( $stored );

		return null !== $plain && str_starts_with( $plain, 'whsec_' ) ? $plain : null;
	}

	/**
	 * Whether a secret is stored.
	 */
	public function exists(): bool {
		return null !== $this->get();
	}

	/**
	 * Store a secret.
	 *
	 * @param string $secret A "whsec_" value from Povikvane.
	 */
	public function set( string $secret ): void {
		update_option( self::OPTION, $this->crypto->encrypt( $secret ), false );
	}
}
