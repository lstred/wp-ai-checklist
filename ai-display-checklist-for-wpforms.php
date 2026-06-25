<?php
/**
 * Plugin Name: AI Display Checklist for WPForms
 * Plugin URI:  https://github.com/lstred/wp-ai-checklist
 * Description: Analyze uploaded images with AI vision to automatically check or uncheck WPForms checklist fields based on detected display-card names.
 * Version:     1.0.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author:      lstred
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ai-checklist-wpf
 * Domain Path: /languages
 *
 * @package AI_Display_Checklist_WPForms
 */

defined( 'ABSPATH' ) || exit;

// Plugin constants.
define( 'AICWF_VERSION',    '1.0.0' );
define( 'AICWF_PLUGIN_FILE', __FILE__ );
define( 'AICWF_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'AICWF_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'AICWF_OPTION_KEY',  'aicwf_settings' );

/**
 * PSR-4-style autoloader for AICWF_ prefixed classes.
 * Maps AICWF_My_Class → includes/class-my-class.php
 */
spl_autoload_register( function ( $class_name ) {
	if ( 0 !== strpos( $class_name, 'AICWF_' ) ) {
		return;
	}
	$suffix    = substr( $class_name, strlen( 'AICWF_' ) );
	$file_slug = strtolower( str_replace( '_', '-', $suffix ) );
	$file      = AICWF_PLUGIN_DIR . 'includes/class-' . $file_slug . '.php';
	if ( file_exists( $file ) ) {
		require_once $file;
	}
} );

/**
 * Bootstrap on plugins_loaded so all plugins (including WPForms) are available.
 */
function aicwf_init() {
	AICWF_Plugin::instance();
}
add_action( 'plugins_loaded', 'aicwf_init' );

/**
 * Activation: seed default options once.
 */
register_activation_hook( __FILE__, 'aicwf_activate' );
function aicwf_activate() {
	if ( false === get_option( AICWF_OPTION_KEY ) ) {
		require_once AICWF_PLUGIN_DIR . 'includes/class-settings.php';
		add_option( AICWF_OPTION_KEY, AICWF_Settings::get_defaults(), '', 'no' );
	}
}
