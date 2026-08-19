<?php
/**
 * Creates or updates a locale's PO file from languages/karmcp.pot.
 *
 * Development tool. Does what `msgmerge` does, without needing GNU gettext
 * installed: existing translations are kept, strings the POT no longer has are
 * dropped, and new ones arrive with an empty msgstr.
 *
 * Usage: php tools/make-po.php es_ES
 *
 * @package KarMCP
 * @since   1.27.0
 */

declare( strict_types = 1 );

require_once __DIR__ . '/lib-po.php';

$karmcp_po_locale = $argv[1] ?? '';
if ( ! preg_match( '/^[a-z]{2,3}(_[A-Z]{2})?(_[a-z]+)?$/', $karmcp_po_locale ) ) {
	fwrite( STDERR, "Usage: php tools/make-po.php <locale>   e.g. es_ES\n" );
	exit( 1 );
}

$karmcp_po_root = dirname( __DIR__ );
$karmcp_po_pot  = $karmcp_po_root . '/languages/karmcp.pot';
$karmcp_po_dest = $karmcp_po_root . '/languages/karmcp-' . $karmcp_po_locale . '.po';

if ( ! is_readable( $karmcp_po_pot ) ) {
	fwrite( STDERR, "Missing languages/karmcp.pot. Run: php tools/make-pot.php\n" );
	exit( 1 );
}

$karmcp_po_template = karmcp_po_parse( (string) file_get_contents( $karmcp_po_pot ) );
$karmcp_po_existing = is_readable( $karmcp_po_dest )
	? karmcp_po_parse( (string) file_get_contents( $karmcp_po_dest ) )
	: array( 'headers' => array(), 'entries' => array() );

$karmcp_po_kept    = 0;
$karmcp_po_added   = 0;
$karmcp_po_dropped = 0;

// Carry every existing translation over onto the fresh template entry, so the
// references and the extracted comments come from the POT (they are the ones
// that just got regenerated) while the msgstr comes from the translator.
foreach ( $karmcp_po_template['entries'] as $key => &$entry ) {
	$previous = $karmcp_po_existing['entries'][ $key ] ?? null;
	if ( null === $previous ) {
		++$karmcp_po_added;
		continue;
	}
	$entry['msgstr'] = $previous['msgstr'];
	$entry['flags']  = $previous['flags'];
	if ( karmcp_po_is_translated( $previous ) ) {
		++$karmcp_po_kept;
	}
}
unset( $entry );

foreach ( $karmcp_po_existing['entries'] as $key => $previous ) {
	if ( ! isset( $karmcp_po_template['entries'][ $key ] ) && karmcp_po_is_translated( $previous ) ) {
		++$karmcp_po_dropped;
	}
}

$karmcp_po_headers = karmcp_po_locale_headers(
	$karmcp_po_template['headers'],
	$karmcp_po_existing['headers'],
	$karmcp_po_locale
);

file_put_contents( $karmcp_po_dest, karmcp_po_render( $karmcp_po_headers, $karmcp_po_template['entries'] ) );

$karmcp_po_total       = count( $karmcp_po_template['entries'] );
$karmcp_po_translated  = 0;
$karmcp_po_agent_facing = 0;
foreach ( $karmcp_po_template['entries'] as $entry ) {
	if ( karmcp_po_is_translated( $entry ) ) {
		++$karmcp_po_translated;
	} elseif ( karmcp_po_is_agent_facing( $entry ) ) {
		++$karmcp_po_agent_facing;
	}
}
$karmcp_po_target = $karmcp_po_total - $karmcp_po_agent_facing;

printf(
	"Wrote languages/karmcp-%s.po\n  %d strings total, %d agent-facing (left in English on purpose)\n  %d of %d human-facing translated (%.1f%%)\n  kept %d, new %d, dropped %d\n",
	$karmcp_po_locale,
	$karmcp_po_total,
	$karmcp_po_agent_facing,
	$karmcp_po_translated,
	$karmcp_po_target,
	$karmcp_po_target > 0 ? ( $karmcp_po_translated / $karmcp_po_target ) * 100 : 0.0,
	$karmcp_po_kept,
	$karmcp_po_added,
	$karmcp_po_dropped
);

/**
 * Builds the header block for a locale PO.
 *
 * Takes the POT's headers as the base, keeps the translator's own metadata when
 * the file already existed, and forces the fields that describe the locale.
 *
 * @param array<string, string> $template Headers from the POT.
 * @param array<string, string> $existing Headers from the PO being updated.
 * @param string                $locale   WordPress locale, e.g. es_ES.
 * @return array<string, string>
 */
function karmcp_po_locale_headers( array $template, array $existing, string $locale ): array {
	$headers = $template;

	// Carry the translator's own metadata over, but not the POT's placeholders:
	// those are what the template ships with, and copying them back would pin
	// "FULL NAME <EMAIL@ADDRESS>" into the PO for good.
	foreach ( array( 'Last-Translator', 'Language-Team', 'PO-Revision-Date' ) as $field ) {
		$value = $existing[ $field ] ?? '';
		if ( '' === $value || $value === ( $template[ $field ] ?? null ) ) {
			continue;
		}
		$headers[ $field ] = $value;
	}

	$headers['Language']     = $locale;
	$headers['Plural-Forms'] = karmcp_po_plural_forms( $locale );
	$headers['X-Generator']  = 'tools/make-po.php';

	// Language belongs next to the other locale metadata, not appended at the
	// end where it lands when it is a new key.
	$order  = array(
		'Project-Id-Version',
		'Report-Msgid-Bugs-To',
		'POT-Creation-Date',
		'PO-Revision-Date',
		'Last-Translator',
		'Language-Team',
		'Language',
		'MIME-Version',
		'Content-Type',
		'Content-Transfer-Encoding',
		'Plural-Forms',
	);
	$sorted = array();
	foreach ( $order as $field ) {
		if ( isset( $headers[ $field ] ) ) {
			$sorted[ $field ] = $headers[ $field ];
		}
	}
	foreach ( $headers as $field => $value ) {
		if ( ! isset( $sorted[ $field ] ) ) {
			$sorted[ $field ] = $value;
		}
	}

	return $sorted;
}

/**
 * Plural-Forms expression for a locale.
 *
 * Only the languages this plugin actually ships need an entry; anything else
 * gets the two-form default, which the translator can correct by hand.
 *
 * @param string $locale WordPress locale.
 */
function karmcp_po_plural_forms( string $locale ): string {
	$language = strtok( $locale, '_' );

	return match ( $language ) {
		'ja', 'zh', 'ko', 'vi', 'th', 'id' => 'nplurals=1; plural=0;',
		'fr', 'pt'                         => 'nplurals=2; plural=(n > 1);',
		'ru', 'uk'                         => 'nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);',
		'pl'                               => 'nplurals=3; plural=(n==1 ? 0 : n%10>=2 && n%10<=4 && (n%100<12 || n%100>14) ? 1 : 2);',
		default                            => 'nplurals=2; plural=(n != 1);',
	};
}
