<?php
/**
 * Structural safety net: every class loads and every import resolves.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The plugin cannot be run inside WordPress in unit tests, so this catches the
 * cheap mistakes: a class in the wrong file, or an import with a typo.
 */
final class StructureTest extends TestCase {

	/**
	 * Files that extend a WordPress class and cannot load here.
	 */
	private const NEEDS_WORDPRESS = array( 'Admin/MessageListTable.php' );

	/**
	 * Source files relative to src/.
	 *
	 * @return array<string,array{string}>
	 */
	public static function source_files(): array {
		$root  = dirname( __DIR__, 2 ) . '/src';
		$files = array();

		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, RecursiveDirectoryIterator::SKIP_DOTS ) ) as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$relative           = substr( $file->getPathname(), strlen( $root ) + 1 );
				$files[ $relative ] = array( $relative );
			}
		}

		return $files;
	}

	/**
	 * Whether a name is a class, interface, trait or enum.
	 *
	 * @param string $name Fully qualified name.
	 */
	private function exists( string $name ): bool {
		return class_exists( $name ) || interface_exists( $name ) || enum_exists( $name ) || trait_exists( $name );
	}

	/**
	 * Each file defines the type its path says it does.
	 *
	 * @param string $relative Path relative to src/.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'source_files' )]
	public function test_file_defines_the_expected_type( string $relative ): void {
		if ( in_array( $relative, self::NEEDS_WORDPRESS, true ) ) {
			$this->markTestSkipped( 'Needs WordPress.' );
		}

		$name = 'Povikvane\\WooCommerce\\' . str_replace( array( '/', '.php' ), array( '\\', '' ), $relative );

		$this->assertTrue( $this->exists( $name ), "$relative should define $name" );
	}

	/**
	 * Every plugin import points at something that exists.
	 *
	 * @param string $relative Path relative to src/.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'source_files' )]
	public function test_imports_resolve( string $relative ): void {
		$code = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/' . $relative );

		preg_match_all( '/^use (Povikvane\\\\WooCommerce\\\\[A-Za-z\\\\]+);/m', $code, $matches );

		foreach ( $matches[1] as $import ) {
			if ( 'Povikvane\\WooCommerce\\Admin\\MessageListTable' === $import ) {
				continue;
			}

			$this->assertTrue( $this->exists( $import ), "$relative imports missing $import" );
		}

		$this->assertTrue( true );
	}
}
