<?php
/**
 * String normalisation and fuzzy matching for display-card names.
 *
 * Pipeline:
 *  1. Lowercase + trim
 *  2. Normalise Unicode apostrophes and quotes
 *  3. Remove punctuation (preserve hyphens and apostrophes in names)
 *  4. Collapse whitespace
 *  5. Normalise common roman numerals (II→2, III→3 …)
 *  6. Apply conservative OCR corrections
 *
 * Then exact-normalized match → fuzzy match (similar_text + Levenshtein hybrid).
 *
 * @package AI_Display_Checklist_WPForms
 */

defined( 'ABSPATH' ) || exit;

class AICWF_Name_Matcher {

	// -------------------------------------------------------------------------
	// Normalisation
	// -------------------------------------------------------------------------

	/**
	 * Normalise a display-card label for comparison.
	 *
	 * @param string $string
	 * @return string
	 */
	public function normalize( $string ) {
		$string = (string) $string;
		$string = mb_strtolower( $string, 'UTF-8' );
		$string = trim( $string );

		// Normalise Unicode apostrophes / smart quotes to ASCII equivalents.
		$string = str_replace(
			array( "\u{2018}", "\u{2019}", "\u{201A}", "\u{201B}", "\u{2032}" ),
			"'",
			$string
		);
		$string = str_replace(
			array( "\u{201C}", "\u{201D}", "\u{201E}", "\u{201F}", "\u{2033}" ),
			'"',
			$string
		);

		// Remove punctuation except hyphens, apostrophes, and word characters.
		$string = preg_replace( '/[^\w\s\'\-]/u', '', $string );

		// Collapse internal whitespace.
		$string = preg_replace( '/\s+/u', ' ', $string );
		$string = trim( $string );

		// Normalise roman numerals only at word boundaries.
		$string = $this->normalize_roman_numerals( $string );

		return $string;
	}

	/**
	 * Replace common roman numerals with Arabic equivalents.
	 * Only replaces standalone tokens to avoid clobbering real words.
	 *
	 * @param string $string  Already lowercased.
	 * @return string
	 */
	private function normalize_roman_numerals( $string ) {
		// Order matters: longer first to prevent partial replacement.
		$map = array(
			'/\bviii\b/' => '8',
			'/\bvii\b/'  => '7',
			'/\bvi\b/'   => '6',
			'/\biv\b/'   => '4',
			'/\biii\b/'  => '3',
			'/\bii\b/'   => '2',
			// i is too ambiguous (common word/letter); skip.
		);
		return preg_replace( array_keys( $map ), array_values( $map ), $string );
	}

	// -------------------------------------------------------------------------
	// Post-processing of AI result
	// -------------------------------------------------------------------------

	/**
	 * Verify and enrich the AI result against the actual checklist data.
	 *
	 * - Attaches field_id and choice_key to each matched item.
	 * - Moves below-threshold matches to low_confidence.
	 * - Deduplicates (same choice can't be matched twice).
	 * - Tries fuzzy-matching the AI's checklist_label string against the real
	 *   checklist so partial/mis-cased labels from the AI are resolved.
	 *
	 * @param array $ai_result       Structured result from AICWF_Image_Analyzer.
	 * @param array $checklist_data  [ { field_id, choice_key, label } ]
	 * @param float $threshold       Minimum confidence to treat as a match.
	 * @return array  Enriched result.
	 */
	public function post_process( array $ai_result, array $checklist_data, $threshold = 0.65 ) {
		// Build a normalised index: norm_label → checklist item.
		$norm_index = array();
		foreach ( $checklist_data as $item ) {
			$norm                  = $this->normalize( $item['label'] );
			$norm_index[ $norm ]   = $item;
		}

		$verified      = array();
		$seen_keys     = array(); // prevent duplicate field_id:choice_key changes.

		foreach ( $ai_result['matched_checklist_items'] as $match ) {
			$confidence = (float) ( $match['confidence'] ?? 0.0 );

			// Below-threshold items → move to low_confidence.
			if ( $confidence < $threshold ) {
				$ai_result['low_confidence'][] = array(
					'text'             => $match['detected_text'] ?? '',
					'possible_matches' => array( $match['checklist_label'] ?? '' ),
					'confidence'       => $confidence,
					'reason'           => 'Confidence below threshold (' . round( $threshold * 100 ) . '%).',
				);
				continue;
			}

			// Try to map the AI's checklist_label back to an actual checklist item.
			$resolved = $this->resolve_to_checklist_item(
				$match['checklist_label'] ?? '',
				$match['detected_text'] ?? '',
				$norm_index,
				$threshold
			);

			if ( ! $resolved ) {
				// AI mentioned a label that doesn't exist in our checklist → unmatched.
				$ai_result['unmatched_visible_cards'][] = array(
					'detected_text' => $match['detected_text'] ?? '',
					'confidence'    => $confidence,
					'reason'        => 'Could not map to a real checklist item.',
				);
				continue;
			}

			// Deduplicate.
			$dedup_key = $resolved['field_id'] . ':' . $resolved['choice_key'];
			if ( in_array( $dedup_key, $seen_keys, true ) ) {
				continue;
			}
			$seen_keys[] = $dedup_key;

			$verified[] = array(
				'field_id'        => $resolved['field_id'],
				'choice_key'      => $resolved['choice_key'],
				'checklist_label' => $resolved['label'],   // canonical label from WPForms.
				'detected_text'   => $match['detected_text'] ?? '',
				'confidence'      => $confidence,
				'reason'          => $match['reason'] ?? '',
			);
		}

		$ai_result['matched_checklist_items'] = $verified;
		return $ai_result;
	}

	// -------------------------------------------------------------------------
	// Matching helpers
	// -------------------------------------------------------------------------

	/**
	 * Attempt to resolve an AI-supplied label string to a real checklist item.
	 *
	 * Strategy:
	 *  1. Exact normalised match on checklist_label.
	 *  2. Exact normalised match on detected_text.
	 *  3. Best fuzzy match above threshold.
	 *
	 * @param string $ai_label      checklist_label from AI response.
	 * @param string $detected_text detected_text from AI response.
	 * @param array  $norm_index    Normalised index of real checklist items.
	 * @param float  $threshold
	 * @return array|null  Real checklist item or null.
	 */
	private function resolve_to_checklist_item( $ai_label, $detected_text, array $norm_index, $threshold ) {
		// 1 – exact on ai_label.
		$norm_ai = $this->normalize( $ai_label );
		if ( isset( $norm_index[ $norm_ai ] ) ) {
			return $norm_index[ $norm_ai ];
		}

		// 2 – exact on detected_text.
		$norm_dt = $this->normalize( $detected_text );
		if ( isset( $norm_index[ $norm_dt ] ) ) {
			return $norm_index[ $norm_dt ];
		}

		// 3 – fuzzy.
		$best_score = 0.0;
		$best_item  = null;

		foreach ( $norm_index as $norm_label => $item ) {
			$score = $this->fuzzy_score( $norm_ai, $norm_label );
			if ( $score > $best_score ) {
				$best_score = $score;
				$best_item  = $item;
			}
			// Also try against detected text.
			$score2 = $this->fuzzy_score( $norm_dt, $norm_label );
			if ( $score2 > $best_score ) {
				$best_score = $score2;
				$best_item  = $item;
			}
		}

		if ( $best_score >= $threshold && $best_item ) {
			return $best_item;
		}

		return null;
	}

	/**
	 * Compute a 0.0–1.0 similarity score between two normalised strings.
	 *
	 * Uses a blend of similar_text percentage and Levenshtein distance ratio.
	 * Short strings (< 4 chars) require exact match to avoid false positives.
	 *
	 * @param string $a
	 * @param string $b
	 * @return float
	 */
	public function fuzzy_score( $a, $b ) {
		if ( $a === $b ) {
			return 1.0;
		}

		if ( '' === $a || '' === $b ) {
			return 0.0;
		}

		// Very short strings → require exact match.
		if ( mb_strlen( $a ) < 4 || mb_strlen( $b ) < 4 ) {
			return 0.0;
		}

		// similar_text percentage (case already lowered by normalize).
		similar_text( $a, $b, $percent );
		$sim_score = $percent / 100.0;

		// Levenshtein ratio.
		$lev        = levenshtein( $a, $b );
		$max_len    = max( mb_strlen( $a ), mb_strlen( $b ) );
		$lev_score  = 1.0 - ( $lev / $max_len );

		// Weighted average: similar_text is more reliable for OCR variance.
		return ( $sim_score * 0.6 ) + ( $lev_score * 0.4 );
	}
}
