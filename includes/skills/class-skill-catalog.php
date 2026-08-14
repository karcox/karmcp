<?php
/**
 * Agent Skills catalog — the discovery-context index.
 *
 * Only the index travels: every skill's name and one-line summary, plus a note
 * telling the agent how to fetch a body. Bodies stay behind `get-skill`.
 *
 * That split is the whole point. Site policy (the Guardrails module) is short
 * and applies to every call, so it ships whole on every connection. Skills are
 * long and apply to some calls, so shipping them whole would tax every
 * conversation for guidance most of them never use. An index costs a line each
 * and lets the agent decide.
 *
 * @package KarMCP
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the `## Skills` block injected into the discovery context.
 *
 * @since 1.0.0
 */
class KarMCP_Skill_Catalog {

	/** Wire the discovery seam. Called by the Agent Skills module when active. */
	public static function init(): void {
		add_filter( 'karmcp_discovery_skills', array( __CLASS__, 'inject' ) );
	}

	/**
	 * `karmcp_discovery_skills` filter: append this site's skill index.
	 *
	 * @param string $existing Whatever an earlier listener produced.
	 * @return string
	 */
	public static function inject( $existing ): string {
		$existing = (string) $existing;
		$block    = self::block( self::index() );

		if ( '' === $block ) {
			return $existing;
		}
		return ( '' === trim( $existing ) ) ? $block : $existing . "\n\n" . $block;
	}

	/**
	 * Index entries for every published skill.
	 *
	 * @return array<int,array{name:string,title:string,summary:string}>
	 */
	public static function index(): array {
		$out = array();
		foreach ( KarMCP_Skill_Store::active() as $skill ) {
			$entry = KarMCP_Skill_Store::summarize( $skill );
			if ( '' !== $entry['name'] ) {
				$out[] = $entry;
			}
		}
		return $out;
	}

	/**
	 * Render an index as the discovery block. Pure, so the wording is testable.
	 *
	 * @param array $skills Index entries.
	 * @return string Markdown block, or '' when there are no skills.
	 */
	public static function block( array $skills ): string {
		if ( array() === $skills ) {
			return '';
		}

		$lines   = array( '## Skills' );
		$lines[] = __( 'How this site expects things to be done. Read the one that fits the task before you start — call `get-skill` with the name in backticks to fetch its full text.', 'karmcp' );
		$lines[] = '';

		foreach ( $skills as $skill ) {
			$name    = (string) ( $skill['name'] ?? '' );
			$title   = trim( (string) ( $skill['title'] ?? '' ) );
			$summary = trim( (string) ( $skill['summary'] ?? '' ) );

			$line = '- `' . $name . '`';
			if ( '' !== $title ) {
				$line .= ' — ' . $title;
			}
			if ( '' !== $summary ) {
				$line .= ( '' !== $title ) ? ': ' . $summary : ' — ' . $summary;
			}
			$lines[] = $line;
		}

		return implode( "\n", $lines );
	}
}
