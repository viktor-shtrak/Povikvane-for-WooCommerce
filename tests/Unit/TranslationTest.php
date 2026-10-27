<?php
/**
 * Tests that translations are complete, correct and current.
 *
 * @package Povikvane\WooCommerce
 */

declare( strict_types=1 );

namespace Povikvane\WooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Povikvane\WooCommerce\Tests\Support\MoFile;

/**
 * The Bulgarian translation must cover every string, keep every placeholder, and
 * the template and compiled file must match the source code.
 */
final class TranslationTest extends TestCase {

	private const FUNCTIONS = array( '__', '_e', 'esc_html__', 'esc_attr__', 'esc_html_e', 'esc_attr_e', '_n', '_x' );

	/**
	 * Path inside the plugin.
	 *
	 * @param string $relative Relative path.
	 */
	private function path( string $relative ): string {
		return dirname( __DIR__, 2 ) . '/' . $relative;
	}

	/**
	 * Decode a quoted PO string (the part between the quotes, possibly several lines).
	 *
	 * @param string $raw Raw PO text.
	 */
	private function unquote( string $raw ): string {
		preg_match_all( '/"((?:[^"\\\\]|\\\\.)*)"/', $raw, $parts );

		return stripcslashes( implode( '', $parts[1] ) );
	}

	/**
	 * Parse a PO or POT file.
	 *
	 * @param string $file Path.
	 * @return array<int,array{id:string,ctx:string,plural:?string,str:array<int,string>,fuzzy:bool}>
	 */
	private function parse_po( string $file ): array {
		$content = (string) file_get_contents( $file );
		$entries = array();

		foreach ( preg_split( '/\n\n+/', trim( $content ) ) as $block ) {
			if ( ! preg_match( '/^msgid ((?:".*"\n?)+)/m', $block, $id ) || '' === $this->unquote( $id[1] ) ) {
				continue; // The header.
			}

			$plural = preg_match( '/^msgid_plural ((?:".*"\n?)+)/m', $block, $pl ) ? $this->unquote( $pl[1] ) : null;
			$str    = array();

			if ( preg_match_all( '/^msgstr(?:\[(\d+)\])? ((?:".*"\n?)+)/m', $block, $matches, PREG_SET_ORDER ) ) {
				foreach ( $matches as $match ) {
					$str[ '' === $match[1] ? 0 : (int) $match[1] ] = $this->unquote( $match[2] );
				}
			}

			$entries[] = array(
				'id'     => $this->unquote( $id[1] ),
				'ctx'    => preg_match( '/^msgctxt ((?:".*"\n?)+)/m', $block, $ctx ) ? $this->unquote( $ctx[1] ) : '',
				'plural' => $plural,
				'str'    => $str,
				'fuzzy'  => (bool) preg_match( '/^#,.*\bfuzzy\b/m', $block ),
			);
		}

		return $entries;
	}

	/**
	 * Placeholders in a string, sorted, so two strings can be compared.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	private function placeholders( string $text ): array {
		preg_match_all( '/%(?:\d+\$)?[sdf]|\{[a-z_]+\}/', $text, $found );
		sort( $found[0] );

		return $found[0];
	}

	/**
	 * Every English string in the code is in the template.
	 */
	public function test_template_contains_every_string_in_the_code(): void {
		$entries  = $this->parse_po( $this->path( 'languages/povikvane-for-woocommerce.pot' ) );
		$template = array_merge( array_column( $entries, 'id' ), array_filter( array_column( $entries, 'plural' ) ) );
		$missing  = array();

		foreach ( $this->source_strings() as $string ) {
			if ( ! in_array( $string, $template, true ) ) {
				$missing[] = $string;
			}
		}

		$this->assertSame( array(), $missing, 'The template is out of date. Run bin/i18n.sh and translate the new strings.' );
	}

	/**
	 * Every template string has a Bulgarian translation, and none is left fuzzy.
	 */
	public function test_bulgarian_translation_is_complete(): void {
		$template   = $this->parse_po( $this->path( 'languages/povikvane-for-woocommerce.pot' ) );
		$bulgarian  = $this->parse_po( $this->path( 'languages/povikvane-for-woocommerce-bg_BG.po' ) );
		$translated = array();

		foreach ( $bulgarian as $entry ) {
			$translated[ $entry['id'] ] = $entry;
			$this->assertFalse( $entry['fuzzy'], 'Fuzzy translation: ' . $entry['id'] );
		}

		foreach ( $template as $entry ) {
			$this->assertArrayHasKey( $entry['id'], $translated, 'Not translated: ' . $entry['id'] );

			foreach ( $translated[ $entry['id'] ]['str'] as $index => $text ) {
				$this->assertNotSame( '', trim( $text ), 'Empty translation (form ' . $index . '): ' . $entry['id'] );
			}

			$this->assertCount( null === $entry['plural'] ? 1 : 2, $translated[ $entry['id'] ]['str'], 'Wrong number of plural forms: ' . $entry['id'] );
		}
	}

	/**
	 * A translation keeps exactly the placeholders of the English text.
	 */
	public function test_translations_keep_their_placeholders(): void {
		foreach ( $this->parse_po( $this->path( 'languages/povikvane-for-woocommerce-bg_BG.po' ) ) as $entry ) {
			$expected = $this->placeholders( $entry['id'] );

			foreach ( $entry['str'] as $index => $text ) {
				// The singular form of a plural may drop "%d" ("one message"), so only forms with a count are compared.
				if ( null !== $entry['plural'] && 0 === $index && ! str_contains( $text, '%d' ) ) {
					continue;
				}

				$this->assertSame( $expected, $this->placeholders( $text ), 'Placeholders differ in: ' . $entry['id'] );
			}
		}
	}

	/**
	 * The compiled file matches the .po (nobody forgot to run bin/i18n.sh).
	 */
	public function test_compiled_file_matches_the_po(): void {
		$compiled = MoFile::read( $this->path( 'languages/povikvane-for-woocommerce-bg_BG.mo' ) );

		foreach ( $this->parse_po( $this->path( 'languages/povikvane-for-woocommerce-bg_BG.po' ) ) as $entry ) {
			$key = null === $entry['plural'] ? $entry['id'] : $entry['id'] . "\0" . $entry['plural'];
			$key = '' === $entry['ctx'] ? $key : $entry['ctx'] . "\4" . $key;

			$this->assertArrayHasKey( $key, $compiled, 'Missing from the .mo: ' . $entry['id'] );
			$this->assertSame( implode( "\0", $entry['str'] ), $compiled[ $key ], 'The .mo is out of date for: ' . $entry['id'] );
		}
	}

	/**
	 * A few translations read as expected, as a sanity check of the whole chain.
	 */
	public function test_known_translations(): void {
		$compiled = MoFile::read( $this->path( 'languages/povikvane-for-woocommerce-bg_BG.mo' ) );

		$this->assertSame( 'Запази промените', $compiled['Save changes'] );
		$this->assertSame( 'Общ преглед', $compiled['Overview'] );
		$this->assertSame( 'Език', $compiled['Language'] );
		$this->assertStringContainsString( 'nplurals=2', $compiled[''] );
		$this->assertStringContainsString( 'Language: bg_BG', $compiled[''] );
	}

	/**
	 * Literal strings passed to the translation functions anywhere in the plugin.
	 *
	 * @return string[]
	 */
	private function source_strings(): array {
		$files   = array( $this->path( 'povikvane-for-woocommerce.php' ), $this->path( 'uninstall.php' ) );
		$strings = array();

		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->path( 'src' ), \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$files[] = $file->getPathname();
			}
		}

		foreach ( $files as $file ) {
			$tokens = token_get_all( (string) file_get_contents( $file ) );
			$count  = count( $tokens );

			for ( $i = 0; $i < $count; $i++ ) {
				if ( ! is_array( $tokens[ $i ] ) || T_STRING !== $tokens[ $i ][0] || ! in_array( $tokens[ $i ][1], self::FUNCTIONS, true ) ) {
					continue;
				}

				$arguments = 0;
				$depth     = 0;

				for ( $j = $i + 1; $j < $count; $j++ ) {
					$token = $tokens[ $j ];

					if ( '(' === $token ) {
						++$depth;
						continue;
					}

					if ( ')' === $token && 0 === --$depth ) {
						break;
					}

					if ( 1 === $depth && is_array( $token ) && T_CONSTANT_ENCAPSED_STRING === $token[0] && $arguments < ( '_n' === $tokens[ $i ][1] ? 2 : 1 ) ) {
						$strings[] = $this->literal( $token[1] );
						++$arguments;
					}
				}
			}
		}

		return array_values( array_unique( $strings ) );
	}

	/**
	 * The value of a PHP string literal.
	 *
	 * @param string $source Source text including the quotes.
	 */
	private function literal( string $source ): string {
		$body = substr( $source, 1, -1 );

		return "'" === $source[0] ? str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), $body ) : stripcslashes( $body );
	}
}
