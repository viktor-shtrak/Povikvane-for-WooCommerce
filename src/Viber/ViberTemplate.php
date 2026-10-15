<?php
/**
 * A Viber template registered in the Povikvane account.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Viber;

defined( 'ABSPATH' ) || exit;

/**
 * Name, text with {{variables}}, approval state and billing class of a template.
 */
final class ViberTemplate {

	/**
	 * Constructor.
	 *
	 * @param string      $id           Template ID.
	 * @param string      $name         Template name.
	 * @param string      $content      Text with {{variable}} markers.
	 * @param string[]    $variables    Variable names.
	 * @param string      $status       "approved", "pending" or "rejected".
	 * @param string|null $message_type  "transactional", "marketing" or null.
	 * @param string|null $why_not       Carrier reason when it is not transactional.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $name,
		public readonly string $content,
		public readonly array $variables,
		public readonly string $status,
		public readonly ?string $message_type,
		public readonly ?string $why_not = null
	) {}

	/**
	 * Build from one item of the API list.
	 *
	 * @param mixed $item Item with "id" and "attributes".
	 */
	public static function from_api( mixed $item ): ?self {
		if ( ! is_array( $item ) || ! isset( $item['id'] ) || ! is_string( $item['id'] ) || ! is_array( $item['attributes'] ?? null ) ) {
			return null;
		}

		return self::from_array(
			array_merge(
				$item['attributes'],
				array( 'id' => $item['id'] )
			)
		);
	}

	/**
	 * Build from a stored or API-shaped row.
	 *
	 * @param array<mixed> $row Row.
	 */
	public static function from_array( array $row ): ?self {
		$id = isset( $row['id'] ) && is_string( $row['id'] ) ? $row['id'] : '';

		if ( '' === $id || ! isset( $row['content'] ) || ! is_string( $row['content'] ) ) {
			return null;
		}

		$variables = is_array( $row['variables'] ?? null ) ? array_values( array_filter( array_map( 'strval', $row['variables'] ), static fn( string $v ): bool => '' !== $v ) ) : array();
		$status    = $row['approval_status'] ?? $row['status'] ?? '';
		$type      = $row['message_type'] ?? null;
		$why       = $row['why_not_transactional'] ?? $row['why_not'] ?? null;

		return new self(
			$id,
			isset( $row['name'] ) && is_string( $row['name'] ) ? $row['name'] : $id,
			$row['content'],
			$variables,
			is_string( $status ) ? $status : '',
			is_string( $type ) ? $type : null,
			is_string( $why ) ? $why : null
		);
	}

	/**
	 * Row for storage.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'id'           => $this->id,
			'name'         => $this->name,
			'content'      => $this->content,
			'variables'    => $this->variables,
			'status'       => $this->status,
			'message_type' => $this->message_type,
			'why_not'      => $this->why_not,
		);
	}

	/**
	 * Whether the template is approved by Povikvane.
	 */
	public function is_approved(): bool {
		return 'approved' === $this->status;
	}

	/**
	 * Whether it is registered with the carrier as a transactional template.
	 */
	public function is_transactional(): bool {
		return $this->is_approved() && 'transactional' === $this->message_type;
	}

	/**
	 * Fill the template.
	 *
	 * @param array<string,string> $params Values keyed by variable name.
	 */
	public function render( array $params ): string {
		return trim(
			preg_replace_callback(
				'/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/',
				static fn( array $found ): string => $params[ $found[1] ] ?? '',
				$this->content
			) ?? $this->content
		);
	}
}
