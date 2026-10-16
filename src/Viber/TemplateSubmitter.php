<?php
/**
 * Sends a template to Povikvane for review.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Viber;

defined( 'ABSPATH' ) || exit;

/**
 * The part of the Povikvane API that creates Viber templates. It is behind an
 * interface because the endpoint is not live yet: until it is, the screens say so
 * instead of offering a button that cannot work.
 */
interface TemplateSubmitter {

	/**
	 * Whether the account can send templates for review from the plugin.
	 */
	public function available(): bool;

	/**
	 * Send one template for review.
	 *
	 * @param string $name    Template name, 48 characters at most.
	 * @param string $content Template text with {customFieldN} variables.
	 * @return SubmitResult
	 */
	public function submit( string $name, string $content ): SubmitResult;
}
