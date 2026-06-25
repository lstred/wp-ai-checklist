<?php
/**
 * Admin UI: settings page, AJAX handlers, and asset enqueuing.
 *
 * @package AI_Display_Checklist_WPForms
 */

defined( 'ABSPATH' ) || exit;

class AICWF_Admin {

	public function __construct() {
		add_action( 'admin_menu',            array( $this, 'register_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Admin AJAX handlers.
		add_action( 'wp_ajax_aicwf_get_forms',       array( $this, 'ajax_get_forms' ) );
		add_action( 'wp_ajax_aicwf_get_form_fields', array( $this, 'ajax_get_form_fields' ) );
		add_action( 'wp_ajax_aicwf_test_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_aicwf_save_settings',   array( $this, 'ajax_save_settings' ) );
	}

	// -------------------------------------------------------------------------
	// Admin menu
	// -------------------------------------------------------------------------

	public function register_admin_menu() {
		add_options_page(
			__( 'AI Display Checklist for WPForms', 'ai-checklist-wpf' ),
			__( 'AI Display Checklist', 'ai-checklist-wpf' ),
			'manage_options',
			'ai-display-checklist',
			array( $this, 'render_settings_page' )
		);
	}

	// -------------------------------------------------------------------------
	// Assets
	// -------------------------------------------------------------------------

	public function enqueue_assets( $hook ) {
		if ( 'settings_page_ai-display-checklist' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'aicwf-admin',
			AICWF_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			AICWF_VERSION
		);

		wp_enqueue_script(
			'aicwf-admin',
			AICWF_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			AICWF_VERSION,
			true
		);

		wp_localize_script(
			'aicwf-admin',
			'aicwfAdmin',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'aicwf_admin' ),
				'mappings'  => AICWF_Settings::get_settings()['mappings'] ?? array(),
				'i18n'      => array(
					'confirmDelete'     => __( 'Delete this mapping?', 'ai-checklist-wpf' ),
					'testingConn'       => __( 'Testing connection…', 'ai-checklist-wpf' ),
					'testSuccess'       => __( 'Connection successful!', 'ai-checklist-wpf' ),
					'testFailed'        => __( 'Connection failed: ', 'ai-checklist-wpf' ),
					'saving'            => __( 'Saving…', 'ai-checklist-wpf' ),
					'saved'             => __( 'Settings saved!', 'ai-checklist-wpf' ),
					'saveFailed'        => __( 'Save failed: ', 'ai-checklist-wpf' ),
					'selectForm'        => __( '— Select a form —', 'ai-checklist-wpf' ),
					'selectField'       => __( '— Select a field —', 'ai-checklist-wpf' ),
					'selectFields'      => __( '— Select fields —', 'ai-checklist-wpf' ),
					'loadingFields'     => __( 'Loading fields…', 'ai-checklist-wpf' ),
					'noUploadFields'    => __( 'No file-upload fields in this form.', 'ai-checklist-wpf' ),
					'noCheckboxFields'  => __( 'No checkbox/checklist fields in this form.', 'ai-checklist-wpf' ),
					'addMapping'        => __( '+ Add Mapping', 'ai-checklist-wpf' ),
					'cancelMapping'     => __( 'Cancel', 'ai-checklist-wpf' ),
				),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Settings page render
	// -------------------------------------------------------------------------

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ai-checklist-wpf' ) );
		}

		$settings       = AICWF_Settings::get_settings();
		$wpforms_active = class_exists( 'WPForms' ) || class_exists( 'WPForms_Lite' ) || function_exists( 'wpforms' );
		$has_api_key    = ! empty( AICWF_Settings::get_api_key( 'openai' ) );
		$has_mappings   = ! empty( $settings['mappings'] );

		?>
		<div class="wrap aicwf-admin-wrap">
			<h1><?php esc_html_e( 'AI Display Checklist for WPForms', 'ai-checklist-wpf' ); ?></h1>

			<?php $this->render_status_banner( $wpforms_active, $has_api_key ); ?>

			<nav class="aicwf-tabs nav-tab-wrapper">
				<a href="#tab-general"  class="nav-tab nav-tab-active" data-tab="general"><?php esc_html_e( 'General Settings', 'ai-checklist-wpf' ); ?></a>
				<a href="#tab-mappings" class="nav-tab" data-tab="mappings"><?php esc_html_e( 'Form Mappings', 'ai-checklist-wpf' ); ?></a>
			</nav>

			<div id="aicwf-notice" class="aicwf-save-notice" style="display:none;"></div>

			<!-- General Settings Tab -->
			<div id="tab-general" class="aicwf-tab-content">
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="aicwf_ai_provider"><?php esc_html_e( 'AI Provider', 'ai-checklist-wpf' ); ?></label></th>
							<td>
								<select id="aicwf_ai_provider" name="aicwf_ai_provider">
									<option value="openai" <?php selected( $settings['ai_provider'], 'openai' ); ?>>OpenAI</option>
									<!-- future: Anthropic, Gemini -->
								</select>
								<p class="description"><?php esc_html_e( 'Additional providers can be added in future versions.', 'ai-checklist-wpf' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="aicwf_openai_model"><?php esc_html_e( 'OpenAI Model', 'ai-checklist-wpf' ); ?></label></th>
							<td>
								<select id="aicwf_openai_model" name="aicwf_openai_model">
									<option value="gpt-4o"      <?php selected( $settings['openai_model'], 'gpt-4o' ); ?>>GPT-4o (recommended)</option>
									<option value="gpt-4o-mini" <?php selected( $settings['openai_model'], 'gpt-4o-mini' ); ?>>GPT-4o mini (faster, cheaper)</option>
									<option value="gpt-4-turbo" <?php selected( $settings['openai_model'], 'gpt-4-turbo' ); ?>>GPT-4 Turbo</option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="aicwf_openai_api_key"><?php esc_html_e( 'OpenAI API Key', 'ai-checklist-wpf' ); ?></label></th>
							<td>
								<input
									type="password"
									id="aicwf_openai_api_key"
									name="aicwf_openai_api_key"
									class="regular-text"
									value="<?php echo $has_api_key ? esc_attr( AICWF_Settings::API_KEY_MASK ) : ''; ?>"
									autocomplete="new-password"
									placeholder="sk-…"
								/>
								<button type="button" class="button aicwf-toggle-key" data-target="aicwf_openai_api_key">
									<?php esc_html_e( 'Show/Hide', 'ai-checklist-wpf' ); ?>
								</button>
								<p class="description">
									<?php if ( $has_api_key ) : ?>
										<span class="aicwf-key-saved">&#10003; <?php esc_html_e( 'API key is saved. Enter a new value to replace it.', 'ai-checklist-wpf' ); ?></span>
									<?php else : ?>
										<?php esc_html_e( 'Enter your OpenAI API key (starts with sk-). It will be stored securely and never displayed again.', 'ai-checklist-wpf' ); ?>
									<?php endif; ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Test Connection', 'ai-checklist-wpf' ); ?></th>
							<td>
								<button type="button" id="aicwf-test-connection" class="button button-secondary">
									<?php esc_html_e( 'Test AI Connection', 'ai-checklist-wpf' ); ?>
								</button>
								<span id="aicwf-test-result" class="aicwf-test-result"></span>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="aicwf_confidence_threshold"><?php esc_html_e( 'Default Confidence Threshold', 'ai-checklist-wpf' ); ?></label></th>
							<td>
								<input
									type="number"
									id="aicwf_confidence_threshold"
									name="aicwf_confidence_threshold"
									value="<?php echo esc_attr( number_format( (float) $settings['confidence_threshold'], 2 ) ); ?>"
									min="0.10" max="1.00" step="0.05"
									class="small-text"
								/> (0.10 – 1.00)
								<p class="description"><?php esc_html_e( 'Minimum AI confidence to accept a checklist match. Can be overridden per mapping.', 'ai-checklist-wpf' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="aicwf_max_file_size_mb"><?php esc_html_e( 'Max Upload Size (MB)', 'ai-checklist-wpf' ); ?></label></th>
							<td>
								<input
									type="number"
									id="aicwf_max_file_size_mb"
									name="aicwf_max_file_size_mb"
									value="<?php echo esc_attr( (int) $settings['max_file_size_mb'] ); ?>"
									min="1" max="50"
									class="small-text"
								/>
								<p class="description"><?php esc_html_e( 'Maximum image file size accepted for analysis.', 'ai-checklist-wpf' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Rate Limiting', 'ai-checklist-wpf' ); ?></th>
							<td>
								<label>
									<?php esc_html_e( 'Max', 'ai-checklist-wpf' ); ?>
									<input
										type="number"
										id="aicwf_rate_limit_requests"
										name="aicwf_rate_limit_requests"
										value="<?php echo esc_attr( (int) $settings['rate_limit_requests'] ); ?>"
										min="1" max="1000"
										class="small-text"
									/>
									<?php esc_html_e( 'requests per', 'ai-checklist-wpf' ); ?>
									<input
										type="number"
										id="aicwf_rate_limit_window"
										name="aicwf_rate_limit_window"
										value="<?php echo esc_attr( (int) $settings['rate_limit_window'] ); ?>"
										min="60" max="86400"
										class="small-text"
									/>
									<?php esc_html_e( 'seconds (per IP).', 'ai-checklist-wpf' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="aicwf_debug_logging"><?php esc_html_e( 'Debug Logging', 'ai-checklist-wpf' ); ?></label></th>
							<td>
								<label>
									<input
										type="checkbox"
										id="aicwf_debug_logging"
										name="aicwf_debug_logging"
										value="1"
										<?php checked( ! empty( $settings['debug_logging'] ) ); ?>
									/>
									<?php esc_html_e( 'Enable debug logging to WordPress error log', 'ai-checklist-wpf' ); ?>
								</label>
								<p class="description aicwf-warning">
									<?php esc_html_e( 'Only enable during troubleshooting. API keys and image data are never logged.', 'ai-checklist-wpf' ); ?>
								</p>
							</td>
						</tr>
					</tbody>
				</table>

				<p class="submit">
					<button type="button" id="aicwf-save-general" class="button button-primary">
						<?php esc_html_e( 'Save Settings', 'ai-checklist-wpf' ); ?>
					</button>
				</p>
			</div><!-- #tab-general -->

			<!-- Form Mappings Tab -->
			<div id="tab-mappings" class="aicwf-tab-content" style="display:none;">
				<?php if ( ! $wpforms_active ) : ?>
					<div class="notice notice-error inline">
						<p><?php esc_html_e( 'WPForms is not active. Please install and activate WPForms to configure mappings.', 'ai-checklist-wpf' ); ?></p>
					</div>
				<?php else : ?>

					<p>
						<button type="button" id="aicwf-add-mapping" class="button button-primary">
							<?php esc_html_e( '+ Add New Mapping', 'ai-checklist-wpf' ); ?>
						</button>
					</p>

					<!-- Add/Edit mapping form (hidden until opened) -->
					<div id="aicwf-mapping-editor" class="aicwf-mapping-editor" style="display:none;">
						<h3 id="aicwf-editor-title"><?php esc_html_e( 'Add Mapping', 'ai-checklist-wpf' ); ?></h3>
						<input type="hidden" id="aicwf-edit-mapping-id" value="" />
						<table class="form-table" role="presentation">
							<tbody>
								<tr>
									<th scope="row"><label for="aicwf-map-label"><?php esc_html_e( 'Mapping Label', 'ai-checklist-wpf' ); ?></label></th>
									<td><input type="text" id="aicwf-map-label" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Showroom Floor Board', 'ai-checklist-wpf' ); ?>" /></td>
								</tr>
								<tr>
									<th scope="row"><label for="aicwf-map-form"><?php esc_html_e( 'WPForms Form', 'ai-checklist-wpf' ); ?></label></th>
									<td>
										<select id="aicwf-map-form">
											<option value=""><?php esc_html_e( '— Loading forms… —', 'ai-checklist-wpf' ); ?></option>
										</select>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="aicwf-map-image-field"><?php esc_html_e( 'Image Upload Field', 'ai-checklist-wpf' ); ?></label></th>
									<td>
										<select id="aicwf-map-image-field" disabled>
											<option value=""><?php esc_html_e( '— Select a form first —', 'ai-checklist-wpf' ); ?></option>
										</select>
										<p class="description"><?php esc_html_e( 'The file-upload field the user will place their photo in.', 'ai-checklist-wpf' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><?php esc_html_e( 'Checklist Fields', 'ai-checklist-wpf' ); ?></th>
									<td>
										<div id="aicwf-checklist-fields-wrap" class="aicwf-checkbox-group">
											<em><?php esc_html_e( 'Select a form to see available checkbox fields.', 'ai-checklist-wpf' ); ?></em>
										</div>
										<p class="description"><?php esc_html_e( 'Select one or more checkbox/checklist fields to compare against the image.', 'ai-checklist-wpf' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><?php esc_html_e( 'Action Mode', 'ai-checklist-wpf' ); ?></th>
									<td>
										<label>
											<input type="radio" name="aicwf-map-action-mode" value="check" checked />
											<?php esc_html_e( 'Check detected items', 'ai-checklist-wpf' ); ?>
										</label>
										<br />
										<label>
											<input type="radio" name="aicwf-map-action-mode" value="uncheck" />
											<?php esc_html_e( 'Uncheck detected items', 'ai-checklist-wpf' ); ?>
										</label>
										<p class="description">
											<?php esc_html_e( '"Check" ticks matching items when they are detected in the photo. "Uncheck" removes the tick.', 'ai-checklist-wpf' ); ?>
										</p>
									</td>
								</tr>
								<tr>
									<th scope="row"><?php esc_html_e( 'Trigger Mode', 'ai-checklist-wpf' ); ?></th>
									<td>
										<label>
											<input type="checkbox" id="aicwf-map-auto-analyze" value="1" />
											<?php esc_html_e( 'Automatically analyze after image upload (no button click required)', 'ai-checklist-wpf' ); ?>
										</label>
									</td>
								</tr>
								<tr id="aicwf-button-label-row">
									<th scope="row"><label for="aicwf-map-button-label"><?php esc_html_e( 'Button Label', 'ai-checklist-wpf' ); ?></label></th>
									<td>
										<input type="text" id="aicwf-map-button-label" class="regular-text" value="<?php esc_attr_e( 'Analyze Image', 'ai-checklist-wpf' ); ?>" />
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="aicwf-map-confidence"><?php esc_html_e( 'Confidence Threshold', 'ai-checklist-wpf' ); ?></label></th>
									<td>
										<input type="number" id="aicwf-map-confidence" value="0.65" min="0.10" max="1.00" step="0.05" class="small-text" />
										<p class="description"><?php esc_html_e( 'Override the default threshold for this mapping.', 'ai-checklist-wpf' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><?php esc_html_e( 'Enable Mapping', 'ai-checklist-wpf' ); ?></th>
									<td>
										<label>
											<input type="checkbox" id="aicwf-map-enabled" value="1" checked />
											<?php esc_html_e( 'Active', 'ai-checklist-wpf' ); ?>
										</label>
									</td>
								</tr>
							</tbody>
						</table>
						<p>
							<button type="button" id="aicwf-save-mapping" class="button button-primary">
								<?php esc_html_e( 'Save Mapping', 'ai-checklist-wpf' ); ?>
							</button>
							<button type="button" id="aicwf-cancel-mapping" class="button button-secondary">
								<?php esc_html_e( 'Cancel', 'ai-checklist-wpf' ); ?>
							</button>
						</p>
					</div><!-- #aicwf-mapping-editor -->

					<!-- Mappings table -->
					<div id="aicwf-mappings-table-wrap">
						<?php $this->render_mappings_table( $settings['mappings'] ?? array() ); ?>
					</div>

				<?php endif; ?>
			</div><!-- #tab-mappings -->

		</div><!-- .wrap -->
		<?php
	}

	// -------------------------------------------------------------------------
	// Mappings table
	// -------------------------------------------------------------------------

	/**
	 * Render the HTML table listing all configured mappings.
	 *
	 * @param array $mappings
	 */
	private function render_mappings_table( array $mappings ) {
		if ( empty( $mappings ) ) {
			echo '<p class="aicwf-no-mappings">' . esc_html__( 'No mappings configured yet. Click "Add New Mapping" to get started.', 'ai-checklist-wpf' ) . '</p>';
			return;
		}
		?>
		<table class="wp-list-table widefat fixed striped aicwf-mappings-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Label', 'ai-checklist-wpf' ); ?></th>
					<th><?php esc_html_e( 'Form ID', 'ai-checklist-wpf' ); ?></th>
					<th><?php esc_html_e( 'Action Mode', 'ai-checklist-wpf' ); ?></th>
					<th><?php esc_html_e( 'Auto-Analyze', 'ai-checklist-wpf' ); ?></th>
					<th><?php esc_html_e( 'Status', 'ai-checklist-wpf' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'ai-checklist-wpf' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $mappings as $mapping ) : ?>
					<tr data-mapping-id="<?php echo esc_attr( $mapping['id'] ?? '' ); ?>">
						<td><?php echo esc_html( $mapping['label'] ?? '(unnamed)' ); ?></td>
						<td><?php echo esc_html( $mapping['form_id'] ?? '—' ); ?></td>
						<td><?php echo esc_html( 'uncheck' === ( $mapping['action_mode'] ?? '' ) ? __( 'Uncheck detected', 'ai-checklist-wpf' ) : __( 'Check detected', 'ai-checklist-wpf' ) ); ?></td>
						<td><?php echo ! empty( $mapping['auto_analyze'] ) ? esc_html__( 'Yes', 'ai-checklist-wpf' ) : esc_html__( 'No', 'ai-checklist-wpf' ); ?></td>
						<td>
							<span class="aicwf-status aicwf-status-<?php echo ! empty( $mapping['enabled'] ) ? 'active' : 'inactive'; ?>">
								<?php echo ! empty( $mapping['enabled'] ) ? esc_html__( 'Active', 'ai-checklist-wpf' ) : esc_html__( 'Inactive', 'ai-checklist-wpf' ); ?>
							</span>
						</td>
						<td>
							<button
								type="button"
								class="button button-small aicwf-edit-mapping"
								data-id="<?php echo esc_attr( $mapping['id'] ?? '' ); ?>"
							><?php esc_html_e( 'Edit', 'ai-checklist-wpf' ); ?></button>
							<button
								type="button"
								class="button button-small aicwf-delete-mapping"
								data-id="<?php echo esc_attr( $mapping['id'] ?? '' ); ?>"
							><?php esc_html_e( 'Delete', 'ai-checklist-wpf' ); ?></button>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	// -------------------------------------------------------------------------
	// Status banner
	// -------------------------------------------------------------------------

	private function render_status_banner( $wpforms_active, $has_api_key ) {
		$issues = array();

		if ( ! $wpforms_active ) {
			$issues[] = __( 'WPForms is not active. The plugin will not function until WPForms is installed and activated.', 'ai-checklist-wpf' );
		}

		if ( ! $has_api_key ) {
			$issues[] = __( 'No AI API key configured. Go to General Settings to add your OpenAI key.', 'ai-checklist-wpf' );
		}

		if ( empty( $issues ) ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( '✓ Plugin configured and ready.', 'ai-checklist-wpf' ) . '</p></div>';
			return;
		}

		foreach ( $issues as $issue ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( $issue ) . '</p></div>';
		}
	}

	// -------------------------------------------------------------------------
	// AJAX handlers
	// -------------------------------------------------------------------------

	public function ajax_get_forms() {
		check_ajax_referer( 'aicwf_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}

		$wpf    = new AICWF_WPForms_Integration();
		$forms  = $wpf->get_forms();

		if ( is_wp_error( $forms ) ) {
			wp_send_json_error( $forms->get_error_message() );
		}

		wp_send_json_success( $forms );
	}

	public function ajax_get_form_fields() {
		check_ajax_referer( 'aicwf_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}

		$form_id = absint( $_POST['form_id'] ?? 0 );
		if ( ! $form_id ) {
			wp_send_json_error( 'Invalid form ID.' );
		}

		$wpf    = new AICWF_WPForms_Integration();
		$fields = $wpf->get_form_fields( $form_id );

		if ( is_wp_error( $fields ) ) {
			wp_send_json_error( $fields->get_error_message() );
		}

		wp_send_json_success( $fields );
	}

	public function ajax_test_connection() {
		check_ajax_referer( 'aicwf_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}

		$settings = AICWF_Settings::get_settings();
		$api_key  = AICWF_Settings::get_api_key( 'openai' );

		if ( empty( $api_key ) ) {
			wp_send_json_error( __( 'No API key configured.', 'ai-checklist-wpf' ) );
		}

		$provider = new AICWF_OpenAI_Provider( $api_key, $settings['openai_model'] ?? 'gpt-4o' );
		$result   = $provider->test_connection();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( __( 'Connected successfully!', 'ai-checklist-wpf' ) );
	}

	public function ajax_save_settings() {
		check_ajax_referer( 'aicwf_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}

		// The settings payload arrives JSON-encoded inside the 'settings' POST key.
		$raw_settings = array();
		if ( ! empty( $_POST['settings'] ) ) {
			// posted as a JSON string from admin.js.
			$raw_settings = json_decode( wp_unslash( $_POST['settings'] ), true );
			if ( ! is_array( $raw_settings ) ) {
				wp_send_json_error( __( 'Invalid settings format.', 'ai-checklist-wpf' ) );
			}
		}

		$result = AICWF_Settings::save_settings( $raw_settings );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( __( 'Settings saved.', 'ai-checklist-wpf' ) );
	}
}
