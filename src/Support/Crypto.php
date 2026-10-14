<?php
/**
 * Authenticated encryption for stored credentials.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Libsodium secretbox with a key derived from caller-supplied material (WordPress
 * salts in production). If the material changes, existing values cannot be read
 * and decrypt() returns null, so callers must handle "ask the user again".
 */
final class Crypto {

	private const PREFIX = 'v1:';

	/**
	 * Derived key.
	 *
	 * @var string
	 */
	private string $key;

	/**
	 * Constructor.
	 *
	 * @param string $key_material Secret material the key is derived from.
	 */
	public function __construct( string $key_material ) {
		$this->key = sodium_crypto_generichash( $key_material, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	/**
	 * Encrypt a value.
	 *
	 * @param string $plain Plain text.
	 * @return string Versioned, base64 encoded ciphertext.
	 */
	public function encrypt( string $plain ): string {
		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( $plain, $nonce, $this->key );

		return self::PREFIX . base64_encode( $nonce . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary-safe transport encoding of ciphertext, not obfuscation.
	}

	/**
	 * Decrypt a value produced by encrypt().
	 *
	 * @param string $encoded Output of encrypt().
	 * @return string|null Plain text, or null if it cannot be decrypted.
	 */
	public function decrypt( string $encoded ): ?string {
		if ( ! str_starts_with( $encoded, self::PREFIX ) ) {
			return null;
		}

		$raw = base64_decode( substr( $encoded, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding our own ciphertext.
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}

		$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, $this->key );

		return false === $plain ? null : $plain;
	}
}
