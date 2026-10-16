<?php
/**
 * Settings for promotional messages.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Marketing;

use Povikvane\WooCommerce\Messages\LegacyPlaceholders;

use DateTimeZone;

defined( 'ABSPATH' ) || exit;

/**
 * Everything is off until the shop owner switches promotional messages on.
 */
final class MarketingSettings {

	public const OPTION = 'povikvane_marketing';

	private const DEFAULTS = array(
		'enabled'           => false,
		'consent_text'      => '',
		'quiet_open'        => 9,
		'quiet_close'       => 20,
		'cap_max'           => 1,
		'cap_days'          => 7,
		'followup_enabled'  => false,
		'followup_days'     => 7,
		'followup_template' => '',
	);

	/**
	 * Constructor.
	 *
	 * @param string $locale Site locale, used to pick default wording.
	 */
	public function __construct( private readonly string $locale ) {}

	/**
	 * All settings with defaults applied.
	 *
	 * @return array{enabled:bool,consent_text:string,quiet_open:int,quiet_close:int,cap_max:int,cap_days:int,followup_enabled:bool,followup_days:int,followup_template:string}
	 */
	public function all(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$value  = static fn( string $key ): mixed => $stored[ $key ] ?? self::DEFAULTS[ $key ];

		return array(
			'enabled'           => (bool) $value( 'enabled' ),
			'consent_text'      => (string) $value( 'consent_text' ),
			'quiet_open'        => self::clamp( (int) $value( 'quiet_open' ), 0, 23 ),
			'quiet_close'       => self::clamp( (int) $value( 'quiet_close' ), 0, 23 ),
			'cap_max'           => self::clamp( (int) $value( 'cap_max' ), 0, 100 ),
			'cap_days'          => self::clamp( (int) $value( 'cap_days' ), 0, 365 ),
			'followup_enabled'  => (bool) $value( 'followup_enabled' ),
			'followup_days'     => self::clamp( (int) $value( 'followup_days' ), 1, 60 ),
			'followup_template' => (string) $value( 'followup_template' ),
		);
	}

	/**
	 * Whether promotional messages are switched on.
	 */
	public function enabled(): bool {
		return $this->all()['enabled'];
	}

	/**
	 * Wording shown next to the checkout checkbox.
	 */
	public function consent_text(): string {
		$text = trim( $this->all()['consent_text'] );

		return '' !== $text ? $text : Defaults::consent_text( $this->locale );
	}

	/**
	 * Short hash of the wording, stored with each agreement as proof of what was shown.
	 */
	public function wording_hash(): string {
		return substr( hash( 'sha256', $this->consent_text() ), 0, 12 );
	}

	/**
	 * Hours when promotional messages may be sent.
	 *
	 * @param DateTimeZone $timezone Shop time zone.
	 */
	public function quiet_hours( DateTimeZone $timezone ): QuietHours {
		$all = $this->all();

		return new QuietHours( $all['quiet_open'], $all['quiet_close'], $timezone );
	}

	/**
	 * Maximum promotional messages per number in the period (0 = no limit).
	 */
	public function cap_max(): int {
		return $this->all()['cap_max'];
	}

	/**
	 * Length of the frequency period in days.
	 */
	public function cap_days(): int {
		return $this->all()['cap_days'];
	}

	/**
	 * The review-request settings.
	 */
	public function followup(): FollowupConfig {
		$all      = $this->all();
		$template = LegacyPlaceholders::strip( $all['followup_template'] );

		return new FollowupConfig(
			$all['enabled'] && $all['followup_enabled'],
			$all['followup_days'],
			'' !== $template ? $template : Defaults::followup_template( $this->locale ),
			Defaults::optout_label( $this->locale )
		);
	}

	/**
	 * Keep numbers inside their allowed range.
	 *
	 * @param int $value Value.
	 * @param int $min   Smallest allowed.
	 * @param int $max   Largest allowed.
	 */
	public static function clamp( int $value, int $min, int $max ): int {
		return max( $min, min( $max, $value ) );
	}
}
