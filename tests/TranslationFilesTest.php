<?php
/**
 * The invariants the shipped translation files rest on.
 *
 * languages/ ships in the release zip, so a PO that has drifted from the POT, a
 * MO that no longer matches its PO, or a translation that dropped a printf
 * placeholder are all things a user sees and the rest of the suite does not.
 * None of them fail loudly: gettext falls back to English on a stale entry, and
 * a lost placeholder only blows up when that particular sprintf() runs.
 *
 * Regenerate with:
 *   php tools/make-pot.php
 *   php tools/make-po.php es_ES
 *   php tools/make-mo.php es_ES
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../tools/lib-po.php';

class TranslationFilesTest extends TestCase {

	private const LANGUAGES = __DIR__ . '/../languages';

	/** Locales that ship with the plugin. */
	private const LOCALES = array( 'es_ES' );

	/**
	 * The POT parses and is not empty.
	 */
	public function test_pot_exists_and_parses(): void {
		$pot = $this->pot();

		$this->assertNotEmpty( $pot['entries'], 'languages/karmcp.pot has no entries.' );
		$this->assertSame( 'karmcp', $pot['headers']['X-Domain'] ?? '', 'The POT declares the wrong text domain.' );
	}

	/**
	 * Every string the agent reads is left in English on purpose.
	 *
	 * This is the decision the POT records in a comment; without a test, the
	 * next translator fills them in and the MCP tool schemas silently change
	 * language.
	 *
	 * @dataProvider locales
	 *
	 * @param string $locale WordPress locale.
	 */
	public function test_agent_facing_strings_are_not_translated( string $locale ): void {
		$translated = array();

		foreach ( $this->po( $locale )['entries'] as $entry ) {
			if ( karmcp_po_is_agent_facing( $entry ) && karmcp_po_is_translated( $entry ) ) {
				$translated[] = $entry['msgid'];
			}
		}

		$this->assertSame(
			array(),
			$translated,
			"These are MCP tool schema strings the AI agent reads, not UI a person sees. They stay in English:\n- "
				. implode( "\n- ", array_slice( $translated, 0, 10 ) )
		);
	}

	/**
	 * Everything a person can see is translated.
	 *
	 * @dataProvider locales
	 *
	 * @param string $locale WordPress locale.
	 */
	public function test_human_facing_strings_are_all_translated( string $locale ): void {
		$missing = array();

		foreach ( $this->po( $locale )['entries'] as $entry ) {
			if ( ! karmcp_po_is_agent_facing( $entry ) && ! karmcp_po_is_translated( $entry ) ) {
				$missing[] = $entry['msgid'];
			}
		}

		$this->assertSame(
			array(),
			$missing,
			count( $missing ) . " untranslated user-facing strings in $locale, first few:\n- "
				. implode( "\n- ", array_slice( $missing, 0, 10 ) )
		);
	}

	/**
	 * The PO holds exactly the strings the POT does.
	 *
	 * An entry the POT dropped is a translation of code that no longer exists;
	 * one the PO is missing is a string that will render in English.
	 *
	 * @dataProvider locales
	 *
	 * @param string $locale WordPress locale.
	 */
	public function test_po_matches_the_pot( string $locale ): void {
		$pot = array_keys( $this->pot()['entries'] );
		$po  = array_keys( $this->po( $locale )['entries'] );

		sort( $pot, SORT_STRING );
		sort( $po, SORT_STRING );

		$this->assertSame(
			$pot,
			$po,
			"languages/karmcp-$locale.po has drifted from the POT. Run: php tools/make-pot.php && php tools/make-po.php $locale"
		);
	}

	/**
	 * Every translation carries the same printf placeholders as its original.
	 *
	 * A dropped %s makes sprintf() emit the wrong argument, or none; an invented
	 * one makes it read past the end of the argument list.
	 *
	 * @dataProvider locales
	 *
	 * @param string $locale WordPress locale.
	 */
	public function test_placeholders_survive_translation( string $locale ): void {
		$broken = array();

		foreach ( $this->po( $locale )['entries'] as $entry ) {
			if ( ! karmcp_po_is_translated( $entry ) ) {
				continue;
			}

			$forms = is_array( $entry['msgstr'] ) ? $entry['msgstr'] : array( $entry['msgstr'] );
			$want  = array( $entry['msgid'], $entry['plural'] ?? $entry['msgid'] );

			foreach ( array_values( $forms ) as $index => $form ) {
				$source = $want[ $index ] ?? $entry['msgid'];
				if ( $this->placeholders( $source ) !== $this->placeholders( (string) $form ) ) {
					$broken[] = $entry['msgid'] . ' => ' . $form;
				}
			}
		}

		$this->assertSame(
			array(),
			$broken,
			"Placeholder mismatch in $locale:\n- " . implode( "\n- ", array_slice( $broken, 0, 10 ) )
		);
	}

	/**
	 * A plural entry is filled in for every form its locale declares.
	 *
	 * Half a plural is worse than none: WordPress returns the English original
	 * for the missing form, so the string flips language on the count.
	 *
	 * @dataProvider locales
	 *
	 * @param string $locale WordPress locale.
	 */
	public function test_plurals_have_every_form( string $locale ): void {
		$po = $this->po( $locale );

		$this->assertMatchesRegularExpression(
			'/^nplurals=(\d+);/',
			$po['headers']['Plural-Forms'] ?? '',
			"languages/karmcp-$locale.po declares no Plural-Forms."
		);
		preg_match( '/^nplurals=(\d+);/', $po['headers']['Plural-Forms'], $matches );
		$expected = (int) $matches[1];

		$partial = array();
		foreach ( $po['entries'] as $entry ) {
			if ( null === $entry['plural'] || ! is_array( $entry['msgstr'] ) ) {
				continue;
			}
			$filled = array_filter( $entry['msgstr'], static fn( $form ): bool => '' !== trim( (string) $form ) );
			if ( array() !== $filled && count( $filled ) !== $expected ) {
				$partial[] = $entry['msgid'];
			}
		}

		$this->assertSame( array(), $partial, "Half-filled plurals in $locale:\n- " . implode( "\n- ", $partial ) );
	}

	/**
	 * The MO is the compiled form of the PO sitting next to it.
	 *
	 * WordPress loads the MO, never the PO, so an edit to the PO that was not
	 * recompiled changes nothing on screen — the failure mode is a translation
	 * that "did not apply" for no visible reason.
	 *
	 * @dataProvider locales
	 *
	 * @param string $locale WordPress locale.
	 */
	public function test_mo_is_compiled_from_the_po( string $locale ): void {
		$path = self::LANGUAGES . "/karmcp-$locale.mo";
		$this->assertFileExists( $path, "Run: php tools/make-mo.php $locale" );

		$mo = $this->mo( $path );

		$expected = array();
		foreach ( $this->po( $locale )['entries'] as $entry ) {
			if ( ! karmcp_po_is_translated( $entry ) ) {
				continue;
			}
			if ( null !== $entry['plural'] ) {
				$key            = ( null === $entry['context'] ? '' : $entry['context'] . "\4" ) . $entry['msgid'] . "\0" . $entry['plural'];
				$expected[ $key ] = implode( "\0", array_values( (array) $entry['msgstr'] ) );
				continue;
			}
			$value = is_array( $entry['msgstr'] ) ? ( $entry['msgstr'][0] ?? '' ) : $entry['msgstr'];
			$expected[ karmcp_po_key( $entry['msgid'], $entry['context'] ) ] = (string) $value;
		}

		unset( $mo[''] ); // The header entry; its content is asserted separately.

		$this->assertSame(
			count( $expected ),
			count( $mo ),
			"languages/karmcp-$locale.mo is out of date. Run: php tools/make-mo.php $locale"
		);
		foreach ( $expected as $key => $value ) {
			$this->assertArrayHasKey( $key, $mo, 'Missing from the MO: ' . str_replace( "\0", ' | ', $key ) );
			$this->assertSame( $value, $mo[ $key ], 'Wrong translation in the MO for: ' . str_replace( "\0", ' | ', $key ) );
		}
	}

	/**
	 * The MO's own header carries the locale metadata WordPress reads.
	 *
	 * Plural-Forms lives only here at runtime; without it every plural falls
	 * back to the English rule.
	 *
	 * @dataProvider locales
	 *
	 * @param string $locale WordPress locale.
	 */
	public function test_mo_header_declares_the_locale( string $locale ): void {
		$mo = $this->mo( self::LANGUAGES . "/karmcp-$locale.mo" );

		$this->assertArrayHasKey( '', $mo, 'The MO has no header entry.' );
		$headers = karmcp_po_parse_headers( $mo[''] );

		$this->assertSame( $locale, $headers['Language'] ?? '' );
		$this->assertSame( 'text/plain; charset=UTF-8', $headers['Content-Type'] ?? '' );
		$this->assertStringStartsWith( 'nplurals=', $headers['Plural-Forms'] ?? '' );
	}

	/**
	 * Locales under test.
	 *
	 * @return array<string, string[]>
	 */
	public static function locales(): array {
		$cases = array();
		foreach ( self::LOCALES as $locale ) {
			$cases[ $locale ] = array( $locale );
		}
		return $cases;
	}

	/**
	 * Parses the POT once per test run.
	 *
	 * @return array{headers: array<string, string>, entries: array<string, array>}
	 */
	private function pot(): array {
		static $parsed = null;
		if ( null === $parsed ) {
			$parsed = karmcp_po_parse( (string) file_get_contents( self::LANGUAGES . '/karmcp.pot' ) );
		}
		return $parsed;
	}

	/**
	 * Parses one locale's PO once per test run.
	 *
	 * @param string $locale WordPress locale.
	 * @return array{headers: array<string, string>, entries: array<string, array>}
	 */
	private function po( string $locale ): array {
		static $parsed = array();
		if ( ! isset( $parsed[ $locale ] ) ) {
			$path = self::LANGUAGES . "/karmcp-$locale.po";
			$this->assertFileExists( $path );
			$parsed[ $locale ] = karmcp_po_parse( (string) file_get_contents( $path ) );
		}
		return $parsed[ $locale ];
	}

	/**
	 * Reads a compiled MO back into original => translation.
	 *
	 * Deliberately a second implementation of the format rather than a call
	 * into the writer: a shared helper would agree with itself about a bug.
	 *
	 * @param string $path Absolute path to the .mo file.
	 * @return array<string, string>
	 */
	private function mo( string $path ): array {
		$data = (string) file_get_contents( $path );

		$this->assertGreaterThanOrEqual( 28, strlen( $data ), 'The MO is too short to hold a header.' );
		$magic = unpack( 'V', substr( $data, 0, 4 ) )[1];
		$this->assertSame( 0x950412de, $magic, 'The MO does not start with the little-endian gettext magic.' );

		$header = unpack( 'Vrevision/Vcount/Voriginals/Vtranslations', substr( $data, 4, 16 ) );
		$this->assertSame( 0, $header['revision'], 'Unexpected MO format revision.' );

		$read = static function ( int $table, int $index ) use ( $data ): string {
			$entry = unpack( 'Vlength/Voffset', substr( $data, $table + ( $index * 8 ), 8 ) );
			return substr( $data, $entry['offset'], $entry['length'] );
		};

		$pairs = array();
		$keys  = array();
		for ( $i = 0; $i < $header['count']; $i++ ) {
			$original           = $read( $header['originals'], $i );
			$keys[]             = $original;
			$pairs[ $original ] = $read( $header['translations'], $i );
		}

		$sorted = $keys;
		sort( $sorted, SORT_STRING );
		$this->assertSame( $sorted, $keys, 'MO entries are not sorted by original; binary lookup would miss them.' );

		return $pairs;
	}

	/**
	 * The printf placeholders in a string, sorted.
	 *
	 * @param string $text Any translatable string.
	 * @return string[]
	 */
	private function placeholders( string $text ): array {
		preg_match_all( '/%(?:\d+\$)?[+-]?(?:[ 0]|\'.)?-?\d*(?:\.\d+)?[bcdeEfFgGosuxX%]/', $text, $matches );
		$found = $matches[0];
		sort( $found, SORT_STRING );
		return $found;
	}
}
