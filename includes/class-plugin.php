<?php
/**
 * Core plugin class – singleton orchestrator.
 *
 * @package AI_Display_Checklist_WPForms
 */

defined( 'ABSPATH' ) || exit;

class AICWF_Plugin {

	/** @var AICWF_Plugin|null Singleton instance. */
	private static $instance = null;

	/**
	 * Mappings collected from rendered forms during page output.
	 * Keyed by mapping ID to prevent duplicates.
	 *
	 * @var array[]
	 */
	private $rendered_mappings = array();

	/**
	 * Return or create the singleton.
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Prevent cloning / unserialization. */
	private function __clone() {}
	public function __wakeup() {}

	private function __construct() {
		$this->init_hooks();
	}

	private function init_hooks() {
		add_action( 'rest_api_init',      array( $this, 'register_rest_routes' ) );

		// Enqueue assets early (during wp_enqueue_scripts) on every front-end page
		// that has at least one active mapping. The JS itself handles the case
		// where the configured form is not present on the page.
		add_action( 'wp_enqueue_scripts', array( $this, 'register_and_enqueue_frontend_assets' ) );

		// Collect rendered form IDs so we can pass only relevant mappings to JS.
		add_action( 'wpforms_frontend_output', array( $this, 'on_wpforms_output' ), 5, 1 );

		// Inject aicwfData via wp_add_inline_script at wp_footer priority 1.
		// This runs before WordPress prints queued footer scripts at priority 20.
		add_action( 'wp_footer', array( $this, 'inject_frontend_data' ), 1 );

		if ( is_admin() ) {
			new AICWF_Admin();
		}
	}

	// -------------------------------------------------------------------------
	// REST routes
	// -------------------------------------------------------------------------

	public function register_rest_routes() {
		// Front-end image analysis (authenticated via nonce, open to all users).
		register_rest_route(
			'ai-checklist/v1',
			'/analyze',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_analyze_request' ),
				'permission_callback' => '__return_true', // nonce checked inside callback.
			)
		);

		// Admin helpers – require manage_options.
		register_rest_route(
			'ai-checklist/v1',
			'/forms',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_get_forms' ),
				'permission_callback' => array( $this, 'admin_permission_check' ),
			)
		);

		register_rest_route(
			'ai-checklist/v1',
			'/form-fields/(?P<form_id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_get_form_fields' ),
				'permission_callback' => array( $this, 'admin_permission_check' ),
				'args'                => array(
					'form_id' => array(
						'validate_callback' => function( $v ) { return is_numeric( $v ) && (int) $v > 0; },
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	public function admin_permission_check() {
		return current_user_can( 'manage_options' );
	}

	// -------------------------------------------------------------------------
	// Front-end asset enqueuing
	// -------------------------------------------------------------------------

	/**
	 * Register and enqueue frontend assets during wp_enqueue_scripts.
	 * We enqueue early (not lazily) so the script tag is definitely in the
	 * queue before wp_footer fires. The JS handles pages where no configured
	 * form is present by exiting silently if aicwfData.mappings is empty.
	 */
	public function register_and_enqueue_frontend_assets() {
		if ( is_admin() ) {
			return;
		}

		$settings = AICWF_Settings::get_settings();
		$has_active = ! empty(
			array_filter(
				$settings['mappings'] ?? array(),
				function ( $m ) {
					return ! empty( $m['enabled'] ) && ! empty( $m['form_id'] );
				}
			)
		);

		if ( ! $has_active ) {
			return;
		}

		wp_enqueue_style(
			'aicwf-frontend',
			AICWF_PLUGIN_URL . 'assets/css/frontend.css',
			array(),
			AICWF_VERSION
		);

		wp_enqueue_script(
			'aicwf-frontend',
			AICWF_PLUGIN_URL . 'assets/js/frontend.js',
			array( 'jquery' ),
			AICWF_VERSION,
			true   // load in footer.
		);
	}

	/**
	 * Fires every time WPForms renders a form on the front end.
	 * Collects matching mappings keyed by ID (deduplicates multi-render).
	 *
	 * @param array $form_data  WPForms form data array.
	 */
	public function on_wpforms_output( $form_data ) {
		if ( is_admin() ) {
			return;
		}

		$form_id  = (int) ( $form_data['id'] ?? 0 );
		if ( ! $form_id ) {
			return;
		}

		$settings = AICWF_Settings::get_settings();
		foreach ( $settings['mappings'] ?? array() as $m ) {
			if ( ! empty( $m['enabled'] ) && (int) ( $m['form_id'] ?? 0 ) === $form_id ) {
				$this->rendered_mappings[ $m['id'] ] = $m;
			}
		}
	}

	/**
	 * Inject aicwfData via wp_add_inline_script at wp_footer priority 1.
	 *
	 * wp_add_inline_script( ..., 'before' ) inserts a <script> tag immediately
	 * before the aicwf-frontend <script> tag when WordPress prints footer
	 * scripts at priority 20.  This is more reliable than wp_localize_script
	 * called from the footer because it does not depend on the script already
	 * being in a "not yet printed" state.
	 *
	 * Mappings: prefer ones collected from rendered forms; fall back to all
	 * active mappings so the button still appears even if wpforms_frontend_output
	 * did not fire (e.g. some page builder / caching configurations).
	 */
	public function inject_frontend_data() {
		if ( is_admin() || ! wp_script_is( 'aicwf-frontend', 'enqueued' ) ) {
			return;
		}

		// Use mappings from rendered forms; fall back to all active mappings.
		if ( ! empty( $this->rendered_mappings ) ) {
			$mappings = array_values( $this->rendered_mappings );
		} else {
			$settings = AICWF_Settings::get_settings();
			$mappings = array_values(
				array_filter(
					$settings['mappings'] ?? array(),
					function ( $m ) {
						return ! empty( $m['enabled'] ) && ! empty( $m['form_id'] );
					}
				)
			);
		}

		if ( empty( $mappings ) ) {
			return;
		}

		$data = array(
			'restUrl'  => esc_url_raw( rest_url( 'ai-checklist/v1/analyze' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'mappings' => $this->build_js_mappings( $mappings ),
			'i18n'     => array(
				'analyzing'      => __( 'Analyzing image, please wait…', 'ai-checklist-wpf' ),
				'analyzeBtn'     => __( 'Analyze Image', 'ai-checklist-wpf' ),
				'error'          => __( 'Analysis failed. Please try again.', 'ai-checklist-wpf' ),
				'noFile'         => __( 'Please upload an image first.', 'ai-checklist-wpf' ),
				'reviewTitle'    => __( 'AI Analysis Results', 'ai-checklist-wpf' ),
				'matchedTitle'   => __( 'Matched &amp; Updated', 'ai-checklist-wpf' ),
				'unmatchedTitle' => __( 'Visible cards not in checklist', 'ai-checklist-wpf' ),
				'lowConfTitle'   => __( 'Low Confidence Detections', 'ai-checklist-wpf' ),
				'ignoredTitle'   => __( 'Ignored Text', 'ai-checklist-wpf' ),
				'noMatches'      => __( 'No checklist items were matched.', 'ai-checklist-wpf' ),
				'dismiss'        => __( 'Dismiss', 'ai-checklist-wpf' ),
				'checked'        => __( 'Checked', 'ai-checklist-wpf' ),
				'unchecked'      => __( 'Unchecked', 'ai-checklist-wpf' ),
				'confidence'     => __( 'Confidence', 'ai-checklist-wpf' ),
			),
		);

		// Output as a plain var assignment BEFORE the script tag so it is available
		// the moment frontend.js executes.
		wp_add_inline_script(
			'aicwf-frontend',
			'var aicwfData = ' . wp_json_encode( $data ) . ';',
			'before'
		);
	}

	/**
	 * Build the JS-safe mappings array (no API keys, sanitised values).
	 *
	 * @param array[] $mappings
	 * @return array[]
	 */
	private function build_js_mappings( array $mappings ) {
		return array_values(
			array_map(
				function ( $m ) {
					return array(
						'id'                  => sanitize_key( $m['id'] ?? '' ),
						'form_id'             => (int) ( $m['form_id'] ?? 0 ),
						'image_field_id'      => (int) ( $m['image_field_id'] ?? 0 ),
						'checklist_field_ids' => array_map( 'intval', $m['checklist_field_ids'] ?? array() ),
						'action_mode'         => in_array( $m['action_mode'] ?? '', array( 'check', 'uncheck' ), true )
							? $m['action_mode']
							: 'check',
						'auto_analyze'        => ! empty( $m['auto_analyze'] ),
						'button_label'        => esc_html( $m['button_label'] ?? __( 'Analyze Image', 'ai-checklist-wpf' ) ),
					);
				},
				$mappings
			)
		);
	}

	// enqueue_frontend_scripts() removed — replaced by
	// register_and_enqueue_frontend_assets() + inject_frontend_data().

	// -------------------------------------------------------------------------
	// REST callbacks
	// -------------------------------------------------------------------------

	/**
	 * POST /wp-json/ai-checklist/v1/analyze
	 * Accepts: multipart/form-data with image file, mapping_id, form_id.
	 */
	public function handle_analyze_request( WP_REST_Request $request ) {
		// Verify WordPress REST nonce.
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Security check failed.', 'ai-checklist-wpf' ),
				array( 'status' => 403 )
			);
		}

		// Rate-limit check.
		$security    = new AICWF_Security();
		$rate_result = $security->check_rate_limit();
		if ( is_wp_error( $rate_result ) ) {
			return $rate_result;
		}

		// Validate mapping reference.
		$mapping_id = sanitize_key( (string) $request->get_param( 'mapping_id' ) );
		$form_id    = absint( $request->get_param( 'form_id' ) );
		$mapping    = $this->find_enabled_mapping( $mapping_id, $form_id );

		if ( ! $mapping ) {
			return new WP_Error(
				'invalid_mapping',
				__( 'Invalid or disabled mapping.', 'ai-checklist-wpf' ),
				array( 'status' => 400 )
			);
		}

		// Resolve the image path: either a direct file upload or a WPForms temp URL.
		$files     = $request->get_file_params();
		$image_url = sanitize_text_field( (string) ( $request->get_param( 'image_url' ) ?? '' ) );

		$tmp_path      = '';
		$tmp_created   = false; // true if we created a temp file from a URL fetch.

		if ( ! empty( $files['image'] ) && (int) ( $files['image']['error'] ?? 1 ) === UPLOAD_ERR_OK ) {
			// Standard file upload.
			$file_check = $security->validate_image_file( $files['image'] );
			if ( is_wp_error( $file_check ) ) {
				return $file_check;
			}
			$tmp_path = $files['image']['tmp_name'];

		} elseif ( ! empty( $image_url ) ) {
			// WPForms Dropzone already uploaded the file; we receive its URL.
			// Only allow URLs from the same site to prevent SSRF.
			$resolved = $this->resolve_local_image_url( $image_url );
			if ( is_wp_error( $resolved ) ) {
				return $resolved;
			}

			$tmp_path    = $resolved;
			$tmp_created = true; // resolved to a local filesystem path.

			// Validate the resolved file.
			$fake_file  = array(
				'tmp_name' => $tmp_path,
				'name'     => basename( $tmp_path ),
				'size'     => filesize( $tmp_path ),
				'error'    => UPLOAD_ERR_OK,
			);
			$file_check = $security->validate_image_file( $fake_file );
			if ( is_wp_error( $file_check ) ) {
				return $file_check;
			}

		} else {
			return new WP_Error(
				'no_image',
				__( 'No valid image was provided.', 'ai-checklist-wpf' ),
				array( 'status' => 400 )
			);
		}

		// Fetch checklist labels from WPForms (read-only).
		$wpf_integration = new AICWF_WPForms_Integration();
		$checklist_data  = $wpf_integration->get_checklist_labels(
			$form_id,
			$mapping['checklist_field_ids'] ?? array()
		);

		if ( is_wp_error( $checklist_data ) ) {
			return $checklist_data;
		}

		if ( empty( $checklist_data ) ) {
			return new WP_Error(
				'no_checklist',
				__( 'No checklist items found for the configured fields.', 'ai-checklist-wpf' ),
				array( 'status' => 400 )
			);
		}

		// Run AI analysis.
		$settings = AICWF_Settings::get_settings();
		$analyzer = new AICWF_Image_Analyzer( $settings );
		$result   = $analyzer->analyze( $tmp_path, $checklist_data, $mapping );

		if ( is_wp_error( $result ) ) {
			AICWF_Logger::log( 'Analysis error: ' . $result->get_error_message() );
			return $result;
		}

		// Record this request toward the rate limit.
		$security->record_rate_limit_hit();

		// Append safe metadata for the front end.
		$result['action_mode'] = in_array( $mapping['action_mode'] ?? '', array( 'check', 'uncheck' ), true )
			? $mapping['action_mode']
			: 'check';
		$result['form_id'] = $form_id;

		return rest_ensure_response( $result );
	}

	/**
	 * Validate that an image_url belongs to this site and resolve it to an
	 * absolute filesystem path (via ABSPATH mapping).
	 * Prevents SSRF by rejecting all external or non-image URLs.
	 *
	 * @param string $url
	 * @return string|WP_Error  Absolute path on success.
	 */
	private function resolve_local_image_url( $url ) {
		$site_url = trailingslashit( site_url() );

		// Must start with the site URL (same origin).
		if ( 0 !== strpos( $url, $site_url ) ) {
			return new WP_Error(
				'external_url',
				__( 'Image URL must be from the same site.', 'ai-checklist-wpf' ),
				array( 'status' => 400 )
			);
		}

		// Map URL to filesystem path.
		$relative = substr( $url, strlen( $site_url ) );
		$abs_path = realpath( ABSPATH . $relative );

		// realpath() returns false for non-existent or path-traversal paths.
		if ( false === $abs_path ) {
			return new WP_Error(
				'file_not_found',
				__( 'Uploaded image file could not be located.', 'ai-checklist-wpf' ),
				array( 'status' => 400 )
			);
		}

		// Ensure the resolved path is still within ABSPATH.
		if ( 0 !== strpos( $abs_path, realpath( ABSPATH ) ) ) {
			return new WP_Error(
				'path_traversal',
				__( 'Invalid image path.', 'ai-checklist-wpf' ),
				array( 'status' => 400 )
			);
		}

		// Validate file extension before handing to full MIME check.
		$allowed_ext = array( 'jpg', 'jpeg', 'png', 'webp', 'gif' );
		$ext         = strtolower( pathinfo( $abs_path, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, $allowed_ext, true ) ) {
			return new WP_Error(
				'invalid_extension',
				__( 'Only image files are accepted.', 'ai-checklist-wpf' ),
				array( 'status' => 400 )
			);
		}

		return $abs_path;
	}

	public function handle_get_forms( WP_REST_Request $request ) {
		$wpf = new AICWF_WPForms_Integration();
		return rest_ensure_response( $wpf->get_forms() );
	}

	public function handle_get_form_fields( WP_REST_Request $request ) {
		$form_id = absint( $request->get_param( 'form_id' ) );
		$wpf     = new AICWF_WPForms_Integration();
		return rest_ensure_response( $wpf->get_form_fields( $form_id ) );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Find an enabled mapping by ID and form_id.
	 *
	 * @param string $mapping_id
	 * @param int    $form_id
	 * @return array|null
	 */
	private function find_enabled_mapping( $mapping_id, $form_id ) {
		$settings = AICWF_Settings::get_settings();
		foreach ( $settings['mappings'] ?? array() as $m ) {
			if (
				sanitize_key( $m['id'] ?? '' ) === $mapping_id &&
				(int) ( $m['form_id'] ?? 0 ) === $form_id &&
				! empty( $m['enabled'] )
			) {
				return $m;
			}
		}
		return null;
	}
}
