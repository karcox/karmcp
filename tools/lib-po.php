<?php
/**
 * Shared PO/POT reading and writing for the translation tools.
 *
 * Development-only. Used by make-pot.php, make-po.php and make-mo.php so the
 * three agree on how an entry is keyed, quoted and considered translated.
 *
 * An entry is keyed exactly the way gettext keys it: context, then \4, then the
 * singular. Anything that changes that key silently loses translations on the
 * next merge.
 *
 * @package KarMCP
 * @since   1.27.0
 */

declare( strict_types = 1 );

/** Marker written by make-pot.php onto strings only the AI agent ever reads. */
const KARMCP_PO_AGENT_FACING_MARK = 'DO NOT TRANSLATE. Agent-facing:';

/**
 * Parses a PO or POT file.
 *
 * @param string $source File contents.
 * @return array{headers: array<string, string>, entries: array<string, array>}
 */
function karmcp_po_parse( string $source ): array {
	$headers = array();
	$entries = array();

	foreach ( preg_split( '/\R{2,}/', trim( $source ) ) ?: array() as $block ) {
		$entry = karmcp_po_parse_block( $block );
		if ( null === $entry ) {
			continue;
		}
		if ( '' === $entry['msgid'] && null === $entry['context'] ) {
			$headers = karmcp_po_parse_headers( is_array( $entry['msgstr'] ) ? ( $entry['msgstr'][0] ?? '' ) : $entry['msgstr'] );
			continue;
		}
		$entries[ karmcp_po_key( $entry['msgid'], $entry['context'] ) ] = $entry;
	}

	return array(
		'headers' => $headers,
		'entries' => $entries,
	);
}

/**
 * Parses one PO block into an entry, or null when it holds no msgid.
 *
 * @param string $block One entry, comments included.
 * @return array|null
 */
function karmcp_po_parse_block( string $block ): ?array {
	$entry = array(
		'msgid'     => null,
		'plural'    => null,
		'context'   => null,
		'msgstr'    => '',
		'refs'      => array(),
		'comments'  => array(),
		'flags'     => array(),
	);

	$field  = null;
	$plural = 0;

	foreach ( preg_split( '/\R/', $block ) ?: array() as $line ) {
		$line = trim( $line );
		if ( '' === $line ) {
			continue;
		}

		if ( str_starts_with( $line, '#.' ) ) {
			$entry['comments'][] = trim( substr( $line, 2 ) );
			continue;
		}
		if ( str_starts_with( $line, '#:' ) ) {
			$entry['refs'][] = trim( substr( $line, 2 ) );
			continue;
		}
		if ( str_starts_with( $line, '#,' ) ) {
			foreach ( explode( ',', substr( $line, 2 ) ) as $flag ) {
				$flag = trim( $flag );
				if ( '' !== $flag ) {
					$entry['flags'][] = $flag;
				}
			}
			continue;
		}
		if ( str_starts_with( $line, '#' ) ) {
			continue;
		}

		if ( preg_match( '/^msgctxt\s+(.*)$/', $line, $matches ) ) {
			$field            = 'context';
			$entry['context'] = karmcp_po_unquote( $matches[1] );
			continue;
		}
		if ( preg_match( '/^msgid\s+(.*)$/', $line, $matches ) ) {
			$field          = 'msgid';
			$entry['msgid'] = karmcp_po_unquote( $matches[1] );
			continue;
		}
		if ( preg_match( '/^msgid_plural\s+(.*)$/', $line, $matches ) ) {
			$field           = 'plural';
			$entry['plural'] = karmcp_po_unquote( $matches[1] );
			continue;
		}
		if ( preg_match( '/^msgstr\[(\d+)\]\s+(.*)$/', $line, $matches ) ) {
			$field  = 'msgstr_plural';
			$plural = (int) $matches[1];
			if ( ! is_array( $entry['msgstr'] ) ) {
				$entry['msgstr'] = array();
			}
			$entry['msgstr'][ $plural ] = karmcp_po_unquote( $matches[2] );
			continue;
		}
		if ( preg_match( '/^msgstr\s+(.*)$/', $line, $matches ) ) {
			$field           = 'msgstr';
			$entry['msgstr'] = karmcp_po_unquote( $matches[1] );
			continue;
		}

		// A bare "…" line continues whatever field came last.
		if ( str_starts_with( $line, '"' ) && null !== $field ) {
			$continuation = karmcp_po_unquote( $line );
			if ( 'msgstr_plural' === $field ) {
				$entry['msgstr'][ $plural ] .= $continuation;
			} elseif ( 'msgstr' === $field ) {
				$entry['msgstr'] .= $continuation;
			} elseif ( 'msgid' === $field ) {
				$entry['msgid'] .= $continuation;
			} elseif ( 'plural' === $field ) {
				$entry['plural'] .= $continuation;
			} elseif ( 'context' === $field ) {
				$entry['context'] .= $continuation;
			}
		}
	}

	return null === $entry['msgid'] ? null : $entry;
}

/**
 * Splits a header msgstr into its fields.
 *
 * @param string $blob The header entry's msgstr.
 * @return array<string, string>
 */
function karmcp_po_parse_headers( string $blob ): array {
	$headers = array();
	foreach ( preg_split( '/\R/', $blob ) ?: array() as $line ) {
		if ( preg_match( '/^([A-Za-z0-9-]+):\s*(.*)$/', trim( $line ), $matches ) ) {
			$headers[ $matches[1] ] = $matches[2];
		}
	}
	return $headers;
}

/**
 * The gettext lookup key for a string.
 *
 * @param string      $msgid   Singular.
 * @param string|null $context Context, when the call carried one.
 */
function karmcp_po_key( string $msgid, ?string $context ): string {
	return ( null === $context || '' === $context ) ? $msgid : $context . "\4" . $msgid;
}

/**
 * Whether an entry carries a real translation.
 *
 * A plural entry counts only when every form is filled: a half-filled plural
 * makes WordPress fall back to the English for the missing form, which reads
 * as a bug rather than as an untranslated string.
 *
 * @param array $entry Parsed entry.
 */
function karmcp_po_is_translated( array $entry ): bool {
	if ( is_array( $entry['msgstr'] ) ) {
		if ( array() === $entry['msgstr'] ) {
			return false;
		}
		foreach ( $entry['msgstr'] as $form ) {
			if ( '' === trim( (string) $form ) ) {
				return false;
			}
		}
		return true;
	}
	return '' !== trim( (string) $entry['msgstr'] );
}

/**
 * Whether an entry is one of the strings only the AI agent reads.
 *
 * @param array $entry Parsed entry.
 */
function karmcp_po_is_agent_facing( array $entry ): bool {
	foreach ( $entry['comments'] as $comment ) {
		if ( str_starts_with( $comment, KARMCP_PO_AGENT_FACING_MARK ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Renders headers and entries back to PO source.
 *
 * @param array<string, string> $headers Header fields, in order.
 * @param array<string, array>  $entries Entries keyed by karmcp_po_key().
 */
function karmcp_po_render( array $headers, array $entries ): string {
	$out  = "# Spanish translation for KarMCP.\n";
	$out .= '# Copyright (C) ' . gmdate( 'Y' ) . " karcox\n";
	$out .= "# This file is distributed under the GPL-2.0-or-later licence.\n";
	$out .= "msgid \"\"\n";
	$out .= "msgstr \"\"\n";
	foreach ( $headers as $field => $value ) {
		$out .= '"' . $field . ': ' . $value . '\n"' . "\n";
	}

	foreach ( $entries as $entry ) {
		$out .= "\n";
		foreach ( array_unique( $entry['comments'] ) as $comment ) {
			$out .= '#. ' . $comment . "\n";
		}
		foreach ( array_unique( $entry['refs'] ) as $reference ) {
			$out .= '#: ' . $reference . "\n";
		}
		if ( array() !== $entry['flags'] ) {
			$out .= '#, ' . implode( ', ', array_unique( $entry['flags'] ) ) . "\n";
		}
		if ( null !== $entry['context'] ) {
			$out .= 'msgctxt ' . karmcp_po_quote( $entry['context'] ) . "\n";
		}
		$out .= 'msgid ' . karmcp_po_quote( $entry['msgid'] ) . "\n";
		if ( null !== $entry['plural'] ) {
			$out .= 'msgid_plural ' . karmcp_po_quote( $entry['plural'] ) . "\n";
			$forms = is_array( $entry['msgstr'] ) ? $entry['msgstr'] : array( 0 => '', 1 => '' );
			for ( $i = 0; $i < 2; $i++ ) {
				$out .= 'msgstr[' . $i . '] ' . karmcp_po_quote( (string) ( $forms[ $i ] ?? '' ) ) . "\n";
			}
		} else {
			$value = is_array( $entry['msgstr'] ) ? ( $entry['msgstr'][0] ?? '' ) : $entry['msgstr'];
			$out  .= 'msgstr ' . karmcp_po_quote( (string) $value ) . "\n";
		}
	}

	return $out;
}

/**
 * Quotes a value for a PO file, splitting on newlines the way gettext does.
 *
 * @param string $value Raw string.
 */
function karmcp_po_quote( string $value ): string {
	$escape = static function ( string $text ): string {
		return str_replace(
			array( '\\', '"', "\t", "\r" ),
			array( '\\\\', '\\"', '\\t', '\\r' ),
			$text
		);
	};

	if ( ! str_contains( $value, "\n" ) ) {
		return '"' . $escape( $value ) . '"';
	}

	$lines = explode( "\n", $value );
	$last  = array_key_last( $lines );
	$out   = "\"\"\n";
	foreach ( $lines as $index => $line ) {
		if ( $index === $last && '' === $line ) {
			break;
		}
		$out .= '"' . $escape( $line ) . ( $index === $last ? '' : '\\n' ) . "\"\n";
	}
	return rtrim( $out, "\n" );
}

/**
 * Turns a quoted PO value into its string.
 *
 * @param string $literal One `"…"` token from a PO line.
 */
function karmcp_po_unquote( string $literal ): string {
	$literal = trim( $literal );
	if ( ! str_starts_with( $literal, '"' ) ) {
		return '';
	}
	$body = substr( $literal, 1, -1 );

	return (string) preg_replace_callback(
		'/\\\\(n|t|r|"|\\\\)/',
		static function ( array $matches ): string {
			return match ( $matches[1] ) {
				'n'     => "\n",
				't'     => "\t",
				'r'     => "\r",
				'"'     => '"',
				default => '\\',
			};
		},
		$body
	);
}
