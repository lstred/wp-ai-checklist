<?php
/**
 * OpenAI GPT-4o vision provider.
 *
 * Uses wp_remote_post (no cURL dependency) with a 60-second timeout.
 * The API key is never written to any log or output buffer.
 *
 * @package AI_Display_Checklist_WPForms
 */

defined( 'ABSPATH' ) || exit;

class AICWF_OpenAI_Provider extends AICWF_AI_Provider_Interface {

	/** OpenAI completions endpoint. */
	const API_URL = 'https://api.openai.com/v1/chat/completions';

	/** @var string */
	private $api_key;

	/** @var string */
	private $model;

	/**
	 * @param string $api_key  OpenAI API key (sk-…). Never logged or echoed.
	 * @param string $model    Model ID. Defaults to gpt-4o.
	 */
	public function __construct( $api_key, $model = 'gpt-4o' ) {
		$this->api_key = $api_key;
		$this->model   = in_array( $model, array( 'gpt-4o', 'gpt-4o-mini', 'gpt-4-turbo' ), true )
			? $model
			: 'gpt-4o';
	}

	// -------------------------------------------------------------------------
	// Interface implementation
	// -------------------------------------------------------------------------

	/**
	 * {@inheritdoc}
	 */
	public function analyze_image( $image_path, array $checklist_labels, array $options = array() ) {
		if ( empty( $this->api_key ) ) {
			return new WP_Error( 'no_api_key', __( 'OpenAI API key is not configured.', 'ai-checklist-wpf' ) );
		}

		// Encode image as base64 data URI.
		$image_data = @file_get_contents( $image_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $image_data ) {
			return new WP_Error( 'read_error', __( 'Could not read the uploaded image file.', 'ai-checklist-wpf' ) );
		}

		$mime      = $this->get_mime_type( $image_path );
		$base64    = base64_encode( $image_data );
		$data_uri  = "data:{$mime};base64,{$base64}";

		unset( $image_data ); // free memory.

		$prompt  = $this->build_prompt( $checklist_labels );
		$payload = array(
			'model'           => $this->model,
			'messages'        => array(
				array(
					'role'    => 'user',
					'content' => array(
						array(
							'type' => 'text',
							'text' => $prompt,
						),
						array(
							'type'      => 'image_url',
							'image_url' => array(
								'url'    => $data_uri,
								'detail' => 'high',
							),
						),
					),
				),
			),
			'max_tokens'      => 2048,
			'temperature'     => 0,
			'response_format' => array( 'type' => 'json_object' ),
		);

		$response = wp_remote_post(
			self::API_URL,
			array(
				'headers'   => array(
					'Authorization' => 'Bearer ' . $this->api_key,
					'Content-Type'  => 'application/json',
				),
				'body'      => wp_json_encode( $payload ),
				'timeout'   => 90,
				'sslverify' => true,
			)
		);

		unset( $data_uri, $base64 ); // free memory.

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'api_request_failed', $response->get_error_message() );
		}

		$http_code = (int) wp_remote_retrieve_response_code( $response );
		$body_raw  = wp_remote_retrieve_body( $response );
		$body      = json_decode( $body_raw, true );

		if ( 200 !== $http_code ) {
			$msg = isset( $body['error']['message'] )
				? sanitize_text_field( $body['error']['message'] )
				: sprintf( __( 'OpenAI returned HTTP %d.', 'ai-checklist-wpf' ), $http_code );
			return new WP_Error( 'api_error', $msg );
		}

		$content = $body['choices'][0]['message']['content'] ?? '';
		if ( empty( $content ) ) {
			return new WP_Error( 'empty_response', __( 'OpenAI returned an empty response.', 'ai-checklist-wpf' ) );
		}

		return $content;
	}

	/**
	 * {@inheritdoc}
	 */
	public function test_connection() {
		if ( empty( $this->api_key ) ) {
			return new WP_Error( 'no_api_key', __( 'No API key configured.', 'ai-checklist-wpf' ) );
		}

		$response = wp_remote_post(
			self::API_URL,
			array(
				'headers'   => array(
					'Authorization' => 'Bearer ' . $this->api_key,
					'Content-Type'  => 'application/json',
				),
				'body'      => wp_json_encode(
					array(
						'model'      => $this->model,
						'messages'   => array(
							array( 'role' => 'user', 'content' => 'ping' ),
						),
						'max_tokens' => 5,
					)
				),
				'timeout'   => 20,
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'connection_failed', $response->get_error_message() );
		}

		$http_code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 === $http_code ) {
			return true;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$msg  = isset( $body['error']['message'] )
			? sanitize_text_field( $body['error']['message'] )
			: sprintf( __( 'HTTP %d', 'ai-checklist-wpf' ), $http_code );

		return new WP_Error( 'api_error', $msg );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Get the MIME type of an image file using finfo or a fallback.
	 *
	 * @param string $path
	 * @return string
	 */
	private function get_mime_type( $path ) {
		if ( function_exists( 'finfo_open' ) ) {
			$finfo = finfo_open( FILEINFO_MIME_TYPE );
			$mime  = finfo_file( $finfo, $path );
			finfo_close( $finfo );
			return $mime ?: 'image/jpeg';
		}
		// Fallback mapping by extension.
		$ext   = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		$map   = array(
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'png'  => 'image/png',
			'webp' => 'image/webp',
			'gif'  => 'image/gif',
		);
		return $map[ $ext ] ?? 'image/jpeg';
	}
}
