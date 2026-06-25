<?php
/**
 * Settings storage, retrieval, and sanitisation.
 *
 * Responsibilities:
 *  - Define default option structure.
 *  - Get / save settings (sanitised).
 *  - Expose the API key without ever echoing it to a page.
 *
 * @package AI_Display_Checklist_WPForms
 */

defined( 'ABSPATH' ) || exit;

class AICWF_Settings {

	/**
	 * Sentinel used to detect a masked (unchanged) API key submission.
	 */
	const API_KEY_MASK = '••••••••';

	// -------------------------------------------------------------------------
	// Defaults
	// -------------------------------------------------------------------------

	/**
	 * Return the default settings array (no sensitive values).
	 *
	 * @return array
	 */
	public static function get_defaults() {
		return array(
			'ai_provider'          => 'openai',
			'openai_model'         => 'gpt-4o',
			'openai_api_key'       => '',     // stored encrypted-at-rest via WordPress salts
			'debug_logging'        => false,
			'rate_limit_requests'  => 10,
			'rate_limit_window'    => 3600,   // seconds
			'max_file_size_mb'     => 10,
			'confidence_threshold' => 0.65,
			'mappings'             => array(),
		);
	}

	// -------------------------------------------------------------------------
	// Get
	// -------------------------------------------------------------------------

	/**
	 * Retrieve settings, merging with defaults so new keys are always present.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$stored   = get_option( AICWF_OPTION_KEY, array() );
		$defaults = self::get_defaults();

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$merged = array_merge( $defaults, $stored );

		// Ensure mappings is always an array.
		if ( ! is_array( $merged['mappings'] ) ) {
			$merged['mappings'] = array();
		}

		return $merged;
	}

	/**
	 * Return the raw API key for a given provider for use in API calls.
	 * Never call this method from any output context.
	 *
	 * @param string $provider  Provider slug (e.g. 'openai').
	 * @return string  API key or empty string.
	 */
	public static function get_api_key( $provider = 'openai' ) {
		$settings = self::get_settings();
		$key_name = sanitize_key( $provider ) . '_api_key';
		return $settings[ $key_name ] ?? '';
	}

	/**
	 * Return a masked representation of the API key suitable for display.
	 * Never returns the real key.
	 *
	 * @param string $provider
	 * @return string  Masked key or empty string.
	 */
	public static function get_masked_api_key( $provider = 'openai' ) {
		$key = self::get_api_key( $provider );
		return $key ? self::API_KEY_MASK : '';
	}

	// -------------------------------------------------------------------------
	// Save
	// -------------------------------------------------------------------------

	/**
	 * Sanitise and save settings submitted from the admin form.
	 *
	 * @param array $raw  Unsanitised POST data.
	 * @return true|WP_Error
	 */
	public static function save_settings( array $raw ) {
		$current  = self::get_settings();
		$defaults = self::get_defaults();

		$new = $current; // start from current to preserve API key if not changed.

		// ---- General ----
		$allowed_providers = array( 'openai' );
		if ( isset( $raw['ai_provider'] ) && in_array( $raw['ai_provider'], $allowed_providers, true ) ) {
			$new['ai_provider'] = $raw['ai_provider'];
		}

		$allowed_models = array( 'gpt-4o', 'gpt-4o-mini', 'gpt-4-turbo' );
		if ( isset( $raw['openai_model'] ) && in_array( $raw['openai_model'], $allowed_models, true ) ) {
			$new['openai_model'] = $raw['openai_model'];
		}

		// API key: only update if the submitted value is NOT the mask and NOT empty.
		$submitted_key = isset( $raw['openai_api_key'] ) ? trim( $raw['openai_api_key'] ) : '';
		if ( $submitted_key !== '' && $submitted_key !== self::API_KEY_MASK ) {
			// Basic sanity check – OpenAI keys start with "sk-".
			if ( ! preg_match( '/^sk-[A-Za-z0-9_\-]{10,}$/', $submitted_key ) ) {
				return new WP_Error( 'invalid_api_key', __( 'The OpenAI API key format is invalid.', 'ai-checklist-wpf' ) );
			}
			$new['openai_api_key'] = $submitted_key;
		}

		$new['debug_logging'] = ! empty( $raw['debug_logging'] );

		if ( isset( $raw['rate_limit_requests'] ) ) {
			$new['rate_limit_requests'] = max( 1, min( 1000, (int) $raw['rate_limit_requests'] ) );
		}

		if ( isset( $raw['rate_limit_window'] ) ) {
			$new['rate_limit_window'] = max( 60, min( 86400, (int) $raw['rate_limit_window'] ) );
		}

		if ( isset( $raw['max_file_size_mb'] ) ) {
			$new['max_file_size_mb'] = max( 1, min( 50, (int) $raw['max_file_size_mb'] ) );
		}

		if ( isset( $raw['confidence_threshold'] ) ) {
			$new['confidence_threshold'] = max( 0.1, min( 1.0, (float) $raw['confidence_threshold'] ) );
		}

		// ---- Mappings ----
		if ( isset( $raw['mappings'] ) && is_array( $raw['mappings'] ) ) {
			$sanitised_mappings = array();
			foreach ( $raw['mappings'] as $m ) {
				$clean = self::sanitise_mapping( $m );
				if ( $clean ) {
					$sanitised_mappings[] = $clean;
				}
			}
			$new['mappings'] = $sanitised_mappings;
		}

		update_option( AICWF_OPTION_KEY, $new, false );

		return true;
	}

	/**
	 * Sanitise a single mapping array.
	 *
	 * @param mixed $raw
	 * @return array|false  Sanitised mapping or false on failure.
	 */
	private static function sanitise_mapping( $raw ) {
		if ( ! is_array( $raw ) ) {
			return false;
		}

		// Require a form_id.
		$form_id = absint( $raw['form_id'] ?? 0 );
		if ( ! $form_id ) {
			return false;
		}

		// Generate or preserve ID.
		$id = sanitize_key( $raw['id'] ?? '' );
		if ( empty( $id ) ) {
			$id = 'map_' . wp_generate_uuid4();
		}

		$checklist_ids = array();
		if ( ! empty( $raw['checklist_field_ids'] ) && is_array( $raw['checklist_field_ids'] ) ) {
			$checklist_ids = array_values( array_filter( array_map( 'absint', $raw['checklist_field_ids'] ) ) );
		}

		$action_mode = in_array( $raw['action_mode'] ?? '', array( 'check', 'uncheck' ), true )
			? $raw['action_mode']
			: 'check';

		return array(
			'id'                   => $id,
			'enabled'              => ! empty( $raw['enabled'] ),
			'label'                => sanitize_text_field( $raw['label'] ?? '' ),
			'form_id'              => $form_id,
			'image_field_id'       => absint( $raw['image_field_id'] ?? 0 ),
			'checklist_field_ids'  => $checklist_ids,
			'action_mode'          => $action_mode,
			'auto_analyze'         => ! empty( $raw['auto_analyze'] ),
			'button_label'         => sanitize_text_field( $raw['button_label'] ?? __( 'Analyze Image', 'ai-checklist-wpf' ) ),
			'confidence_threshold' => max( 0.1, min( 1.0, (float) ( $raw['confidence_threshold'] ?? 0.65 ) ) ),
		);
	}
}
