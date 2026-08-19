<?php
/**
 * Compiles a PO file into the binary MO that WordPress actually loads.
 *
 * Development tool. Does what `msgfmt` does, without needing GNU gettext
 * installed. Untranslated entries are skipped, which is what makes gettext fall
 * back to the English original.
 *
 * Usage: php tools/make-mo.php es_ES
 *
 * @package KarMCP
 * @since   1.27.0
 */

declare( strict_types = 1 );

require_once __DIR__ . '/lib-po.php';

$karmcp_mo_locale = $argv[1] ?? '';
if ( ! preg_match( '/^[a-z]{2,3}(_[A-Z]{2})?(_[a-z]+)?$/', $karmcp_mo_locale ) ) {
	fwrite( STDERR, "Usage: php tools/make-mo.php <locale>   e.g. es_ES\n" );
	exit( 1 );
}

$karmcp_mo_root   = dirname( __DIR__ );
$karmcp_mo_source = $karmcp_mo_root . '/languages/karmcp-' . $karmcp_mo_locale . '.po';
$karmcp_mo_dest   = $karmcp_mo_root . '/languages/karmcp-' . $karmcp_mo_locale . '.mo';

if ( ! is_readable( $karmcp_mo_source ) ) {
	fwrite( STDERR, 'Missing languages/karmcp-' . $karmcp_mo_locale . ".po\n" );
	exit( 1 );
}

$karmcp_mo_parsed = karmcp_po_parse( (string) file_get_contents( $karmcp_mo_source ) );

// The header entry travels in the MO as the empty msgid; without it WordPress
// cannot read Plural-Forms and every plural falls back to the English rule.
$karmcp_mo_pairs = array( '' => karmcp_mo_header_blob( $karmcp_mo_parsed['headers'] ) );

foreach ( $karmcp_mo_parsed['entries'] as $entry ) {
	if ( ! karmcp_po_is_translated( $entry ) ) {
		continue;
	}
	if ( in_array( 'fuzzy', $entry['flags'], true ) ) {
		continue;
	}

	$key = karmcp_po_key( $entry['msgid'], $entry['context'] );
	if ( null !== $entry['plural'] ) {
		// Plurals key on "singular\0plural" and pack their forms with \0.
		$key   = ( null === $entry['context'] ? '' : $entry['context'] . "\4" ) . $entry['msgid'] . "\0" . $entry['plural'];
		$value = implode( "\0", array_values( (array) $entry['msgstr'] ) );
	} else {
		$value = is_array( $entry['msgstr'] ) ? ( $entry['msgstr'][0] ?? '' ) : $entry['msgstr'];
	}
	$karmcp_mo_pairs[ $key ] = (string) $value;
}

// Entries must be sorted by msgid: the hashless binary search WordPress falls
// back to assumes it.
ksort( $karmcp_mo_pairs, SORT_STRING );

file_put_contents( $karmcp_mo_dest, karmcp_mo_build( $karmcp_mo_pairs ) );

printf(
	"Wrote languages/karmcp-%s.mo: %d translated strings (%s).\n",
	$karmcp_mo_locale,
	count( $karmcp_mo_pairs ) - 1,
	size_format_local( (int) filesize( $karmcp_mo_dest ) )
);

/**
 * Renders the header fields as the MO's empty-msgid value.
 *
 * @param array<string, string> $headers Header fields.
 */
function karmcp_mo_header_blob( array $headers ): string {
	$out = '';
	foreach ( $headers as $field => $value ) {
		$out .= $field . ': ' . $value . "\n";
	}
	return $out;
}

/**
 * Packs the translation table into the MO binary format.
 *
 * Layout is the one gettext documents: magic, revision, counts, the two string
 * tables' offsets, then the original and translated blobs. Written
 * little-endian, which is what the 0x950412de magic declares.
 *
 * @param array<string, string> $pairs Original => translation, sorted by key.
 */
function karmcp_mo_build( array $pairs ): string {
	$count = count( $pairs );

	// 7 header words, then two (length, offset) pairs per string.
	$originals_offset   = 28;
	$translations_offset = $originals_offset + ( $count * 8 );
	$hash_offset        = $translations_offset + ( $count * 8 );

	$originals_table    = '';
	$translations_table = '';
	$originals_blob     = '';
	$translations_blob  = '';

	// Both blobs start after the (empty) hash table.
	$originals_base    = $hash_offset;
	$translations_base = $originals_base;
	foreach ( $pairs as $original => $translation ) {
		$translations_base += strlen( (string) $original ) + 1;
	}

	$original_cursor    = 0;
	$translation_cursor = 0;
	foreach ( $pairs as $original => $translation ) {
		$original    = (string) $original;
		$translation = (string) $translation;

		$originals_table    .= pack( 'VV', strlen( $original ), $originals_base + $original_cursor );
		$translations_table .= pack( 'VV', strlen( $translation ), $translations_base + $translation_cursor );

		$originals_blob    .= $original . "\0";
		$translations_blob .= $translation . "\0";

		$original_cursor    += strlen( $original ) + 1;
		$translation_cursor += strlen( $translation ) + 1;
	}

	$header = pack(
		'VVVVVVV',
		0x950412de,          // Magic, little-endian.
		0,                   // Format revision.
		$count,              // Number of strings.
		$originals_offset,   // Offset of the originals table.
		$translations_offset, // Offset of the translations table.
		0,                   // Hash table size: 0, no hash table written.
		$hash_offset         // Offset of the hash table.
	);

	return $header . $originals_table . $translations_table . $originals_blob . $translations_blob;
}

/**
 * Human-readable byte size. WordPress's size_format() is not loaded here.
 *
 * @param int $bytes Size in bytes.
 */
function size_format_local( int $bytes ): string {
	if ( $bytes < 1024 ) {
		return $bytes . ' B';
	}
	if ( $bytes < 1024 * 1024 ) {
		return round( $bytes / 1024, 1 ) . ' KB';
	}
	return round( $bytes / ( 1024 * 1024 ), 1 ) . ' MB';
}
