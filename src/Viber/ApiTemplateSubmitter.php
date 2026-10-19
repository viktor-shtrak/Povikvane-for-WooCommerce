<?php
/**
 * Sends templates to Povikvane for review through the Public API.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Viber;

use Povikvane\WooCommerce\Api\ApiResult;
use Povikvane\WooCommerce\Api\Gateway;
use Povikvane\WooCommerce\Api\Outcome;

defined( 'ABSPATH' ) || exit;

/**
 * Template names are unique in a company, and an edited text is always a new
 * template, so a name that is taken gets a version suffix (v2, v3, ...).
 */
final class ApiTemplateSubmitter implements TemplateSubmitter {

	private const MAX_NAME     = 48;
	private const MAX_VERSIONS = 9;

	/**
	 * Constructor.
	 *
	 * @param Gateway $gateway Talks to the API.
	 * @param bool    $enabled Whether the account reports template management.
	 * @param string  $lang    "bg" or "en".
	 */
	public function __construct(
		private readonly Gateway $gateway,
		private readonly bool $enabled,
		private readonly string $lang
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
	 * @param string $name    Template name.
	 * @param string $content Template text.
	 */
	public function submit( string $name, string $content ): SubmitResult {
		if ( ! $this->enabled ) {
			return new SubmitResult( false, '', __( 'Sending templates for review from the plugin is not available on this account.', 'povikvane-for-woocommerce' ) );
		}

		for ( $version = 1; $version <= self::MAX_VERSIONS; $version++ ) {
			$result = $this->gateway->create_template( self::versioned( $name, $version ), $this->lang, $content );

			if ( Outcome::Success === $result->outcome ) {
				$id = $result->data['id'] ?? '';

				return new SubmitResult( true, is_string( $id ) ? $id : '' );
			}

			if ( 'template_name_duplicate' !== $result->error_code ) {
				return new SubmitResult( false, '', self::reason( $result ) );
			}
		}

		return new SubmitResult( false, '', __( 'There are already many templates with this name. Rename or delete old ones in Povikvane.', 'povikvane-for-woocommerce' ) );
	}

	/**
	 * The name for a version: the name itself, then "name v2" and so on, cut to fit.
	 *
	 * @param string $name    Name.
	 * @param int    $version Version, 1 for the plain name.
	 */
	public static function versioned( string $name, int $version ): string {
		$name = trim( $name );

		if ( $version <= 1 ) {
			return mb_substr( $name, 0, self::MAX_NAME );
		}

		$suffix = ' v' . $version;

		return rtrim( mb_substr( $name, 0, self::MAX_NAME - strlen( $suffix ) ) ) . $suffix;
	}

	/**
	 * Plain-language reason for a refusal.
	 *
	 * @param ApiResult $result Refused result.
	 */
	public static function reason( ApiResult $result ): string {
		return match ( $result->error_code ) {
			'template_limit_reached'       => __( 'Povikvane already has the most templates waiting for review that one account can have. Wait for them to be reviewed and try again.', 'povikvane-for-woocommerce' ),
			'template_rate_limited'        => __( 'The daily limit for new templates is used up. You can send more tomorrow.', 'povikvane-for-woocommerce' ),
			'template_variable_at_edge'    => __( 'A Viber message cannot start or end with a field. Put some words before and after it.', 'povikvane-for-woocommerce' ),
			'template_too_long'            => __( 'The message is too long for Viber.', 'povikvane-for-woocommerce' ),
			'template_too_many_variables'  => __( 'The message has too many fields for Viber.', 'povikvane-for-woocommerce' ),
			'template_dynamic_url'         => __( 'A Viber message cannot contain a field inside a link.', 'povikvane-for-woocommerce' ),
			'template_variable_not_allowed',
			'template_name_invalid',
			'template_lang_unsupported',
			'template_channel_unsupported' => __( 'Povikvane did not accept this message as a Viber template.', 'povikvane-for-woocommerce' ),
			'endpoint_disabled'            => __( 'Sending templates for review from the plugin is not available on this account.', 'povikvane-for-woocommerce' ),
			default                        => Outcome::AuthFailure === $result->outcome
				? __( 'Povikvane rejected the API key. Check your connection settings.', 'povikvane-for-woocommerce' )
				: __( 'Povikvane could not take the template right now. Try again in a moment.', 'povikvane-for-woocommerce' ),
		};
	}
}
