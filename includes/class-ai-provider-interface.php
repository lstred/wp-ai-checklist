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

Your task: Read EVERY visible display-card label in the image — even partially visible, angled, or distant ones. Thoroughness is critical. Missing a card is a worse error than reporting a low-confidence one.

Focus on:
- Vertical card or binder labels (the narrow spine text on each sample binder)
- Individual product/display names — scan the ENTIRE image systematically, left-to-right, top-to-bottom
- Read small text even if you are not fully certain — report it in low_confidence rather than skipping it

Ignore completely (do NOT report these):
- Brand or manufacturer logos (e.g. "nrf select", "Shaw", "Mohawk")
- Store header signs and banners
- Website URLs (.com addresses)
- Slogans (e.g. "The Best in Today's Flooring")
- Pattern or category tab labels
- Fiber content labels ("nylon", "polyester", "bioloop")
- Technical specs (dimensions, weight, oz)
- Decorative signage

Checklist of expected display-card names (compare EVERYTHING you read to this list):
{$labels_list}

Rules:
1. Scan every card systematically. Do not stop after finding some matches.
2. For each visible label that matches a checklist item → matched_checklist_items. Use confidence 0.95 for clear reads, lower for partial/angled text.
3. For visible labels NOT in the checklist → unmatched_visible_cards.
4. For text you deliberately ignored → ignored_text.
5. For text you can partially read or are unsure about → low_confidence. PREFER this over skipping.
6. Do NOT invent names. Only report text you can actually see.
7. Roman numeral variants (II vs 2) and minor spacing differences should still be matched.

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
