<?php
/**
 * Splits a skill's body into addressable sections.
 *
 * A skill is a manual, and manuals grow. This site's is 90 KB and rising —
 * every fix it documents makes it longer — which put it past what a tool
 * response can carry. The failure is not a truncation: the call errors, and the
 * reader has to dump the JSON to a file, pull the headings out with a regex and
 * slice it by character offsets before reading a word. Every agent that starts
 * work pays that, and the skill is required reading before touching anything.
 *
 * So the body becomes navigable: an outline of what is in it, and the ability
 * to ask for one part. The parsing is deliberately plain — markdown ATX
 * headings, `##` and `###` — because the alternative is a markdown dependency
 * for the one feature that has to keep working when the file is odd.
 *
 * Pure: text in, structure out, no WordPress.
 *
 * @package KarMCP
 * @since   1.22.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Outline and section extraction for a skill body.
 *
 * @since 1.22.0
 */
class KarMCP_Skill_Outline {

	/**
	 * Bodies at or under this many characters are returned whole: splitting a
	 * short manual costs a second call and buys nothing.
	 *
	 * @var int
	 */
	const WHOLE_BODY_LIMIT = 20000;

	/**
	 * The headings of a body, in order.
	 *
	 * Each entry carries the `id` to pass back as `section` — the leading
	 * number when the heading has one ("1.5"), otherwise a slug of the title —
	 * plus its size, so a caller can tell a two-line note from a 14 KB chapter
	 * before spending a call on it.
	 *
	 * @since 1.22.0
	 *
	 * @param string $body The skill body.
	 * @return array<int,array<string,mixed>>
	 */
	public static function outline( string $body ): array {
		$sections = self::split( $body );
		$out      = array();

		foreach ( $sections as $section ) {
			$out[] = array(
				'id'    => $section['id'],
				'level' => $section['level'],
				'title' => $section['title'],
				'chars' => strlen( $section['text'] ),
			);
		}

		return $out;
	}

	/**
	 * One section's text, heading included.
	 *
	 * Matching is forgiving on purpose: an agent reading an outline will pass
	 * "1.5", "1.5 El bloque de navegación" or the bare title, and all three
	 * mean the same section. Being strict here would just move the cost back
	 * onto the caller.
	 *
	 * @since 1.22.0
	 *
	 * @param string $body The skill body.
	 * @param string $want The section id or title.
	 * @return array{id:string,title:string,text:string}|null
	 */
	public static function section( string $body, string $want ): ?array {
		$want = trim( $want );
		if ( '' === $want ) {
			return null;
		}

		$sections = self::split( $body );
		$needle   = self::normalise( $want );

		// Exact id, then exact title, then a title that starts with what was
		// asked for. In that order: an id is unambiguous, a title is nearly so,
		// and a prefix is the guess.
		foreach ( array( 'id', 'title', 'prefix' ) as $pass ) {
			foreach ( $sections as $section ) {
				$id    = self::normalise( $section['id'] );
				$title = self::normalise( $section['title'] );

				$hit = ( 'id' === $pass && $id === $needle )
					|| ( 'title' === $pass && $title === $needle )
					|| ( 'prefix' === $pass && '' !== $needle && str_starts_with( $title, $needle ) );

				if ( $hit ) {
					return array(
						'id'    => $section['id'],
						'title' => $section['title'],
						'text'  => $section['text'],
					);
				}
			}
		}

		return null;
	}

	/**
	 * Splits the body at its `##` and `###` headings.
	 *
	 * A section runs to the next heading at the same level or higher, so asking
	 * for a chapter gets its subsections with it — which is what someone
	 * reading "1. Anatomy of an item" wants, rather than its first paragraph.
	 *
	 * @param string $body The skill body.
	 * @return array<int,array<string,mixed>>
	 */
	private static function split( string $body ): array {
		$lines    = explode( "\n", $body );
		$sections = array();
		$current  = null;

		foreach ( $lines as $line ) {
			if ( preg_match( '/^(#{2,3})\s+(.*\S)\s*$/', $line, $m ) ) {
				if ( null !== $current ) {
					$sections[] = $current;
				}

				$title   = trim( $m[2] );
				$current = array(
					'level' => strlen( $m[1] ),
					'title' => $title,
					'id'    => self::id_from_title( $title ),
					'text'  => $line . "\n",
				);
				continue;
			}

			if ( null !== $current ) {
				$current['text'] .= $line . "\n";
			}
		}

		if ( null !== $current ) {
			$sections[] = $current;
		}

		$sections = self::disambiguate( $sections );

		// A subsection belongs to the chapter above it, so a chapter's text
		// absorbs every deeper section that follows it.
		$count = count( $sections );
		for ( $i = 0; $i < $count; $i++ ) {
			for ( $j = $i + 1; $j < $count; $j++ ) {
				if ( $sections[ $j ]['level'] <= $sections[ $i ]['level'] ) {
					break;
				}
				$sections[ $i ]['text'] .= $sections[ $j ]['text'];
			}
		}

		return $sections;
	}

	/**
	 * The id an agent passes back: the heading's own number when it has one.
	 *
	 * Numbers are what a manual's own cross-references use ("see §1.5"), so
	 * they are what someone reading it will try first.
	 *
	 * @param string $title The heading text.
	 * @return string
	 */
	private static function id_from_title( string $title ): string {
		if ( preg_match( '/^(\d+(?:\.\d+)*)/', $title, $m ) ) {
			return $m[1];
		}

		return self::slug( $title );
	}

	/**
	 * A readable, copyable id from a heading.
	 *
	 * Headings without a number are usually the warnings, and they are
	 * sentences: slugging one whole gives a 76-character id nobody will copy
	 * correctly. Cut to the first few words — enough to be recognisable, and the
	 * full title still resolves through the prefix match.
	 *
	 * @param string $title The heading text.
	 * @return string
	 */
	private static function slug( string $title ): string {
		$slug = strtolower( $title );
		$slug = preg_replace( '/[^a-z0-9]+/', '-', $slug );
		$slug = trim( (string) $slug, '-' );

		if ( strlen( $slug ) > 48 ) {
			$slug = substr( $slug, 0, 48 );
			$slug = substr( $slug, 0, (int) strrpos( $slug, '-' ) ?: 48 );
		}

		return $slug;
	}

	/**
	 * Makes every id unique, keeping the first claimant.
	 *
	 * Numbering in a real manual is not as tidy as the regex assumes: this
	 * site's has "4. Bloques con solución" and "4 bis. Multimedia" as separate
	 * chapters, and both reduce to "4". Left alone, the second is unreachable by
	 * id — every request lands on the first — which is worse than an ugly id,
	 * because nothing about the outline reveals that a section is unreachable.
	 *
	 * A collision falls back to the title slug, and only if that also collides
	 * does it take a counter.
	 *
	 * @param array<int,array<string,mixed>> $sections Parsed sections.
	 * @return array<int,array<string,mixed>>
	 */
	private static function disambiguate( array $sections ): array {
		$seen = array();

		foreach ( $sections as $i => $section ) {
			$id = (string) $section['id'];

			if ( ! isset( $seen[ $id ] ) ) {
				$seen[ $id ] = true;
				continue;
			}

			$slug = self::slug( (string) $section['title'] );
			if ( '' !== $slug && ! isset( $seen[ $slug ] ) ) {
				$sections[ $i ]['id'] = $slug;
				$seen[ $slug ]        = true;
				continue;
			}

			$n = 2;
			while ( isset( $seen[ $id . '-' . $n ] ) ) {
				++$n;
			}
			$sections[ $i ]['id'] = $id . '-' . $n;
			$seen[ $id . '-' . $n ] = true;
		}

		return $sections;
	}

	/**
	 * @param string $value Raw text.
	 * @return string Lowercased, punctuation-flattened, for comparison.
	 */
	private static function normalise( string $value ): string {
		$value = strtolower( trim( $value ) );
		$value = preg_replace( '/\s+/', ' ', $value );

		return (string) $value;
	}
}
