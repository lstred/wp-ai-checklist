<?php
/**
 * Orchestrates image analysis:
 *   1. Instantiate the correct AI provider.
 *   2. Call analyze_image() to get raw JSON from the AI.
 *   3. Validate and sanitise the JSON response.
 *   4. Run the name matcher to attach field_id / choice_key to matches.
 *
 * @package AI_Display_Checklist_WPForms
 */

defined( 'ABSPATH' ) || exit;

class AICWF_Image_Analyzer {

	/** @var array Plugin settings. */
	private $settings;

	/** @var AICWF_AI_Provider_Interface|WP_Error */
	private $provider;

	/**
	 * @param array $settings  Full plugin settings array from AICWF_Settings::get_settings().
	 */
	public function __construct( array $settings ) {
		$this->settings = $settings;
		$this->provider = $this->build_provider();
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Analyse a temporary image file against a list of checklist labels.
	 *
	 * @param string $image_path      Absolute path to temporary uploaded file.
	 * @param array  $checklist_data  [ { field_id, choice_key, label } ]
	 * @param array  $mapping         The active mapping configuration (for per-mapping threshold).
	 * @return array|WP_Error  Structured result array or WP_Error.
	 */
	public function analyze( $image_path, array $checklist_data, array $mapping = array() ) {
		if ( is_wp_error( $this->provider ) ) {
			return $this->provider;
		}

		$api_key = AICWF_Settings::get_api_key( $this->settings['ai_provider'] ?? 'openai' );
		if ( empty( $api_key ) ) {
			return new WP_Error(
				'no_api_key',
				__( 'AI API key is not configured. Please add your API key in the plugin settings.', 'ai-checklist-wpf' )
			);
		}

		AICWF_Logger::log(
			'Starting analysis. Image: ' . basename( $image_path )
			. ' | Checklist items: ' . count( $checklist_data )
		);

		// Call the AI provider.
		$raw = $this->provider->analyze_image( $image_path, $checklist_data );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		// Parse and validate JSON structure.
		$parsed = $this->parse_and_validate( $raw );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		// Run server-side name matching to attach field_id/choice_key.
		$threshold = (float) ( $mapping['confidence_threshold'] ?? $this->settings['confidence_threshold'] ?? 0.65 );
		$matcher   = new AICWF_Name_Matcher();
		$result    = $matcher->post_process( $parsed, $checklist_data, $threshold );

		AICWF_Logger::log(
			'Analysis complete. Matched: ' . count( $result['matched_checklist_items'] )
			. ' | Unmatched: ' . count( $result['unmatched_visible_cards'] )
			. ' | Low-conf: ' . count( $result['low_confidence'] )
		);

		return $result;
	}

	// -------------------------------------------------------------------------
	// Provider factory
	// -------------------------------------------------------------------------

	/**
	 * Instantiate the configured AI provider.
	 *
	 * @return AICWF_AI_Provider_Interface|WP_Error
	 */
	private function build_provider() {
		$provider_slug = $this->settings['ai_provider'] ?? 'openai';
		$api_key       = AICWF_Settings::get_api_key( $provider_slug );

		switch ( $provider_slug ) {
			case 'openai':
				return new AICWF_OpenAI_Provider( $api_key, $this->settings['openai_model'] ?? 'gpt-4o' );

			// Future providers:
			// case 'anthropic':
			//     return new AICWF_Anthropic_Provider( $api_key );
			// case 'gemini':
			//     return new AICWF_Gemini_Provider( $api_key );

			default:
				return new WP_Error(
					'unknown_provider',
					sprintf(
						/* translators: %s: provider slug */
						__( 'Unknown AI provider: %s', 'ai-checklist-wpf' ),
						esc_html( $provider_slug )
					)
				);
		}
	}

	// -------------------------------------------------------------------------
	// Response validation
	// -------------------------------------------------------------------------

	/**
	 * Parse the raw AI output string into a validated, sanitised structure.
	 *
	 * @param string $raw_json
	 * @return array|WP_Error
	 */
	private function parse_and_validate( $raw_json ) {
		// Strip markdown code fences the model may add despite instructions.
		$clean = preg_replace( '/^```(?:json)?\s*/m', '', (string) $raw_json );
		$clean = preg_replace( '/\s*```\s*$/m', '', $clean );
		$clean = trim( $clean );

		$data = json_decode( $clean, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			AICWF_Logger::log( 'JSON parse error: ' . json_last_error_msg() );
			return new WP_Error(
				'invalid_json',
				__( 'The AI returned a response that could not be parsed. Please try again.', 'ai-checklist-wpf' )
			);
		}

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'invalid_structure', __( 'AI response has an unexpected structure.', 'ai-checklist-wpf' ) );
		}

		$result = array(
			'matched_checklist_items' => array(),
			'unmatched_visible_cards' => array(),
			'ignored_text'            => array(),
			'low_confidence'          => array(),
		);

		// matched_checklist_items.
		foreach ( (array) ( $data['matched_checklist_items'] ?? array() ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$result['matched_checklist_items'][] = array(
				'checklist_label' => sanitize_text_field( $item['checklist_label'] ?? '' ),
				'detected_text'   => sanitize_text_field( $item['detected_text'] ?? '' ),
				'confidence'      => $this->clamp_confidence( $item['confidence'] ?? 0.5 ),
				'reason'          => sanitize_text_field( $item['reason'] ?? '' ),
			);
		}

		// unmatched_visible_cards.
		foreach ( (array) ( $data['unmatched_visible_cards'] ?? array() ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$result['unmatched_visible_cards'][] = array(
				'detected_text' => sanitize_text_field( $item['detected_text'] ?? '' ),
				'confidence'    => $this->clamp_confidence( $item['confidence'] ?? 0.5 ),
				'reason'        => sanitize_text_field( $item['reason'] ?? '' ),
			);
		}

		// ignored_text.
		foreach ( (array) ( $data['ignored_text'] ?? array() ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$result['ignored_text'][] = array(
				'text'   => sanitize_text_field( $item['text'] ?? '' ),
				'reason' => sanitize_text_field( $item['reason'] ?? '' ),
			);
		}

		// low_confidence.
		foreach ( (array) ( $data['low_confidence'] ?? array() ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$possible = array();
			if ( ! empty( $item['possible_matches'] ) && is_array( $item['possible_matches'] ) ) {
				$possible = array_map( 'sanitize_text_field', $item['possible_matches'] );
			}
			$result['low_confidence'][] = array(
				'text'             => sanitize_text_field( $item['text'] ?? '' ),
				'possible_matches' => $possible,
				'confidence'       => $this->clamp_confidence( $item['confidence'] ?? 0.3 ),
				'reason'           => sanitize_text_field( $item['reason'] ?? '' ),
			);
		}

		return $result;
	}

	/**
	 * Clamp a confidence value to [0.0, 1.0].
	 *
	 * @param mixed $value
	 * @return float
	 */
	private function clamp_confidence( $value ) {
		return min( 1.0, max( 0.0, (float) $value ) );
	}
}
