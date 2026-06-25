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
	 *
	 * For wide images (aspect ratio > 1.3) we split the image into a left half
	 * and a right half, analyse each separately, and merge the results.
	 *
	 * Why: OpenAI caps its internal processing at 2048×2048px regardless of the
	 * input resolution.  A 4284×5712 portrait photo is scaled to ~1535×2048
	 * before tiling, which leaves each ~75px-wide binder spine at only ~27px
	 * in the model's view.  Sending two halves gives each half the full 2048px
	 * budget, roughly doubling per-card pixel coverage.
	 *
	 * For portrait / square images (the common case) we send the full image once.
	 */
	public function analyze_image( $image_path, array $checklist_labels, array $options = array() ) {
		if ( empty( $this->api_key ) ) {
			return new WP_Error( 'no_api_key', __( 'OpenAI API key is not configured.', 'ai-checklist-wpf' ) );
		}

		// Determine image dimensions to decide whether to split.
		$image_info = @getimagesize( $image_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $image_info ) {
			return new WP_Error( 'read_error', __( 'Could not read image dimensions.', 'ai-checklist-wpf' ) );
		}

		list( $orig_w, $orig_h ) = $image_info;

		// Split landscape images (wider than tall, aspect > 1.3) into left/right halves.
		// Display boards are typically photographed in landscape.
		$aspect = ( $orig_h > 0 ) ? $orig_w / $orig_h : 1;
		$should_split = ( $aspect > 1.3 && function_exists( 'imagecreatefromjpeg' ) );

		if ( $should_split ) {
			return $this->analyze_split( $image_path, $image_info, $checklist_labels );
		}

		return $this->analyze_single( $image_path, $checklist_labels );
	}

	/**
	 * Analyse a single image with no splitting.
	 *
	 * @param string $image_path
	 * @param array  $checklist_labels
	 * @return string|WP_Error  Raw JSON string.
	 */
	private function analyze_single( $image_path, array $checklist_labels ) {
		$data_uri = $this->image_to_data_uri( $image_path );
		if ( is_wp_error( $data_uri ) ) {
			return $data_uri;
		}

		$result = $this->call_api( $data_uri, $checklist_labels );
		unset( $data_uri );
		return $result;
	}

	/**
	 * Split the image into left and right halves (with 10% overlap), analyse
	 * each, then merge the two raw JSON results into one combined JSON string.
	 *
	 * @param string $image_path
	 * @param array  $image_info  Result of getimagesize().
	 * @param array  $checklist_labels
	 * @return string|WP_Error  Merged raw JSON string.
	 */
	private function analyze_split( $image_path, array $image_info, array $checklist_labels ) {
		list( $orig_w, $orig_h, $img_type ) = $image_info;

		// Create GD resource.
		$src = $this->gd_load( $image_path, $img_type );
		if ( false === $src ) {
			// GD can't load this format — fall back to single analysis.
			return $this->analyze_single( $image_path, $checklist_labels );
		}

		// Overlap: 10% of width so cards near the centre edge appear in both halves.
		$overlap  = (int) round( $orig_w * 0.10 );
		$half_w   = (int) round( $orig_w / 2 ) + $overlap;

		// Left half.
		$left = imagecreatetruecolor( $half_w, $orig_h );
		imagecopy( $left, $src, 0, 0, 0, 0, $half_w, $orig_h );

		// Right half (starts before centre by $overlap).
		$right_start = (int) round( $orig_w / 2 ) - $overlap;
		$right_w     = $orig_w - $right_start;
		$right       = imagecreatetruecolor( $right_w, $orig_h );
		imagecopy( $right, $src, 0, 0, $right_start, 0, $right_w, $orig_h );

		imagedestroy( $src );

		$left_uri  = $this->gd_to_data_uri( $left,  $img_type );
		$right_uri = $this->gd_to_data_uri( $right, $img_type );
		imagedestroy( $left );
		imagedestroy( $right );

		if ( is_wp_error( $left_uri ) || is_wp_error( $right_uri ) ) {
			// GD encode failed — fall back to sending the original.
			return $this->analyze_single( $image_path, $checklist_labels );
		}

		// Analyse both halves — slightly modified prompt context for each.
		$result_left  = $this->call_api( $left_uri,  $checklist_labels, 'left half of the display board' );
		$result_right = $this->call_api( $right_uri, $checklist_labels, 'right half of the display board' );
		unset( $left_uri, $right_uri );

		if ( is_wp_error( $result_left ) && is_wp_error( $result_right ) ) {
			return $result_left;
		}

		// Merge the two JSON strings into one.
		$merged = $this->merge_json_results( $result_left, $result_right );
		return $merged;
	}

	/**
	 * Make one API call with a data URI image and return the raw JSON content string.
	 *
	 * @param string $data_uri
	 * @param array  $checklist_labels
	 * @param string $context  Optional context hint appended to the prompt (e.g. "left half").
	 * @return string|WP_Error
	 */
	private function call_api( $data_uri, array $checklist_labels, $context = '' ) {
		$prompt = $this->build_prompt( $checklist_labels, $context );

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
			'max_tokens'      => 4096,
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
	 * Merge two raw JSON result strings into one by concatenating all arrays.
	 * Deduplication of matched_checklist_items is handled later by the name matcher.
	 *
	 * @param string|WP_Error $json_a
	 * @param string|WP_Error $json_b
	 * @return string  Merged JSON string.
	 */
	private function merge_json_results( $json_a, $json_b ) {
		$keys   = array( 'matched_checklist_items', 'unmatched_visible_cards', 'ignored_text', 'low_confidence' );
		$merged = array_fill_keys( $keys, array() );

		foreach ( array( $json_a, $json_b ) as $raw ) {
			if ( is_wp_error( $raw ) ) {
				continue;
			}
			$clean = preg_replace( '/^```(?:json)?\s*/m', '', (string) $raw );
			$clean = preg_replace( '/\s*```\s*$/m', '', $clean );
			$data  = json_decode( trim( $clean ), true );
			if ( ! is_array( $data ) ) {
				continue;
			}
			foreach ( $keys as $key ) {
				if ( ! empty( $data[ $key ] ) && is_array( $data[ $key ] ) ) {
					$merged[ $key ] = array_merge( $merged[ $key ], $data[ $key ] );
				}
			}
		}

		return wp_json_encode( $merged );
	}

	// -------------------------------------------------------------------------
	// GD helpers
	// -------------------------------------------------------------------------

	/**
	 * Load an image file into a GD resource.
	 *
	 * @param string $path
	 * @param int    $img_type  IMAGETYPE_* constant.
	 * @return resource|GdImage|false
	 */
	private function gd_load( $path, $img_type ) {
		switch ( $img_type ) {
			case IMAGETYPE_JPEG:
				return @imagecreatefromjpeg( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			case IMAGETYPE_PNG:
				return @imagecreatefrompng( $path );  // phpcs:ignore WordPress.PHP.NoSilencedErrors
			case IMAGETYPE_WEBP:
				return function_exists( 'imagecreatefromwebp' )
					? @imagecreatefromwebp( $path )   // phpcs:ignore WordPress.PHP.NoSilencedErrors
					: false;
			case IMAGETYPE_GIF:
				return @imagecreatefromgif( $path );  // phpcs:ignore WordPress.PHP.NoSilencedErrors
			default:
				return false;
		}
	}

	/**
	 * Encode a GD resource to a base64 data URI.
	 *
	 * @param resource|GdImage $gd
	 * @param int              $img_type  IMAGETYPE_* constant used to choose encoder.
	 * @return string|WP_Error
	 */
	private function gd_to_data_uri( $gd, $img_type ) {
		ob_start();
		// Always encode as JPEG for the API call (smallest payload, widely supported).
		$ok = imagejpeg( $gd, null, 90 );
		$raw = ob_get_clean();

		if ( ! $ok || empty( $raw ) ) {
			return new WP_Error( 'gd_encode_failed', 'Could not encode image crop.' );
		}

		return 'data:image/jpeg;base64,' . base64_encode( $raw );
	}

	/**
	 * Read an image file and return a base64 data URI.
	 *
	 * @param string $image_path
	 * @return string|WP_Error
	 */
	private function image_to_data_uri( $image_path ) {
		$image_data = @file_get_contents( $image_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $image_data ) {
			return new WP_Error( 'read_error', __( 'Could not read the uploaded image file.', 'ai-checklist-wpf' ) );
		}
		$mime     = $this->get_mime_type( $image_path );
		$data_uri = "data:{$mime};base64," . base64_encode( $image_data );
		unset( $image_data );
		return $data_uri;
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
