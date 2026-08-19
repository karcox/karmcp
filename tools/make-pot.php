<?php
/**
 * POT generator for KarMCP.
 *
 * Development tool. Extracts every gettext call carrying the `karmcp` text
 * domain into languages/karmcp.pot, so translators have a template without
 * needing WP-CLI or the GNU gettext binaries installed.
 *
 * Usage: php tools/make-pot.php [--check]
 *
 * With --check it writes nothing and exits non-zero if the committed POT is
 * out of date, which is what CI wants.
 *
 * Parsing is done with token_get_all(), not regex: it resolves 'a' . 'b'
 * concatenation, both quote styles and their escapes, and never matches a
 * function name inside a comment or a string.
 *
 * @package KarMCP
 * @since   1.27.0
 */

declare( strict_types = 1 );

const KARMCP_POT_DOMAIN = 'karmcp';

/**
 * Gettext functions we understand.
 *
 * Each entry maps argument positions (0-based): where the singular lives, the
 * optional plural, the optional context, and the text domain. A call whose
 * domain argument is absent or is not our domain is skipped.
 */
const KARMCP_POT_FUNCTIONS = array(
	'__'         => array( 'single' => 0, 'domain' => 1 ),
	'_e'         => array( 'single' => 0, 'domain' => 1 ),
	'esc_attr__' => array( 'single' => 0, 'domain' => 1 ),
	'esc_attr_e' => array( 'single' => 0, 'domain' => 1 ),
	'esc_html__' => array( 'single' => 0, 'domain' => 1 ),
	'esc_html_e' => array( 'single' => 0, 'domain' => 1 ),
	'esc_xml__'  => array( 'single' => 0, 'domain' => 1 ),
	'esc_xml_e'  => array( 'single' => 0, 'domain' => 1 ),
	'translate'  => array( 'single' => 0, 'domain' => 1 ),
	'_x'         => array( 'single' => 0, 'context' => 1, 'domain' => 2 ),
	'_ex'        => array( 'single' => 0, 'context' => 1, 'domain' => 2 ),
	'esc_attr_x' => array( 'single' => 0, 'context' => 1, 'domain' => 2 ),
	'esc_html_x' => array( 'single' => 0, 'context' => 1, 'domain' => 2 ),
	'esc_xml_x'  => array( 'single' => 0, 'context' => 1, 'domain' => 2 ),
	'_n'         => array( 'single' => 0, 'plural' => 1, 'domain' => 3 ),
	'_n_noop'    => array( 'single' => 0, 'plural' => 1, 'domain' => 2 ),
	'_nx'        => array( 'single' => 0, 'plural' => 1, 'context' => 3, 'domain' => 4 ),
	'_nx_noop'   => array( 'single' => 0, 'plural' => 1, 'context' => 2, 'domain' => 3 ),
);

/** Directories never scanned: third-party code, dev-only trees, build output. */
const KARMCP_POT_SKIP_DIRS = array( 'vendor', 'node_modules', 'tools', 'tests', '.git', '.github', '.claude', '.phpunit.cache', '.phpunit.public.cache' );

/**
 * Strings referenced only from here are read by the AI agent, never by a person.
 *
 * They are the `label` and `description` of every MCP tool, which travel in the
 * tool schema the agent receives. The admin screens do NOT show them: the Tools
 * page renders its own curated catalogue from includes/admin/class-admin.php.
 */
const KARMCP_POT_AGENT_FACING_DIR = 'includes/abilities/';

/** Note attached to every agent-facing entry, so nobody "completes" them by mistake. */
const KARMCP_POT_AGENT_FACING_NOTE = 'DO NOT TRANSLATE. Agent-facing: this is the label or description of an MCP tool, which is read by the AI agent in the tool schema, never by a person. These are operating instructions whose exact wording has been tested against real agent behaviour, and translating them changes what the agent is told without anyone re-testing the result. Leaving msgstr empty makes gettext return the English original, which is the intended behaviour.';

$karmcp_pot_root  = dirname( __DIR__ );
$karmcp_pot_check = in_array( '--check', $argv, true );

$karmcp_pot_entries = array();
foreach ( karmcp_pot_php_files( $karmcp_pot_root ) as $karmcp_pot_file ) {
	karmcp_pot_scan_file( $karmcp_pot_file, $karmcp_pot_root, $karmcp_pot_entries );
}

// Plugin header fields WordPress itself translates from the POT.
karmcp_pot_scan_plugin_header( $karmcp_pot_root . '/karmcp.php', $karmcp_pot_entries );

ksort( $karmcp_pot_entries, SORT_STRING );

$karmcp_pot_body = karmcp_pot_render( $karmcp_pot_entries );
$karmcp_pot_dest = $karmcp_pot_root . '/languages/karmcp.pot';

if ( $karmcp_pot_check ) {
	$karmcp_pot_current = is_readable( $karmcp_pot_dest ) ? (string) file_get_contents( $karmcp_pot_dest ) : '';
	// The creation date is the only volatile line; compare without it.
	$karmcp_pot_strip = static fn( string $s ): string => (string) preg_replace( '/^"POT-Creation-Date:.*\n/m', '', $s );
	if ( $karmcp_pot_strip( $karmcp_pot_current ) !== $karmcp_pot_strip( $karmcp_pot_body ) ) {
		fwrite( STDERR, "languages/karmcp.pot is out of date. Run: php tools/make-pot.php\n" );
		exit( 1 );
	}
	echo 'languages/karmcp.pot is up to date (' . count( $karmcp_pot_entries ) . " strings).\n";
	exit( 0 );
}

file_put_contents( $karmcp_pot_dest, $karmcp_pot_body );
echo 'Wrote languages/karmcp.pot: ' . count( $karmcp_pot_entries ) . " strings.\n";

/**
 * Yields every PHP file under $root that is part of the shipped plugin.
 *
 * @param string $root Plugin root.
 * @return Generator<string>
 */
function karmcp_pot_php_files( string $root ): Generator {
	$iterator = new RecursiveIteratorIterator(
		new RecursiveCallbackFilterIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
			static function ( SplFileInfo $file ): bool {
				if ( $file->isDir() ) {
					return ! in_array( $file->getFilename(), KARMCP_POT_SKIP_DIRS, true );
				}
				return 'php' === strtolower( $file->getExtension() );
			}
		)
	);
	foreach ( $iterator as $file ) {
		yield str_replace( '\\', '/', $file->getPathname() );
	}
}

/**
 * Extracts every in-domain gettext call from one file into $entries.
 *
 * @param string               $file    Absolute path.
 * @param string               $root    Plugin root, for relative references.
 * @param array<string, array> $entries Accumulator, keyed by context and msgid.
 */
function karmcp_pot_scan_file( string $file, string $root, array &$entries ): void {
	$code     = (string) file_get_contents( $file );
	$tokens   = token_get_all( $code );
	$relative = ltrim( str_replace( str_replace( '\\', '/', $root ), '', $file ), '/' );

	// Translator comments, keyed by the line the comment ends on.
	$comments = array();

	$count = count( $tokens );
	for ( $i = 0; $i < $count; $i++ ) {
		$token = $tokens[ $i ];

		if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
			$comment = karmcp_pot_translator_comment( $token[1] );
			if ( null !== $comment ) {
				$comments[ $token[2] + substr_count( $token[1], "\n" ) ] = $comment;
			}
			continue;
		}

		if ( ! is_array( $token ) || T_STRING !== $token[0] ) {
			continue;
		}
		$name = $token[1];
		if ( ! isset( KARMCP_POT_FUNCTIONS[ $name ] ) ) {
			continue;
		}
		// Skip method calls, declarations and anything that is not the global function.
		$previous = karmcp_pot_previous_significant( $tokens, $i );
		if ( is_array( $previous ) && in_array( $previous[0], array( T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW ), true ) ) {
			continue;
		}
		$at   = null;
		$next = karmcp_pot_next_significant( $tokens, $i, $at );
		if ( '(' !== $next || null === $at ) {
			continue;
		}

		$args = karmcp_pot_read_args( $tokens, $at );
		if ( null === $args ) {
			continue;
		}

		$spec   = KARMCP_POT_FUNCTIONS[ $name ];
		$domain = $args[ $spec['domain'] ] ?? null;
		if ( KARMCP_POT_DOMAIN !== $domain ) {
			continue;
		}
		$single = $args[ $spec['single'] ] ?? null;
		if ( null === $single || '' === $single ) {
			continue;
		}

		$context = isset( $spec['context'] ) ? ( $args[ $spec['context'] ] ?? null ) : null;
		$plural  = isset( $spec['plural'] ) ? ( $args[ $spec['plural'] ] ?? null ) : null;
		$line    = $token[2];

		$key = ( null === $context ? '' : $context . "\4" ) . $single;
		if ( ! isset( $entries[ $key ] ) ) {
			$entries[ $key ] = array(
				'msgid'    => $single,
				'plural'   => $plural,
				'context'  => $context,
				'refs'     => array(),
				'comments' => array(),
			);
		}
		if ( null !== $plural && null === $entries[ $key ]['plural'] ) {
			$entries[ $key ]['plural'] = $plural;
		}
		$entries[ $key ]['refs'][] = $relative . ':' . $line;

		// A translator comment counts if it sits on the call's line or just above it.
		for ( $seek = $line; $seek >= $line - 3; $seek-- ) {
			if ( isset( $comments[ $seek ] ) ) {
				$entries[ $key ]['comments'][] = $comments[ $seek ];
				break;
			}
		}
	}
}

/**
 * Returns the text of a `translators:` comment, or null if it is not one.
 *
 * @param string $raw Raw comment token, markers included.
 */
function karmcp_pot_translator_comment( string $raw ): ?string {
	$text = trim( $raw );
	$text = (string) preg_replace( '#^(?://|\#|/\*+)#', '', $text );
	$text = (string) preg_replace( '#\*+/$#', '', $text );
	$text = trim( (string) preg_replace( '/^\s*\*\s?/m', '', $text ) );
	if ( ! preg_match( '/^translators:/i', $text ) ) {
		return null;
	}
	return (string) preg_replace( '/\s+/', ' ', $text );
}

/**
 * Adds the translatable plugin-header fields as POT entries.
 *
 * @param string               $file    Main plugin file.
 * @param array<string, array> $entries Accumulator.
 */
function karmcp_pot_scan_plugin_header( string $file, array &$entries ): void {
	if ( ! is_readable( $file ) ) {
		return;
	}
	$head = (string) file_get_contents( $file, false, null, 0, 8192 );
	$name = basename( $file );
	foreach ( array( 'Plugin Name', 'Description', 'Author', 'Plugin URI', 'Author URI' ) as $field ) {
		if ( ! preg_match( '/^\s*\*?\s*' . preg_quote( $field, '/' ) . ':\s*(.+)$/mu', $head, $matches ) ) {
			continue;
		}
		$value = trim( $matches[1] );
		if ( '' === $value ) {
			continue;
		}
		if ( ! isset( $entries[ $value ] ) ) {
			$entries[ $value ] = array(
				'msgid'    => $value,
				'plural'   => null,
				'context'  => null,
				'refs'     => array(),
				'comments' => array(),
			);
		}
		$entries[ $value ]['refs'][]     = $name;
		$entries[ $value ]['comments'][] = $field . ' of the plugin';
	}
}

/**
 * Reads a call's arguments, resolving only the ones that are literal strings.
 *
 * Returns null when the parentheses do not balance before EOF. An argument that
 * is not a plain literal (a variable, a call, something built at runtime) comes
 * back as null in its slot, which is what makes an unresolvable domain skip the
 * whole call instead of guessing.
 *
 * @param array<int, array|string> $tokens Token stream.
 * @param int                      $open   Index of the opening parenthesis.
 * @return array<int, string|null>|null
 */
function karmcp_pot_read_args( array $tokens, int $open ): ?array {
	$depth   = 0;
	$args    = array();
	$current = array();
	$count   = count( $tokens );

	for ( $i = $open; $i < $count; $i++ ) {
		$token = $tokens[ $i ];

		if ( is_string( $token ) && in_array( $token, array( '(', '[' ), true ) ) {
			++$depth;
			if ( 1 === $depth ) {
				continue;
			}
		} elseif ( is_string( $token ) && in_array( $token, array( ')', ']' ), true ) ) {
			--$depth;
			if ( 0 === $depth ) {
				$args[] = $current;
				return array_map( 'karmcp_pot_literal', $args );
			}
		} elseif ( ',' === $token && 1 === $depth ) {
			$args[]  = $current;
			$current = array();
			continue;
		}

		if ( is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}
		$current[] = $token;
	}
	return null;
}

/**
 * Resolves one argument's tokens to its literal string value, or null.
 *
 * Handles single- and double-quoted literals and any chain of them joined by
 * `.`. A double-quoted literal containing an interpolated variable is not a
 * literal and comes back null.
 *
 * @param array<int, array|string> $tokens Tokens of one argument.
 */
function karmcp_pot_literal( array $tokens ): ?string {
	if ( array() === $tokens ) {
		return null;
	}
	$out    = '';
	$expect = 'string';
	foreach ( $tokens as $token ) {
		if ( 'string' === $expect ) {
			if ( ! is_array( $token ) || T_CONSTANT_ENCAPSED_STRING !== $token[0] ) {
				return null;
			}
			$value = karmcp_pot_unquote( $token[1] );
			if ( null === $value ) {
				return null;
			}
			$out   .= $value;
			$expect = 'dot';
			continue;
		}
		if ( '.' !== $token ) {
			return null;
		}
		$expect = 'string';
	}
	return 'dot' === $expect ? $out : null;
}

/**
 * Turns a PHP string literal into its value.
 *
 * @param string $literal Raw literal, quotes included.
 */
function karmcp_pot_unquote( string $literal ): ?string {
	$quote = $literal[0];
	$body  = substr( $literal, 1, -1 );

	if ( "'" === $quote ) {
		return str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), $body );
	}
	// Double quotes: interpolation means it is not a literal.
	if ( preg_match( '/(?<!\\\\)(?:\$\w|\{\$)/', $body ) ) {
		return null;
	}
	return (string) preg_replace_callback(
		'/\\\\(n|t|r|v|e|f|\\\\|\$|"|[0-7]{1,3}|x[0-9A-Fa-f]{1,2}|u\{[0-9A-Fa-f]+\})/',
		static function ( array $matches ): string {
			return match ( true ) {
				'n' === $matches[1]     => "\n",
				't' === $matches[1]     => "\t",
				'r' === $matches[1]     => "\r",
				'v' === $matches[1]     => "\v",
				'e' === $matches[1]     => "\033",
				'f' === $matches[1]     => "\f",
				'\\' === $matches[1]    => '\\',
				'$' === $matches[1]     => '$',
				'"' === $matches[1]     => '"',
				'x' === $matches[1][0]  => chr( (int) hexdec( substr( $matches[1], 1 ) ) ),
				'u' === $matches[1][0]  => (string) mb_chr( (int) hexdec( trim( substr( $matches[1], 1 ), '{}' ) ), 'UTF-8' ),
				default                 => chr( (int) octdec( $matches[1] ) ),
			};
		},
		$body
	);
}

/**
 * Previous token that is not whitespace or a comment.
 *
 * @param array<int, array|string> $tokens Token stream.
 * @param int                      $index  Current index.
 * @return array|string|null
 */
function karmcp_pot_previous_significant( array $tokens, int $index ) {
	for ( $i = $index - 1; $i >= 0; $i-- ) {
		if ( is_array( $tokens[ $i ] ) && in_array( $tokens[ $i ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}
		return $tokens[ $i ];
	}
	return null;
}

/**
 * Next token that is not whitespace or a comment, plus its index.
 *
 * @param array<int, array|string> $tokens Token stream.
 * @param int                      $index  Current index.
 * @param int|null                 $at     Receives the index of the token returned.
 * @return array|string|null
 */
function karmcp_pot_next_significant( array $tokens, int $index, ?int &$at = null ) {
	$count = count( $tokens );
	for ( $i = $index + 1; $i < $count; $i++ ) {
		if ( is_array( $tokens[ $i ] ) && in_array( $tokens[ $i ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}
		$at = $i;
		return $tokens[ $i ];
	}
	$at = null;
	return null;
}

/**
 * Renders the collected entries as a POT file.
 *
 * @param array<string, array> $entries Collected strings.
 */
function karmcp_pot_render( array $entries ): string {
	$version = '1.0.0';
	$header  = (string) file_get_contents( dirname( __DIR__ ) . '/karmcp.php', false, null, 0, 4096 );
	if ( preg_match( '/^\s*\*\s*Version:\s*(.+)$/mu', $header, $matches ) ) {
		$version = trim( $matches[1] );
	}

	$out  = '# Copyright (C) ' . gmdate( 'Y' ) . " karcox\n";
	$out .= "# This file is distributed under the GPL-2.0-or-later licence.\n";
	$out .= "msgid \"\"\n";
	$out .= "msgstr \"\"\n";
	$out .= '"Project-Id-Version: KarMCP ' . $version . '\n"' . "\n";
	$out .= '"Report-Msgid-Bugs-To: https://github.com/karcox/karmcp/issues\n"' . "\n";
	$out .= '"POT-Creation-Date: ' . gmdate( 'Y-m-d H:i:sO' ) . '\n"' . "\n";
	$out .= '"MIME-Version: 1.0\n"' . "\n";
	$out .= '"Content-Type: text/plain; charset=UTF-8\n"' . "\n";
	$out .= '"Content-Transfer-Encoding: 8bit\n"' . "\n";
	$out .= '"PO-Revision-Date: YEAR-MO-DA HO:MI+ZONE\n"' . "\n";
	$out .= '"Last-Translator: FULL NAME <EMAIL@ADDRESS>\n"' . "\n";
	$out .= '"Language-Team: LANGUAGE <LL@li.org>\n"' . "\n";
	$out .= '"Plural-Forms: nplurals=2; plural=(n != 1);\n"' . "\n";
	$out .= '"X-Generator: tools/make-pot.php\n"' . "\n";
	$out .= '"X-Domain: ' . KARMCP_POT_DOMAIN . '\n"' . "\n";

	foreach ( $entries as $entry ) {
		$out .= "\n";
		if ( karmcp_pot_is_agent_facing( $entry['refs'] ) ) {
			$out .= '#. ' . KARMCP_POT_AGENT_FACING_NOTE . "\n";
		}
		foreach ( array_unique( $entry['comments'] ) as $comment ) {
			$out .= '#. ' . $comment . "\n";
		}
		foreach ( array_unique( $entry['refs'] ) as $reference ) {
			$out .= '#: ' . $reference . "\n";
		}
		if ( null !== $entry['context'] ) {
			$out .= 'msgctxt ' . karmcp_pot_quote( $entry['context'] ) . "\n";
		}
		$out .= 'msgid ' . karmcp_pot_quote( $entry['msgid'] ) . "\n";
		if ( null !== $entry['plural'] ) {
			$out .= 'msgid_plural ' . karmcp_pot_quote( $entry['plural'] ) . "\n";
			$out .= "msgstr[0] \"\"\n";
			$out .= "msgstr[1] \"\"\n";
		} else {
			$out .= "msgstr \"\"\n";
		}
	}
	return $out;
}

/**
 * Whether a string is only ever read by the AI agent.
 *
 * True when every reference is a tool definition. One reference from anywhere
 * else means a person can see it, and then it gets translated like the rest.
 *
 * @param string[] $refs File:line references of the entry.
 */
function karmcp_pot_is_agent_facing( array $refs ): bool {
	if ( array() === $refs ) {
		return false;
	}
	foreach ( $refs as $reference ) {
		if ( ! str_starts_with( $reference, KARMCP_POT_AGENT_FACING_DIR ) ) {
			return false;
		}
	}
	return true;
}

/**
 * Quotes a value for a PO file, splitting on newlines the way gettext does.
 *
 * @param string $value Raw string.
 */
function karmcp_pot_quote( string $value ): string {
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
