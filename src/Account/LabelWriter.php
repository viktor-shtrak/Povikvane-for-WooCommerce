<?php
/**
 * Writes custom field labels to the Povikvane account.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Account;

defined( 'ABSPATH' ) || exit;

/**
 * Behind an interface because the API cannot change labels yet; until it can, the
 * screens show the comparison and say that changing them is coming.
 */
interface LabelWriter {

	/**
	 * Whether labels can be changed from the plugin.
	 */
	public function available(): bool;

	/**
	 * Set the labels.
	 *
	 * @param array<string,string> $labels Field => label.
	 * @return bool Whether Povikvane accepted them.
	 */
	public function save( array $labels ): bool;
}
