<?php
/**
 * KarMCP_Readability — syllable counting and the per-language formula.
 *
 * The Spanish syllable counter is exact, not heuristic: vowel groups,
 * diphthongs and hiatus follow regular rules. That is worth pinning word by
 * word, because a silent drift of one syllable per word moves the score by
 * tens of points and nothing else would notice.
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/audits/class-readability.php';

final class ReadabilityTest extends TestCase {

	// ------------------------------------------------- Spanish syllables

	/**
	 * @dataProvider spanishWords
	 */
	public function test_counts_spanish_syllables( string $word, int $expected ): void {
		$this->assertSame(
			$expected,
			KarMCP_Readability::syllables_es( $word ),
			"«{$word}»"
		);
	}

	public static function spanishWords(): array {
		return array(
			// Plain consonant-vowel alternation.
			'casa'       => array( 'casa', 2 ),
			'ordenador'  => array( 'ordenador', 4 ),
			'sol'        => array( 'sol', 1 ),

			// Diphthongs: weak + strong, or two different weak vowels.
			'agua'       => array( 'agua', 2 ),
			'tiempo'     => array( 'tiempo', 2 ),
			'ciudad'     => array( 'ciudad', 2 ),
			'aire'       => array( 'aire', 2 ),

			// Hiatus: two strong vowels never merge.
			'caer'       => array( 'caer', 2 ),
			'teatro'     => array( 'teatro', 3 ),
			'leer'       => array( 'leer', 2 ),

			// Hiatus forced by an accent on the weak vowel — the case that
			// separates a real implementation from a vowel counter.
			'día'        => array( 'día', 2 ),
			'país'       => array( 'país', 2 ),
			'raíz'       => array( 'raíz', 2 ),
			'búho'       => array( 'búho', 2 ),

			// Accented strong vowels do not add anything by themselves.
			'canción'    => array( 'canción', 2 ),
			'también'    => array( 'también', 2 ),

			// Triphthong.
			'apreciáis'  => array( 'apreciáis', 3 ),

			// Silent h between vowels does not break the group. This follows the
			// RAE orthographic convention, which is why "prohibir" comes out as
			// proi-bir rather than the pedagogical pro-hi-bir: the same rule
			// that gives ai-ja-do has to give proi-bir, and applying it
			// consistently matters more than winning either argument. The
			// difference is one syllable on a rare word and moves no score.
			'ahijado'    => array( 'ahijado', 3 ),
			'prohibir'   => array( 'prohibir', 2 ),

			// ü is a weak vowel that is pronounced: "güe" stays one syllable.
			'vergüenza'  => array( 'vergüenza', 3 ),

			// y after a vowel behaves as a weak vowel.
			'rey'        => array( 'rey', 1 ),
			'muy'        => array( 'muy', 1 ),
			'hay'        => array( 'hay', 1 ),

			// y as a consonant, and as a word.
			'yo'         => array( 'yo', 1 ),
			'y'          => array( 'y', 1 ),

			// Longer real words.
			'murciélago' => array( 'murciélago', 4 ),
			'biblioteca' => array( 'biblioteca', 4 ),
		);
	}

	public function test_a_word_always_has_at_least_one_syllable(): void {
		$this->assertSame( 1, KarMCP_Readability::syllables_es( '' ) );
		$this->assertSame( 1, KarMCP_Readability::syllables_es( '123' ) );
	}

	// ------------------------------------------------- English syllables

	/**
	 * @dataProvider englishWords
	 */
	public function test_counts_english_syllables( string $word, int $expected ): void {
		$this->assertSame( $expected, KarMCP_Readability::syllables_en( $word ), "«{$word}»" );
	}

	public static function englishWords(): array {
		return array(
			'cat'      => array( 'cat', 1 ),
			'make'     => array( 'make', 1 ),
			'making'   => array( 'making', 2 ),
			'the'      => array( 'the', 1 ),
			'computer' => array( 'computer', 3 ),
		);
	}

	// ------------------------------------------------------- sentences

	public function test_counts_sentences(): void {
		$this->assertSame( 3, KarMCP_Readability::count_sentences( 'Uno. Dos. Tres.' ) );
	}

	public function test_a_run_of_terminators_is_one_sentence(): void {
		// "¿De verdad?!" is one sentence, and an ellipsis is not three.
		$this->assertSame( 1, KarMCP_Readability::count_sentences( '¿De verdad?!' ) );
		$this->assertSame( 1, KarMCP_Readability::count_sentences( 'Bueno…' ) );
	}

	public function test_text_with_no_terminator_is_still_one_sentence(): void {
		$this->assertSame( 1, KarMCP_Readability::count_sentences( 'Un titular sin punto' ) );
	}

	public function test_empty_text_has_no_sentences(): void {
		$this->assertSame( 0, KarMCP_Readability::count_sentences( '   ' ) );
	}

	// --------------------------------------------------------- language

	public function test_resolves_the_language_subtag(): void {
		$this->assertSame( 'es', KarMCP_Readability::language_of( 'es_ES' ) );
		$this->assertSame( 'es', KarMCP_Readability::language_of( 'es-AR' ) );
		$this->assertSame( 'en', KarMCP_Readability::language_of( 'en_US' ) );
	}

	/**
	 * A number from the wrong formula is worse than no number, so a language
	 * without a calibrated formula must say so instead of guessing.
	 */
	public function test_unsupported_language_returns_no_score(): void {
		$result = KarMCP_Readability::analyze( str_repeat( 'Das ist ein Satz. ', 40 ), 'de_DE' );

		$this->assertSame( 'unsupported', $result['status'] );
		$this->assertArrayNotHasKey( 'score', $result );
	}

	public function test_short_text_is_refused_rather_than_scored(): void {
		$result = KarMCP_Readability::analyze( 'Una frase corta y nada más.', 'es_ES' );

		$this->assertSame( 'insufficient', $result['status'] );
		$this->assertArrayNotHasKey( 'score', $result );
	}

	// ---------------------------------------------------------- formula

	public function test_spanish_uses_szigriszt_and_english_uses_flesch(): void {
		$spanish = KarMCP_Readability::analyze( str_repeat( 'La casa es blanca y muy bonita. ', 30 ), 'es_ES' );
		$this->assertSame( 'szigriszt-pazos', $spanish['formula'] );

		$english = KarMCP_Readability::analyze( str_repeat( 'The house is white and very nice. ', 30 ), 'en_US' );
		$this->assertSame( 'flesch', $english['formula'] );
	}

	/**
	 * The whole point of the class. Identical prose scored with the English
	 * formula comes out far harsher, because Spanish carries more syllables per
	 * word — which is a fact about the language, not about the text.
	 */
	public function test_spanish_text_is_not_punished_by_the_english_formula(): void {
		$text = str_repeat( 'La casa de mi familia es blanca y muy bonita. ', 30 );

		$correct = KarMCP_Readability::analyze( $text, 'es_ES' );
		$wrong   = KarMCP_Readability::analyze( $text, 'en_US' );

		$this->assertGreaterThan(
			$wrong['score'],
			$correct['score'],
			'the Spanish formula must not be harsher than the English one on Spanish prose'
		);
	}

	public function test_simple_prose_scores_easy_and_dense_prose_scores_hard(): void {
		$simple = KarMCP_Readability::analyze( str_repeat( 'El perro come. La casa es alta. Voy al mar. ', 25 ), 'es_ES' );
		$dense  = KarMCP_Readability::analyze(
			str_repeat(
				'La implementación de procedimientos administrativos extraordinariamente complejos imposibilita frecuentemente la comprensión inmediata de las responsabilidades correspondientes por parte de los interesados. ',
				12
			),
			'es_ES'
		);

		$this->assertGreaterThan( $dense['score'], $simple['score'] );
		$this->assertSame( 'ok', $simple['status'] );
		$this->assertSame( 'ok', $dense['status'] );
	}

	public function test_score_is_clamped_to_the_scale(): void {
		$result = KarMCP_Readability::analyze( str_repeat( 'Sí. No. Ya. Va. Da. ', 40 ), 'es_ES' );

		$this->assertGreaterThanOrEqual( 0, $result['score'] );
		$this->assertLessThanOrEqual( 100, $result['score'] );
	}

	// ------------------------------------------------------------ bands

	public function test_spanish_bands_follow_the_inflesz_scale(): void {
		$this->assertSame( 'muy difícil', KarMCP_Readability::level( 30, 'es' ) );
		$this->assertSame( 'algo difícil', KarMCP_Readability::level( 50, 'es' ) );
		$this->assertSame( 'normal', KarMCP_Readability::level( 60, 'es' ) );
		$this->assertSame( 'bastante fácil', KarMCP_Readability::level( 70, 'es' ) );
		$this->assertSame( 'muy fácil', KarMCP_Readability::level( 85, 'es' ) );
	}

	public function test_english_bands_are_the_flesch_ones(): void {
		$this->assertSame( 'very difficult', KarMCP_Readability::level( 20, 'en' ) );
		$this->assertSame( 'standard', KarMCP_Readability::level( 65, 'en' ) );
		$this->assertSame( 'easy', KarMCP_Readability::level( 90, 'en' ) );
	}
}
