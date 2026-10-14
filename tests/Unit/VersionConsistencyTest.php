<?php
/**
 * Guards against the plugin version drifting between files.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Keeps the plugin header, VERSION constant and readme.txt in step.
 */
final class VersionConsistencyTest extends TestCase {

	/**
	 * Read a plugin file as a string.
	 *
	 * @param string $relative Path relative to the plugin root.
	 */
	private function read( string $relative ): string {
		$contents = file_get_contents( dirname( __DIR__, 2 ) . '/' . $relative );
		$this->assertIsString( $contents, "Could not read {$relative}" );

		return $contents;
	}

	/**
	 * Header version, VERSION constant and readme stable tag must agree.
	 */
	public function test_header_constant_and_readme_versions_match(): void {
		$main   = $this->read( 'povikvane-for-woocommerce.php' );
		$readme = $this->read( 'readme.txt' );

		$this->assertSame( 1, preg_match( '/^ \* Version:\s+(\S+)/m', $main, $header ), 'Plugin header version missing' );
		$this->assertSame( 1, preg_match( "/^const VERSION\s+=\s+'([^']+)';/m", $main, $constant ), 'VERSION constant missing' );
		$this->assertSame( 1, preg_match( '/^Stable tag:\s+(\S+)/m', $readme, $stable ), 'readme.txt stable tag missing' );

		$this->assertSame( $header[1], $constant[1], 'Header version differs from VERSION constant' );
		$this->assertSame( $header[1], $stable[1], 'Header version differs from readme.txt stable tag' );
	}

	/**
	 * Minimum WordPress and PHP versions must agree between header and readme.
	 */
	public function test_minimum_requirements_match_between_header_and_readme(): void {
		$main   = $this->read( 'povikvane-for-woocommerce.php' );
		$readme = $this->read( 'readme.txt' );

		foreach ( array( 'Requires at least', 'Requires PHP' ) as $field ) {
			$this->assertSame( 1, preg_match( '/^ \* ' . preg_quote( $field, '/' ) . ':\s+(\S+)/m', $main, $in_header ), "{$field} missing in header" );
			$this->assertSame( 1, preg_match( '/^' . preg_quote( $field, '/' ) . ':\s+(\S+)/m', $readme, $in_readme ), "{$field} missing in readme.txt" );
			$this->assertSame( $in_header[1], $in_readme[1], "{$field} differs between header and readme.txt" );
		}
	}
}
