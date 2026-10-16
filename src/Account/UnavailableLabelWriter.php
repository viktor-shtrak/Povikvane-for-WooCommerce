<?php
/**
 * Stands in until the API can change labels.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Account;

defined( 'ABSPATH' ) || exit;

/**
 * Changes nothing.
 */
final class UnavailableLabelWriter implements LabelWriter {

	/**
	 * {@inheritDoc}
	 */
	public function available(): bool {
		return false;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string,string> $labels Labels.
	 */
	public function save( array $labels ): bool {
		return false;
	}
}
