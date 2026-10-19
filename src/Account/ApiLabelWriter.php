<?php
/**
 * Writes custom field labels through the Public API.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Account;

use Povikvane\WooCommerce\Api\Gateway;
use Povikvane\WooCommerce\Api\Outcome;

defined( 'ABSPATH' ) || exit;

/**
 * Only the three fields the plugin uses are touched; the account's other fields stay as they are.
 */
final class ApiLabelWriter implements LabelWriter {

	/**
	 * Constructor.
	 *
	 * @param Gateway $gateway Talks to the API.
	 * @param bool    $enabled Whether the account endpoint is available.
	 */
	public function __construct(
		private readonly Gateway $gateway,
		private readonly bool $enabled
	) {}

	/**
	 * {@inheritDoc}
	 */
	public function available(): bool {
		return $this->enabled;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string,string> $labels Labels.
	 */
	public function save( array $labels ): bool {
		$clean = array();

		foreach ( AccountInfo::FIELDS as $field ) {
			if ( isset( $labels[ $field ] ) ) {
				$clean[ $field ] = mb_substr( trim( $labels[ $field ] ), 0, 60 );
			}
		}

		return array() !== $clean && Outcome::Success === $this->gateway->set_custom_fields( $clean )->outcome;
	}
}
