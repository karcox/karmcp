<?php
/**
 * Readability scoring, calibrated per language.
 *
 * Flesch Reading Ease is calibrated on English. Run a Spanish text through it
 * and you get a number that looks valid and is not: Spanish averages more
 * syllables per word, so every page comes out "difficult". The formula has to
 * match the language or the score is worse than no score, because a wrong
 * number gets acted on.
 *
 * For Spanish this uses **Szigriszt-Pazos** (perspicuidad), the modern
 * adaptation of Flesch, read on the INFLESZ scale. The older Fernández Huerta
 * formula is the other common choice; they disagree by a few points and either
 * is defensible, but mixing them across runs is not, so this picks one.
 *
 * Pure: text in, numbers out. No WordPress.
 *
 * @package KarMCP
 * @since   1.15.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Computes a reading-ease score in the right formula for the text's language.
 *
 * @since 1.15.0
 */
class KarMCP_Readability {

	/**
	 * Below this many words the score is noise, not a measurement: one long
	 * sentence swings it by twenty points.
	 */
	const MIN_WORDS = 100;

	/**
	 * Vowels that always carry their own syllable nucleus.
	 */
	const STRONG_VOWELS = array( 'a', 'e', 'o', 'á', 'é', 'ó' );

	/**
	 * Vowels that merge into a neighbouring one unless they carry the accent.
	 */
	const WEAK_VOWELS = array( 'i', 'u', 'ü' );

	/**
	 * An accent on a weak vowel breaks the diphthong: "día" is two syllables,
	 * "dia" would be one.
	 */
	const ACCENTED_WEAK = array( 'í', 'ú' );

	/**
	 * Language families this class can score, keyed by the leading subtag.
	 */
	const SUPPORTED = array( 'es', 'en' );

	/**
	 * Scores a text.
	 *
	 * @param string $text   Visible text.
	 * @param string $locale WordPress locale, e.g. `es_ES` or `en_US`.
	 * @return array {
	 *     @type string $status    ok | insufficient | unsupported
	 *     @type string $language  Resolved language subtag.
	 *     @type string $formula   Formula used.
	 *     @type int    $score     0-100, present when status is ok.
	 *     @type string $level     Human band, present when status is ok.
	 *     @type int    $words
	 *     @type int    $sentences
	 *     @type int    $syllables
	 * }
	 */
	public static function analyze( string $text, string $locale = 'en_US' ): array {
		$language = self::language_of( $locale );

		if ( ! in_array( $language, self::SUPPORTED, true ) ) {
			return array(
				'status'   => 'unsupported',
				'language' => $language,
				'formula'  => '',
			);
		}

		$words     = self::words( $text );
		$sentences = self::count_sentences( $text );

		if ( count( $words ) < self::MIN_WORDS || 0 === $sentences ) {
			return array(
				'status'    => 'insufficient',
				'language'  => $language,
				'formula'   => '',
				'words'     => count( $words ),
				'sentences' => $sentences,
			);
		}

		$syllables = 0;
		foreach ( $words as $word ) {
			$syllables += 'es' === $language
				? self::syllables_es( $word )
				: self::syllables_en( $word );
		}

		$word_count           = count( $words );
		$syllables_per_word   = $syllables / $word_count;
		$words_per_sentence   = $word_count / $sentences;

		if ( 'es' === $language ) {
			// Szigriszt-Pazos (perspicuidad).
			$score   = 206.835 - ( 62.3 * $syllables_per_word ) - $words_per_sentence;
			$formula = 'szigriszt-pazos';
		} else {
			// Flesch Reading Ease.
			$score   = 206.835 - ( 1.015 * $words_per_sentence ) - ( 84.6 * $syllables_per_word );
			$formula = 'flesch';
		}

		$score = (int) round( max( 0, min( 100, $score ) ) );

		return array(
			'status'             => 'ok',
			'language'           => $language,
			'formula'            => $formula,
			'score'              => $score,
			'level'              => self::level( $score, $language ),
			'words'              => $word_count,
			'sentences'          => $sentences,
			'syllables'          => $syllables,
			'words_per_sentence' => round( $words_per_sentence, 1 ),
		);
	}

	/**
	 * The leading subtag of a WordPress locale.
	 *
	 * @param string $locale Locale.
	 * @return string
	 */
	public static function language_of( string $locale ): string {
		$locale = strtolower( trim( $locale ) );
		$parts  = preg_split( '/[_-]/', $locale );

		return is_array( $parts ) && isset( $parts[0] ) ? $parts[0] : '';
	}

	/**
	 * Human band for a score.
	 *
	 * Spanish uses the INFLESZ scale, which is what Szigriszt-Pazos is read on;
	 * English uses the usual Flesch bands. The numbers are not interchangeable,
	 * which is the whole reason this class exists.
	 *
	 * @param int    $score    Score.
	 * @param string $language Language subtag.
	 * @return string
	 */
	public static function level( int $score, string $language ): string {
		if ( 'es' === $language ) {
			if ( $score < 40 ) {
				return 'muy difícil';
			}
			if ( $score < 55 ) {
				return 'algo difícil';
			}
			if ( $score < 65 ) {
				return 'normal';
			}
			if ( $score < 80 ) {
				return 'bastante fácil';
			}
			return 'muy fácil';
		}

		if ( $score < 30 ) {
			return 'very difficult';
		}
		if ( $score < 50 ) {
			return 'difficult';
		}
		if ( $score < 60 ) {
			return 'fairly difficult';
		}
		if ( $score < 70 ) {
			return 'standard';
		}
		if ( $score < 80 ) {
			return 'fairly easy';
		}
		return 'easy';
	}

	/**
	 * Splits text into words, keeping accented letters intact.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	public static function words( string $text ): array {
		preg_match_all( '/[\p{L}\p{N}\'’-]+/u', $text, $matches );

		return isset( $matches[0] ) ? $matches[0] : array();
	}

	/**
	 * Counts sentences.
	 *
	 * A run of terminators counts once — "¿de verdad?!" is one sentence, and an
	 * ellipsis is not three.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	public static function count_sentences( string $text ): int {
		$text = trim( $text );
		if ( '' === $text ) {
			return 0;
		}

		$count = preg_match_all( '/[.!?…]+(?:\s|$)/u', $text );
		$count = is_int( $count ) ? $count : 0;

		// Text that never terminates is still one sentence — a heading-only
		// page, or a final line with no full stop.
		return max( 1, $count );
	}

	/**
	 * Counts the syllables of one Spanish word.
	 *
	 * Spanish is regular here in a way English is not: vowel groups, diphthongs
	 * and hiatus follow rules that can be implemented exactly, with no
	 * dictionary. That makes this an exact count rather than the heuristic every
	 * English implementation has to settle for.
	 *
	 * @param string $word Word.
	 * @return int At least 1.
	 */
	public static function syllables_es( string $word ): int {
		$word = self::normalize_word( $word );
		if ( '' === $word ) {
			return 1;
		}

		// An h between two vowels is silent and does not break the group:
		// "ahijado" behaves as "aijado".
		$word = preg_replace( '/(?<=[aeiouáéíóúü])h(?=[aeiouáéíóúü])/u', '', $word );

		// A y after a vowel is a weak vowel ("rey", "muy"); anywhere else it is
		// a consonant, except when it is the whole word.
		$word = preg_replace( '/(?<=[aeiouáéíóúü])y/u', 'i', $word );
		if ( 'y' === $word ) {
			return 1;
		}

		preg_match_all( '/[aeiouáéíóúü]+/u', $word, $matches );
		$groups = isset( $matches[0] ) ? $matches[0] : array();

		if ( empty( $groups ) ) {
			return 1;
		}

		$syllables = 0;

		foreach ( $groups as $group ) {
			$vowels = self::split_chars( $group );
			$count  = 1;

			for ( $i = 1, $len = count( $vowels ); $i < $len; $i++ ) {
				if ( self::is_hiatus( $vowels[ $i - 1 ], $vowels[ $i ] ) ) {
					++$count;
				}
			}

			$syllables += $count;
		}

		return max( 1, $syllables );
	}

	/**
	 * Whether two adjacent vowels sit in separate syllables.
	 *
	 * Two strong vowels never merge ("caer"), and an accented weak vowel breaks
	 * the diphthong it would otherwise form ("país", "día").
	 *
	 * @param string $first  First vowel.
	 * @param string $second Second vowel.
	 * @return bool
	 */
	private static function is_hiatus( string $first, string $second ): bool {
		if ( in_array( $first, self::ACCENTED_WEAK, true ) || in_array( $second, self::ACCENTED_WEAK, true ) ) {
			return true;
		}

		return in_array( $first, self::STRONG_VOWELS, true ) && in_array( $second, self::STRONG_VOWELS, true );
	}

	/**
	 * Counts the syllables of one English word.
	 *
	 * Heuristic, and unavoidably so: English spelling needs a dictionary to be
	 * exact. Good enough for a reading score, which is a band and not a
	 * measurement.
	 *
	 * @param string $word Word.
	 * @return int At least 1.
	 */
	public static function syllables_en( string $word ): int {
		$word = self::normalize_word( $word );
		if ( '' === $word ) {
			return 1;
		}

		$count = preg_match_all( '/[aeiouy]+/u', $word );
		$count = is_int( $count ) ? $count : 0;

		// Trailing silent e: "make" is one syllable, not two. "the" keeps its
		// only vowel group because of the floor below.
		if ( preg_match( '/[^aeiou]e$/u', $word ) ) {
			--$count;
		}

		return max( 1, $count );
	}

	/**
	 * Lowercases a word and strips everything that is not a letter.
	 *
	 * @param string $word Word.
	 * @return string
	 */
	private static function normalize_word( string $word ): string {
		$word = function_exists( 'mb_strtolower' ) ? mb_strtolower( $word, 'UTF-8' ) : strtolower( $word );

		return (string) preg_replace( '/[^\p{L}]/u', '', $word );
	}

	/**
	 * Splits a UTF-8 string into characters.
	 *
	 * @param string $value Value.
	 * @return string[]
	 */
	private static function split_chars( string $value ): array {
		$chars = preg_split( '//u', $value, -1, PREG_SPLIT_NO_EMPTY );

		return is_array( $chars ) ? $chars : array();
	}
}
