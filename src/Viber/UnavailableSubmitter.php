<?php
/**
 * Stands in until the template endpoint exists.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Viber;

defined( 'ABSPATH' ) || exit;

/**
 * Says no to everything. Replaced by a real submitter once the API can create templates.
 */
final class UnavailableSubmitter implements TemplateSubmitter {

	/**
	 * {@inheritDoc}
	 */
	public function available(): bool {
		return false;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $name    Template name.
	 * @param string $content Template text.
	 */
	public function submit( string $name, string $content ): SubmitResult {
		return new SubmitResult( false, '', __( 'Sending templates for review from the plugin is not available yet.', 'povikvane-for-woocommerce' ) );
	}
}
