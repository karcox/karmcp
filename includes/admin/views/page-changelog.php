<?php
/**
 * Changelog tab view.
 *
 * Reads CHANGELOG.md and renders version entries as styled cards — matching the
 * website's changelog: a card per version with a LATEST badge, color-coded
 * Fixed/New/Improved tags, blockquote note callouts, nested sub-items, and
 * inline markdown (bold, `code`, and links) rendered to HTML.
 *
 * @package KarMCP
 * @since   1.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders a single line of inline markdown (bold, code, links) to safe HTML.
 * The whole string is escaped first, then the markdown tokens are converted, so
 * nothing user-facing can inject markup.
 *
 * @param string $text Raw markdown text.
 * @return string Sanitized HTML.
 */
if ( ! function_exists( 'karmcp_changelog_inline_md' ) ) {
	function karmcp_changelog_inline_md( string $text ): string {
		$html = esc_html( $text );

		// Inline code: `code`.
		$html = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $html );

		// Links: [text](url).
		$html = preg_replace_callback(
			'/\[([^\]]+)\]\(([^)\s]+)\)/',
			static function ( $m ) {
				$url = esc_url( html_entity_decode( $m[2], ENT_QUOTES ) );
				if ( '' === $url ) {
					return $m[1];
				}
				return '<a href="' . $url . '" target="_blank" rel="noopener noreferrer">' . $m[1] . '</a>';
			},
			$html
		);

		// Bold: **text**.
		$html = preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $html );

		// Italic: *text*. Word-bounded so a lone '*' inside code (e.g.
		// `elementor_mcp_*`) can't open an italic span, and it never spans tags.
		$html = preg_replace( '/(?<![\w*])\*([^*<>]+)\*(?![\w*])/', '<em>$1</em>', $html );

		return wp_kses(
			$html,
			array(
				'strong' => array(),
				'em'     => array(),
				'code'   => array(),
				'a'      => array(
					'href'   => array(),
					'target' => array(),
					'rel'    => array(),
				),
			)
		);
	}
}

/**
 * Splits a leading "Fixed:/New:/Improved:/…" keyword off an item so it can be
 * rendered as a color-coded tag.
 *
 * @param string $text Item text.
 * @return array{tag:string,rest:string}
 */
if ( ! function_exists( 'karmcp_changelog_tag' ) ) {
	function karmcp_changelog_tag( string $text ): array {
		if ( preg_match( '/^(Fixed|New|Improved|Changed|Removed|Security|Deprecated|Note):\s*/i', $text, $m ) ) {
			return array(
				'tag'  => ucfirst( strtolower( $m[1] ) ),
				'rest' => substr( $text, strlen( $m[0] ) ),
			);
		}
		return array( 'tag' => '', 'rest' => $text );
	}
}

$karmcp_changelog_file = KARMCP_DIR . 'CHANGELOG.md';

if ( ! file_exists( $karmcp_changelog_file ) ) {
	echo '<p>' . esc_html__( 'Changelog file not found.', 'karmcp' ) . '</p>';
	return;
}

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local plugin file.
$karmcp_changelog_raw = (string) file_get_contents( $karmcp_changelog_file );

// Parse markdown into version blocks: each has notes[] (blockquotes) and
// items[] (each item = text + nested children for sub-bullets).
$karmcp_versions = array();
$karmcp_current  = null;

foreach ( explode( "\n", $karmcp_changelog_raw ) as $karmcp_line ) {
	$karmcp_line = rtrim( $karmcp_line );

	// Version header: ## [x.y.z]
	if ( preg_match( '/^##\s+\[([^\]]+)\]/', $karmcp_line, $m ) ) {
		if ( null !== $karmcp_current ) {
			$karmcp_versions[] = $karmcp_current;
		}
		$karmcp_current = array(
			'version' => $m[1],
			'notes'   => array(),
			'items'   => array(),
		);
		continue;
	}

	if ( null === $karmcp_current ) {
		continue;
	}

	// Blockquote note: > text
	if ( preg_match( '/^>\s?(.*)/', $karmcp_line, $m ) ) {
		if ( '' !== trim( $m[1] ) ) {
			$karmcp_current['notes'][] = $m[1];
		}
		continue;
	}

	// Sub-item (indented bullet): "  - text" or tab-indented.
	if ( preg_match( '/^(?:\t|\s{2,})[-*]\s+(.+)/', $karmcp_line, $m ) ) {
		$karmcp_last = count( $karmcp_current['items'] ) - 1;
		if ( $karmcp_last >= 0 ) {
			$karmcp_current['items'][ $karmcp_last ]['children'][] = $m[1];
		}
		continue;
	}

	// Top-level bullet: "- text"
	if ( preg_match( '/^[-*]\s+(.+)/', $karmcp_line, $m ) ) {
		$karmcp_current['items'][] = array(
			'text'     => $m[1],
			'children' => array(),
		);
		continue;
	}
}

if ( null !== $karmcp_current ) {
	$karmcp_versions[] = $karmcp_current;
}

$karmcp_latest_version = isset( $karmcp_versions[0]['version'] ) ? $karmcp_versions[0]['version'] : '';
?>

<div class="karmcp-changelog">

	<div class="karmcp-changelog-intro">
		<h2><?php esc_html_e( 'Changelog', 'karmcp' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'What changed in each release of KarMCP.', 'karmcp' ); ?>
		</p>
	</div>

	<div class="karmcp-changelog-list">
		<?php foreach ( $karmcp_versions as $karmcp_entry ) : ?>
			<?php $karmcp_is_latest = ( $karmcp_entry['version'] === $karmcp_latest_version ); ?>
			<div class="karmcp-changelog-version <?php echo esc_attr( $karmcp_is_latest ? 'is-latest' : '' ); ?>">
				<div class="karmcp-changelog-version-header">
					<h3>
						<?php
						/* translators: %s: version number */
						printf( esc_html__( 'Version %s', 'karmcp' ), esc_html( $karmcp_entry['version'] ) );
						?>
					</h3>
					<?php if ( $karmcp_is_latest ) : ?>
						<span class="karmcp-changelog-badge"><?php esc_html_e( 'Latest', 'karmcp' ); ?></span>
					<?php endif; ?>
				</div>

				<?php foreach ( $karmcp_entry['notes'] as $karmcp_note ) : ?>
					<div class="karmcp-changelog-note">
						<?php echo karmcp_changelog_inline_md( $karmcp_note ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sanitized via wp_kses inside the renderer. ?>
					</div>
				<?php endforeach; ?>

				<?php if ( ! empty( $karmcp_entry['items'] ) ) : ?>
					<ul class="karmcp-changelog-items">
						<?php
						foreach ( $karmcp_entry['items'] as $karmcp_item ) :
							$karmcp_parts = karmcp_changelog_tag( $karmcp_item['text'] );
							?>
							<li>
								<?php if ( '' !== $karmcp_parts['tag'] ) : ?>
									<span class="karmcp-cl-tag karmcp-cl-tag--<?php echo esc_attr( strtolower( $karmcp_parts['tag'] ) ); ?>"><?php echo esc_html( $karmcp_parts['tag'] ); ?></span>
								<?php endif; ?>
								<?php echo karmcp_changelog_inline_md( $karmcp_parts['rest'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sanitized via wp_kses inside the renderer. ?>
								<?php if ( ! empty( $karmcp_item['children'] ) ) : ?>
									<ul class="karmcp-changelog-subitems">
										<?php foreach ( $karmcp_item['children'] as $karmcp_child ) : ?>
											<li><?php echo karmcp_changelog_inline_md( $karmcp_child ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sanitized via wp_kses inside the renderer. ?></li>
										<?php endforeach; ?>
									</ul>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>
	</div>

</div>
