<?php
/**
 * Page settings are merged, never replaced.
 *
 * Elementor's page settings manager ends at
 * `update_metadata( 'post', $id, '_elementor_page_settings', $settings )`, so
 * whatever array it is handed becomes the entire meta. Handing it only the
 * incoming keys wiped every key already stored — sending two background keys
 * to a post took its settings from eight entries to two, taking
 * `container_custom_height` (a popup's full-screen height) with them.
 *
 * Worse, the fallback path merged, so the same call either merged or replaced
 * depending on whether the native save happened to succeed. These tests pin
 * both paths to the same contract.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-elementor-data.php';

/**
 * Stands in for an Elementor document, recording what save() was handed and
 * writing it through exactly as Elementor's manager does — replacing the meta.
 */
class KarMCP_Test_Document {

	/** @var array<string,mixed>|null Settings passed to the last save() call. */
	public $received;

	/** @var bool Whether save() reports success. */
	private $succeeds;

	/** @var int */
	private $post_id;

	public function __construct( int $post_id, bool $succeeds ) {
		$this->post_id  = $post_id;
		$this->succeeds = $succeeds;
	}

	/**
	 * @param array<string,mixed> $data Save payload.
	 * @return bool
	 */
	public function save( array $data ): bool {
		$this->received = $data['settings'] ?? null;

		if ( $this->succeeds ) {
			// This is the part that made the bug destructive: a plain overwrite.
			update_post_meta( $this->post_id, '_elementor_page_settings', $this->received );
		}

		return $this->succeeds;
	}
}

class PageSettingsMergeTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['karmcp_test']['post_meta'] = array();
	}

	/**
	 * @param array<string,mixed> $stored   Settings already on the post.
	 * @param array<string,mixed> $incoming Settings being written.
	 * @param bool                $native   Whether the native save succeeds.
	 * @return array{0:array<string,mixed>,1:KarMCP_Test_Document}
	 */
	private function save( array $stored, array $incoming, bool $native ): array {
		$post_id = 11103;
		update_post_meta( $post_id, '_elementor_page_settings', $stored );

		$document = new KarMCP_Test_Document( $post_id, $native );

		$data = $this->getMockBuilder( KarMCP_Data::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_document' ) )
			->getMock();
		$data->method( 'get_document' )->willReturn( $document );

		$data->save_page_settings( $post_id, $incoming );

		return array( (array) get_post_meta( $post_id, '_elementor_page_settings', true ), $document );
	}

	/**
	 * The reported failure, reproduced: eight keys in, two written, six gone.
	 */
	public function test_the_native_save_keeps_the_keys_it_was_not_given(): void {
		$stored = array(
			'container_custom_height' => '100vh',
			'padding'                 => array( 'top' => '0' ),
			'custom_css'              => '.x{}',
		);

		list( $after ) = $this->save(
			$stored,
			array( 'background_background' => 'classic', 'background_color' => '#000' ),
			true
		);

		$this->assertSame( '100vh', $after['container_custom_height'], 'The popup height must survive a background write.' );
		$this->assertSame( '#000', $after['background_color'] );
		$this->assertCount( 5, $after );
	}

	/**
	 * The merge has to happen before the document sees it — asserting on the
	 * meta alone would also pass if we merged afterwards, which would still
	 * hand Elementor a truncated model to rebuild its CSS from.
	 */
	public function test_the_document_is_handed_the_merged_set(): void {
		list( , $document ) = $this->save(
			array( 'container_custom_height' => '100vh' ),
			array( 'background_color' => '#000' ),
			true
		);

		$this->assertArrayHasKey( 'container_custom_height', (array) $document->received );
	}

	/**
	 * Same contract when the native save fails and the direct-meta fallback
	 * runs. The two paths disagreeing is what made this hard to pin down.
	 */
	public function test_the_fallback_path_merges_identically(): void {
		list( $after ) = $this->save(
			array( 'container_custom_height' => '100vh' ),
			array( 'background_color' => '#000' ),
			false
		);

		$this->assertSame( '100vh', $after['container_custom_height'] );
		$this->assertSame( '#000', $after['background_color'] );
	}

	/**
	 * Merging must not cost the ability to remove a key: null still deletes.
	 */
	public function test_null_still_deletes_a_key(): void {
		list( $after ) = $this->save(
			array( 'container_custom_height' => '100vh', 'custom_css' => '.x{}' ),
			array( 'custom_css' => null ),
			true
		);

		$this->assertArrayNotHasKey( 'custom_css', $after );
		$this->assertSame( '100vh', $after['container_custom_height'] );
	}
}
