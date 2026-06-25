<?php
/**
 * Abstract base class (provider interface) for AI vision providers.
 *
 * To add a new provider (e.g. Anthropic, Gemini):
 *  1. Create a class that extends AICWF_AI_Provider_Interface.
 *  2. Implement analyze_image() and test_connection().
 *  3. Register the new slug in AICWF_Image_Analyzer::get_provider().
 *
 * @package AI_Display_Checklist_WPForms
 */

defined( 'ABSPATH' ) || exit;

abstract class AICWF_AI_Provider_Interface {

	/**
	 * Analyse an image and return raw JSON string from the AI.
	 *
	 * @param string $image_path      Absolute filesystem path to the temporary image.
	 * @param array  $checklist_labels  Flat list: [ { field_id, choice_key, label } ].
	 * @param array  $options           Provider-specific overrides.
	 * @return string|WP_Error  Raw JSON string on success, WP_Error on failure.
	 */
	abstract public function analyze_image( $image_path, array $checklist_labels, array $options = array() );

	/**
	 * Verify that the configured API key is valid by making a minimal call.
	 *
	 * @return true|WP_Error
	 */
	abstract public function test_connection();

	/**
	 * Build the structured system/user prompt used for all providers.
	 * Providers may override this for model-specific formatting.
	 *
	 * @param array $checklist_labels  [ { field_id, choice_key, label } ]
	 * @return string  Full prompt text.
	 */
	protected function build_prompt( array $checklist_labels ) {
		$labels_list = '';
		foreach ( $checklist_labels as $item ) {
			$labels_list .= '- ' . $item['label'] . "\n";
		}
		$labels_list = rtrim( $labels_list );

		return <<<PROMPT
You are analyzing a photo of a flooring display board.

Your task: Identify visible display-card names only.

Focus on:
- Vertical card or binder labels
- Individual product or display names printed on cards

Ignore completely (do NOT report these as matched items):
- Brand or manufacturer logos
- Store header signs and banners
- Website URLs and slogans
- Pattern or category tab labels
- Fiber content labels and technical specifications
- Dimensions, icons, and decorative signage
- Repeated brand names that are not individual display cards

Checklist of expected display-card names (compare what you see to this list):
{$labels_list}

Rules:
1. For each visible display card that matches a checklist item → add to "matched_checklist_items".
2. For visible display cards NOT in the checklist → add to "unmatched_visible_cards".
3. For text you deliberately ignored → add to "ignored_text".
4. For uncertain or partial matches → add to "low_confidence".
5. Do NOT invent or guess names. Only report text you actually see.
6. Prefer using the exact checklist label wording when it matches.
7. If a detection could match multiple checklist items without a clear winner, put it in "low_confidence".

Return ONLY valid JSON matching this exact schema. No markdown fences, no explanation:
{
  "matched_checklist_items": [
    {"checklist_label": "string", "detected_text": "string", "confidence": 0.95, "reason": "string"}
  ],
  "unmatched_visible_cards": [
    {"detected_text": "string", "confidence": 0.9, "reason": "string"}
  ],
  "ignored_text": [
    {"text": "string", "reason": "string"}
  ],
  "low_confidence": [
    {"text": "string", "possible_matches": ["string"], "confidence": 0.4, "reason": "string"}
  ]
}
PROMPT;
	}
}
