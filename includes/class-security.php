<?php
/**
 * Security helpers: nonces, capability checks, file validation, rate limiting.
 *
 * @package AI_Display_Checklist_WPForms
 */

defined( 'ABSPATH' ) || exit;

class AICWF_Security {

	/** Allowed MIME types for uploaded images. */
	const ALLOWED_MIME_TYPES = array(
		'image/jpeg',
		'image/png',
		'image/webp',
		'image/gif',
	);

	/** Transient key prefix for rate limiting. */
	const RATE_LIMIT_PREFIX = 'aicwf_rl_';

	// -------------------------------------------------------------------------
	// File validation
	// -------------------------------------------------------------------------

	/**
	 * Validate an uploaded image file from $_FILES.
	 *
	 * @param array $file  Single entry from $_FILES (keys: name, tmp_name, size, error, type).
	 * @return true|WP_Error
	 */
	public function validate_image_file( array $file ) {
		// WordPress upload error codes.
		if ( (int) ( $file['error'] ?? 1 ) !== UPLOAD_ERR_OK ) {
			return new WP_Error(
				'upload_error',
				__( 'File upload failed (error code: ', 'ai-checklist-wpf' ) . ( $file['error'] ?? '?' ) . ').',
				array( 'status' => 400 )
			);
		}

		// Verify the file actually exists on disk.
		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error(
				'invalid_file',
				__( 'Uploaded file could not be verified.', 'ai-checklist-wpf' ),
				array( 'status' => 400 )
			);
		}

		// Enforce maximum file size.
		$settings     = AICWF_Settings::get_settings();
		$max_bytes    = ( (int) ( $settings['max_file_size_mb'] ?? 10 ) ) * 1024 * 1024;
		if ( (int) ( $file['size'] ?? 0 ) > $max_bytes ) {
			return new WP_Error(
				'file_too_large',
				sprintf(
					/* translators: %d: max file size in MB */
					__( 'Image exceeds the maximum allowed size of %d MB.', 'ai-checklist-wpf' ),
					$settings['max_file_size_mb'] ?? 10
				),
				array( 'status' => 400 )
			);
		}

		// Validate MIME type using file content (not the browser-supplied type).
		$real_mime = $this->get_real_mime_type( $file['tmp_name'] );
		if ( ! in_array( $real_mime, self::ALLOWED_MIME_TYPES, true ) ) {
			return new WP_Error(
				'invalid_mime',
				__( 'Only JPEG, PNG, WebP, or GIF images are accepted.', 'ai-checklist-wpf' ),
				array( 'status' => 400 )
			);
		}

		// Validate it is a real image (getimagesize reads headers).
		$image_info = @getimagesize( $file['tmp_name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $image_info ) {
			return new WP_Error(
				'not_an_image',
				__( 'The uploaded file does not appear to be a valid image.', 'ai-checklist-wpf' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Determine the real MIME type of a file by reading its magic bytes.
	 *
	 * Uses finfo_file if available, falls back to wp_check_filetype_and_ext.
	 *
	 * @param string $path  Absolute path to the file.
	 * @return string  MIME type string.
	 */
	private function get_real_mime_type( $path ) {
		if ( function_exists( 'finfo_open' ) ) {
			$finfo = finfo_open( FILEINFO_MIME_TYPE );
			$mime  = finfo_file( $finfo, $path );
			finfo_close( $finfo );
			return $mime;
		}

		// Fallback: WordPress file type check.
		$check = wp_check_filetype_and_ext( $path, basename( $path ) );
		return $check['type'] ?? 'application/octet-stream';
	}

	// -------------------------------------------------------------------------
	// Rate limiting
	// -------------------------------------------------------------------------

	/**
	 * Check whether the current IP has exceeded the configured rate limit.
	 *
	 * Uses WordPress transients (compatible with object cache when configured).
	 *
	 * @return true|WP_Error
	 */
	public function check_rate_limit() {
		$key  = $this->rate_limit_key();
		$hits = (int) get_transient( $key );

		$settings = AICWF_Settings::get_settings();
		$limit    = (int) ( $settings['rate_limit_requests'] ?? 10 );

		if ( $hits >= $limit ) {
			return new WP_Error(
				'rate_limited',
				__( 'Too many analysis requests. Please wait before trying again.', 'ai-checklist-wpf' ),
				array( 'status' => 429 )
			);
		}

		return true;
	}

	/**
	 * Increment the rate-limit counter for the current IP.
	 */
	public function record_rate_limit_hit() {
		$settings = AICWF_Settings::get_settings();
		$window   = (int) ( $settings['rate_limit_window'] ?? 3600 );
		$key      = $this->rate_limit_key();
		$hits     = (int) get_transient( $key );

		if ( 0 === $hits ) {
			set_transient( $key, 1, $window );
		} else {
			// set_transient resets the TTL; use update to preserve it.
			// Since WordPress doesn't expose update-without-TTL-reset for transients,
			// we accept a small TTL drift here — acceptable for rate limiting.
			set_transient( $key, $hits + 1, $window );
		}
	}

	/**
	 * Build a hashed transient key for the current visitor.
	 * Combines IP + user ID (if logged in) so logged-in users have their own bucket.
	 *
	 * @return string  Transient key (max 172 chars to be safe).
	 */
	private function rate_limit_key() {
		$ip      = $this->get_client_ip();
		$user_id = get_current_user_id();
		$hash    = hash( 'sha256', $ip . '|' . $user_id );
		return self::RATE_LIMIT_PREFIX . $hash;
	}

	/**
	 * Retrieve the best available client IP address.
	 * Deliberately conservative – only trust headers behind known proxies.
	 *
	 * @return string
	 */
	private function get_client_ip() {
		// If behind a trusted proxy (e.g. Cloudflare, load balancer), REMOTE_ADDR
		// may be the proxy.  We keep it simple and use REMOTE_ADDR as the source
		// of truth; site operators can customise via filter if needed.
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';
		return $ip;
	}
}
