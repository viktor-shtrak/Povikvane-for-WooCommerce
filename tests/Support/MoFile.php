<?php
/**
 * Minimal reader for compiled gettext (.mo) files.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Tests\Support;

use RuntimeException;

/**
 * Reads the translations WordPress would load, so tests can check them.
 */
final class MoFile {

	/**
	 * Read a .mo file into an array of original => translation. Plural forms are
	 * joined with a NUL byte on both sides, as in the file.
	 *
	 * @param string $path Path to the .mo file.
	 * @return array<string,string>
	 */
	public static function read( string $path ): array {
		$data = file_get_contents( $path );

		if ( false === $data || strlen( $data ) < 28 ) {
			throw new RuntimeException( "Cannot read $path" );
		}

		$magic = unpack( 'V', substr( $data, 0, 4 ) )[1];

		if ( 0x950412de !== $magic ) {
			throw new RuntimeException( 'Not a little-endian .mo file' );
		}

		$header = unpack( 'Vrevision/Vcount/Voriginals/Vtranslations', substr( $data, 4, 16 ) );
		$out    = array();

		for ( $i = 0; $i < $header['count']; $i++ ) {
			$original    = unpack( 'Vlength/Voffset', substr( $data, $header['originals'] + $i * 8, 8 ) );
			$translation = unpack( 'Vlength/Voffset', substr( $data, $header['translations'] + $i * 8, 8 ) );

			$out[ substr( $data, $original['offset'], $original['length'] ) ] = substr( $data, $translation['offset'], $translation['length'] );
		}

		return $out;
	}
}
