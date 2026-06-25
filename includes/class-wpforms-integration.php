<?php
/**
 * WPForms integration – read-only access to forms and fields.
 *
 * This class NEVER writes to WPForms data. It only reads form structures
 * to build checklist labels for AI analysis and to validate server responses
 * before they are sent to the browser.
 *
 * @package AI_Display_Checklist_WPForms
 */

defined( 'ABSPATH' ) || exit;

class AICWF_WPForms_Integration {

	/**
	 * Check whether WPForms (Lite or Pro) is active.
	 *
	 * @return bool
	 */
	public function is_wpforms_active() {
		return class_exists( 'WPForms' ) || class_exists( 'WPForms_Lite' ) || function_exists( 'wpforms' );
	}

	// -------------------------------------------------------------------------
	// Forms list
	// -------------------------------------------------------------------------

	/**
	 * Return a lightweight list of all WPForms forms: [ {id, title} ].
	 *
	 * @return array|WP_Error
	 */
	public function get_forms() {
		if ( ! $this->is_wpforms_active() ) {
			return new WP_Error( 'wpforms_inactive', __( 'WPForms is not active.', 'ai-checklist-wpf' ) );
		}

		$posts = get_posts(
			array(
				'post_type'      => 'wpforms',
				'posts_per_page' => 200,
				'post_status'    => 'publish',
				'orderby'        => 'title',
				'order'          => 'ASC',
				'fields'         => 'ids',
			)
		);

		$forms = array();
		foreach ( $posts as $form_id ) {
			$post    = get_post( $form_id );
			$forms[] = array(
				'id'    => (int) $form_id,
				'title' => esc_html( $post->post_title ),
			);
		}

		return $forms;
	}

	// -------------------------------------------------------------------------
	// Form fields
	// -------------------------------------------------------------------------

	/**
	 * Return all fields of a single form, categorised by type.
	 *
	 * Response shape per field:
	 *   { id, type, label, choices (for checkbox/radio), is_file_upload }
	 *
	 * @param int $form_id
	 * @return array|WP_Error
	 */
	public function get_form_fields( $form_id ) {
		$form_id = absint( $form_id );

		if ( ! $this->is_wpforms_active() ) {
			return new WP_Error( 'wpforms_inactive', __( 'WPForms is not active.', 'ai-checklist-wpf' ) );
		}

		$form_data = $this->get_form_data( $form_id );
		if ( is_wp_error( $form_data ) ) {
			return $form_data;
		}

		$fields = array();
		foreach ( (array) ( $form_data['fields'] ?? array() ) as $field ) {
			$type    = $field['type'] ?? '';
			$entry   = array(
				'id'             => (int) ( $field['id'] ?? 0 ),
				'type'           => esc_html( $type ),
				'label'          => esc_html( $field['label'] ?? '' ),
				'is_file_upload' => in_array( $type, array( 'file-upload', 'signature' ), true ),
				'is_checkbox'    => in_array( $type, array( 'checkbox', 'radio' ), true ),
				'choices'        => array(),
			);

			if ( $entry['is_checkbox'] && ! empty( $field['choices'] ) ) {
				foreach ( (array) $field['choices'] as $key => $choice ) {
					$entry['choices'][] = array(
						'key'   => (string) $key,
						'label' => esc_html( $choice['label'] ?? '' ),
					);
				}
			}

			$fields[] = $entry;
		}

		return $fields;
	}

	// -------------------------------------------------------------------------
	// Checklist label extraction
	// -------------------------------------------------------------------------

	/**
	 * Build a flat list of all choice labels across the specified checkbox fields.
	 *
	 * Return shape: [ { field_id, choice_key, label } ]
	 *
	 * @param int   $form_id
	 * @param int[] $field_ids  IDs of the checkbox fields to include.
	 * @return array|WP_Error
	 */
	public function get_checklist_labels( $form_id, array $field_ids ) {
		$form_id = absint( $form_id );

		if ( ! $this->is_wpforms_active() ) {
			return new WP_Error( 'wpforms_inactive', __( 'WPForms is not active.', 'ai-checklist-wpf' ) );
		}

		if ( empty( $field_ids ) ) {
			return new WP_Error( 'no_fields', __( 'No checklist field IDs configured.', 'ai-checklist-wpf' ) );
		}

		$form_data = $this->get_form_data( $form_id );
		if ( is_wp_error( $form_data ) ) {
			return $form_data;
		}

		$labels      = array();
		$target_ids  = array_map( 'absint', $field_ids );

		foreach ( (array) ( $form_data['fields'] ?? array() ) as $field ) {
			$fid  = (int) ( $field['id'] ?? 0 );
			$type = $field['type'] ?? '';

			if ( ! in_array( $fid, $target_ids, true ) ) {
				continue;
			}

			if ( ! in_array( $type, array( 'checkbox', 'radio' ), true ) ) {
				continue;
			}

			foreach ( (array) ( $field['choices'] ?? array() ) as $key => $choice ) {
				$label = trim( $choice['label'] ?? '' );
				if ( '' === $label ) {
					continue;
				}
				$labels[] = array(
					'field_id'   => $fid,
					'choice_key' => (string) $key,
					'label'      => sanitize_text_field( $label ),
				);
			}
		}

		return $labels;
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Load and decode a WPForms form's post_content.
	 *
	 * @param int $form_id
	 * @return array|WP_Error  Decoded form data array.
	 */
	private function get_form_data( $form_id ) {
		$post = get_post( $form_id );

		if ( ! $post || 'wpforms' !== get_post_type( $post ) ) {
			return new WP_Error(
				'form_not_found',
				sprintf(
					/* translators: %d: form ID */
					__( 'WPForms form #%d not found.', 'ai-checklist-wpf' ),
					$form_id
				)
			);
		}

		// WPForms stores form data as JSON in post_content.
		// Use WPForms' own decoder if available.
		if ( function_exists( 'wpforms_decode' ) ) {
			$data = wpforms_decode( $post->post_content );
		} else {
			$data = json_decode( $post->post_content, true );
		}

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'form_decode_error', __( 'Could not decode WPForms form data.', 'ai-checklist-wpf' ) );
		}

		return $data;
	}
}
