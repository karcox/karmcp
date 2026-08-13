<?php
/**
 * Public unit test for KarMCP_Data::is_atomic_validation_rejection() — the
 * issue #112 fix. When Document::save() THROWS Elementor's atomic settings/styles
 * validation error (e.g. a valid editor-authored `{$$type:'dynamic'}` paragraph
 * whose tag the CLI/REST atomic registry can't resolve), the save path routes the
 * throw into the raw meta-write fallback instead of hard-failing the whole edit.
 * A genuine fatal (any other message) still hard-fails. This pins that
 * classification; the end-to-end fallback is verified on a live HTTP save context.
 *
 * @package KarMCP
 */

require_once dirname( __DIR__ ) . '/includes/class-elementor-data.php';

class Issue112ValidationFallbackTest extends \PHPUnit\Framework\TestCase {

	public function test_settings_validation_failure_is_a_rejection() {
		$this->assertTrue(
			KarMCP_Data::is_atomic_validation_rejection( 'Settings validation failed. paragraph: invalid_value' )
		);
	}

	public function test_styles_validation_failure_is_a_rejection() {
		$this->assertTrue(
			KarMCP_Data::is_atomic_validation_rejection( 'Styles validation failed for style `g-1` (widget `abc`)' )
		);
	}

	public function test_bare_invalid_value_is_a_rejection() {
		$this->assertTrue(
			KarMCP_Data::is_atomic_validation_rejection( 'title: invalid_value' )
		);
	}

	public function test_case_insensitive() {
		$this->assertTrue(
			KarMCP_Data::is_atomic_validation_rejection( 'SETTINGS VALIDATION FAILED. paragraph: INVALID_VALUE' )
		);
	}

	public function test_genuine_fatal_is_not_a_rejection() {
		$this->assertFalse(
			KarMCP_Data::is_atomic_validation_rejection( 'Call to a member function get_settings() on null' )
		);
	}

	public function test_empty_message_is_not_a_rejection() {
		$this->assertFalse( KarMCP_Data::is_atomic_validation_rejection( '' ) );
	}
}
