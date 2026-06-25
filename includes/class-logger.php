<?php
/**
 * Conditional debug logger.
 *
 * All logging is gated behind the debug_logging setting (default: off).
 * This class MUST NEVER log API keys, full base64 image data, or raw
 * form submission values.
 *
 * @package AI_Display_Checklist_WPForms
 */

defined( 'ABSPATH' ) || exit;

class AICWF_Logger {

	/**
	 * Write a debug message to the WordPress error log.
	 *
	 * @param string $message  Message to log (must NOT contain sensitive data).
	 * @param string $level    'debug' | 'warning' | 'error'
	 */
	public static function log( $message, $level = 'debug' ) {
		$settings = AICWF_Settings::get_settings();

		if ( empty( $settings['debug_logging'] ) ) {
			return;
		}

		// Sanitise the message before writing.
		$message = wp_strip_all_tags( (string) $message );

		// Remove anything that looks like an API key (sk-… patterns).
		$message = preg_replace( '/sk-[A-Za-z0-9_\-]{10,}/', '[REDACTED_API_KEY]', $message );

		error_log( '[AICWF][' . strtoupper( $level ) . '] ' . $message );
	}

	/**
	 * Shorthand for error-level messages (always logged when debug is on,
	 * useful pattern to have separate from debug-only logs in future).
	 *
	 * @param string $message
	 */
	public static function error( $message ) {
		self::log( $message, 'error' );
	}
}
