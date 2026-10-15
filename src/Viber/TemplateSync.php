<?php
/**
 * Fetches the template list from the API.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Viber;

use Povikvane\WooCommerce\Api\Gateway;
use Povikvane\WooCommerce\Api\Outcome;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the local template list current. A failed fetch keeps what we have; an
 * endpoint that is switched off empties the list, so Viber is not offered.
 */
final class TemplateSync {

	/**
	 * Constructor.
	 *
	 * @param Gateway         $gateway API gateway.
	 * @param TemplateCatalog $catalog Local list.
	 */
	public function __construct(
		private readonly Gateway $gateway,
		private readonly TemplateCatalog $catalog
	) {}

	/**
	 * Fetch and store the list.
	 *
	 * @param int $now Current time.
	 * @return bool Whether the list was updated.
	 */
	public function refresh( int $now ): bool {
		$result = $this->gateway->templates();

		if ( Outcome::NotFound === $result->outcome ) {
			$this->catalog->replace( array(), $now );
			return true;
		}

		if ( Outcome::Success !== $result->outcome ) {
			return false;
		}

		$templates = array();

		foreach ( $result->data as $item ) {
			$template = ViberTemplate::from_api( $item );

			if ( null !== $template ) {
				$templates[] = $template;
			}
		}

		$this->catalog->replace( $templates, $now );

		return true;
	}

	/**
	 * Refresh when the list is older than a limit.
	 *
	 * @param int $now         Current time.
	 * @param int $max_seconds Oldest acceptable age.
	 */
	public function refresh_if_stale( int $now, int $max_seconds ): bool {
		return $now - $this->catalog->fetched_at() >= $max_seconds ? $this->refresh( $now ) : false;
	}
}
