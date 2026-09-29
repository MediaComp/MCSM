<?php
/**
 * Admin UI controller.
 *
 * @package MCSnippetManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once MCSM_PLUGIN_DIR . 'includes/class-list-table.php';
require_once MCSM_PLUGIN_DIR . 'includes/class-audit-log-table.php';

class MCSM_Admin {
	/**
	 * Repository.
	 *
	 * @var MCSM_Repository
	 */
	private $repository;

	/**
	 * Constructor.
	 *
	 * @param MCSM_Repository $repository Repository.
	 */
	public function __construct( MCSM_Repository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Register admin hooks.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'add_meta_boxes', array( $this, 'register_local_snippet_metaboxes' ) );
		add_action( 'save_post', array( $this, 'save_local_snippets' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'render_local_snippet_notice' ) );
		add_action( 'admin_notices', array( $this, 'render_php_safe_mode_notice' ) );
		add_action( 'wp_ajax_mcsm_toggle_status', array( $this, 'handle_toggle_status' ) );
		add_action( 'wp_ajax_mcsm_search_content', array( $this, 'handle_content_search' ) );
		add_filter( 'set-screen-option', array( $this, 'set_screen_option' ), 10, 3 );
	}

	/**
	 * Add admin pages.
	 */
	public function register_menu() {
		$hook = add_menu_page(
			__( 'MCSM', 'mcsm' ),
			__( 'MCSM', 'mcsm' ),
			'manage_options',
			'mcsm',
			array( $this, 'render_page' ),
			'dashicons-editor-code',
			81
		);

		$add_hook = add_submenu_page(
			'mcsm',
			__( 'Add Snippet', 'mcsm' ),
			__( 'Add New', 'mcsm' ),
			'manage_options',
			'mcsm-add',
			array( $this, 'render_add_page' )
		);

		$activity_hook = add_submenu_page(
			'mcsm',
			__( 'Activity Log', 'mcsm' ),
			__( 'Activity Log', 'mcsm' ),
			'manage_options',
			'mcsm-activity',
			array( $this, 'render_activity_page' )
		);

		$settings_hook = add_submenu_page(
			'mcsm',
			__( 'Snippet Settings', 'mcsm' ),
			__( 'Settings', 'mcsm' ),
			'manage_options',
			'mcsm-settings',
			array( $this, 'render_settings_page' )
		);

		add_action( "load-{$hook}", array( $this, 'add_screen_options' ) );
		add_action( "load-{$hook}", array( $this, 'handle_actions_before_output' ) );
		add_action( "load-{$add_hook}", array( $this, 'handle_actions_before_output' ) );
		add_action( "load-{$settings_hook}", array( $this, 'handle_actions_before_output' ) );
		add_action( "load-{$activity_hook}", array( $this, 'add_activity_screen_options' ) );
		add_action( "load-{$activity_hook}", array( $this, 'handle_actions_before_output' ) );
	}

	/**
	 * Persist screen options.
	 *
	 * @param bool|int $status Status.
	 * @param string   $option Option.
	 * @param int      $value Value.
	 * @return bool|int
	 */
	public function set_screen_option( $status, $option, $value ) {
		if ( 'mcsm_snippets_per_page' === $option ) {
			return max( 1, min( 100, absint( $value ) ) );
		}

		if ( 'mcsm_activity_per_page' === $option ) {
			return max( 1, min( 100, absint( $value ) ) );
		}

		return $status;
	}

	/**
	 * Add list screen options.
	 */
	public function add_screen_options() {
		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Snippets per page', 'mcsm' ),
				'default' => 20,
				'option'  => 'mcsm_snippets_per_page',
			)
		);
	}

	/**
	 * Add activity log screen options.
	 */
	public function add_activity_screen_options() {
		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Activity entries per page', 'mcsm' ),
				'default' => 20,
				'option'  => 'mcsm_activity_per_page',
			)
		);
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook Current hook.
	 */
	public function enqueue_assets( $hook ) {
		$is_plugin_page = false !== strpos( $hook, 'mcsm' );
		$is_post_screen = $this->is_supported_post_edit_screen();

		if ( ! $is_plugin_page && ! $is_post_screen ) {
			return;
		}

		$code_settings = array();
		$code_types    = array(
			'html'       => 'text/html',
			'javascript' => 'text/javascript',
			'css'        => 'text/css',
			'php'        => 'application/x-httpd-php',
		);

		foreach ( $code_types as $type => $mime ) {
			$settings = wp_enqueue_code_editor( array( 'type' => $mime ) );
			if ( $settings ) {
				$code_settings[ $type ] = $settings;
			}
		}

		wp_enqueue_style( 'mcsm-admin', MCSM_PLUGIN_URL . 'assets/admin.css', array(), MCSM_VERSION );
		wp_enqueue_script( 'mcsm-admin', MCSM_PLUGIN_URL . 'assets/admin.js', array( 'jquery' ), MCSM_VERSION, true );
		wp_localize_script(
			'mcsm-admin',
			'mcsmAdmin',
			array(
				'remove'             => __( 'Remove', 'mcsm' ),
				'statusNonce'        => wp_create_nonce( 'mcsm_toggle_status' ),
				'statusUpdateFailed' => __( 'Could not update the snippet status. Please try again.', 'mcsm' ),
				'activateSnippet'    => __( 'Activate %s', 'mcsm' ),
				'deactivateSnippet'  => __( 'Deactivate %s', 'mcsm' ),
				'active'             => __( 'Active', 'mcsm' ),
				'inactive'           => __( 'Inactive', 'mcsm' ),
				'snippet'            => __( 'Snippet', 'mcsm' ),
				'wordpressHooks'     => __( 'WordPress hooks', 'mcsm' ),
				'deleteSnippet'      => __( 'Delete this snippet? This action cannot be undone.', 'mcsm' ),
				'deleteSelected'     => __( 'Delete the selected snippets? This action cannot be undone.', 'mcsm' ),
				'deleteLocal'        => __( 'Remove this local snippet?', 'mcsm' ),
				'deleteDataWarning'  => __( 'Uninstalling the plugin will permanently delete all global and local snippets. Continue?', 'mcsm' ),
				'clearActivityLog'   => __( 'Permanently clear the entire activity log? This action cannot be undone.', 'mcsm' ),
				'emptyCode'          => __( 'Code is empty. Empty snippets are not rendered.', 'mcsm' ),
				'unmatchedScript'    => __( 'The JavaScript snippet contains unmatched script tags.', 'mcsm' ),
				'orphanScriptClose'  => __( 'A closing script tag was found without an opening tag.', 'mcsm' ),
				'unmatchedStyle'     => __( 'The CSS snippet contains unmatched style tags.', 'mcsm' ),
				'cssContainsScript'  => __( 'This CSS snippet contains a script tag.', 'mcsm' ),
				'htmlMixedCode'      => __( 'HTML snippets are output as entered. Use the JavaScript or CSS type for automatic wrapping.', 'mcsm' ),
				'phpTags'            => __( 'PHP snippets must not include opening or closing PHP tags.', 'mcsm' ),
				'phpBlocked'         => __( 'PHP snippets cannot use exit, die, namespaces, or goto.', 'mcsm' ),
				'contentSearchNonce' => wp_create_nonce( 'mcsm_search_content' ),
				'searchPrompt'       => __( 'Enter at least two characters to search.', 'mcsm' ),
				'searchFailed'       => __( 'Content search failed. Please try again.', 'mcsm' ),
				'noMatches'          => __( 'No matching content found.', 'mcsm' ),
				'codeSettings'       => $code_settings,
			)
		);

		if ( ! empty( $code_settings ) ) {
			wp_enqueue_script( 'code-editor' );
			wp_enqueue_style( 'code-editor' );
		}
	}

	/**
	 * Render the main page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage snippets.', 'mcsm' ) );
		}

		$action = $this->current_action();

		if ( in_array( $action, array( 'add', 'edit' ), true ) ) {
			$this->render_edit_screen();
			return;
		}

		$this->render_list_screen();
	}

	/**
	 * Process mutating actions before WordPress sends admin page output.
	 */
	public function handle_actions_before_output() {
		$action = $this->current_action();

		if ( 'save' === $action ) {
			$this->handle_save();
		}

		if ( 'delete' === $action ) {
			$this->handle_delete();
		}

		if ( 'export_json' === $action ) {
			$this->handle_export_json();
		}

		if ( 'import_json' === $action ) {
			$this->handle_import_json();
		}

		if ( 'save_settings' === $action ) {
			$this->handle_save_settings();
		}

		if ( 'clear_activity_log' === $action ) {
			$this->handle_clear_activity_log();
		}

		$this->handle_bulk_actions();
	}

	/**
	 * Render add submenu.
	 */
	public function render_add_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage snippets.', 'mcsm' ) );
		}

		$this->render_edit_screen();
	}

	/**
	 * Render snippets list.
	 */
	private function render_list_screen() {
		$list_table = new MCSM_List_Table( $this->repository );
		$list_table->prepare_items();
		$add_url = add_query_arg(
			array(
				'page'   => 'mcsm',
				'action' => 'add',
			),
			admin_url( 'admin.php' )
		);
		?>
		<div class="wrap mcsm-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'MCSM', 'mcsm' ); ?></h1>
			<a href="<?php echo esc_url( $add_url ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'mcsm' ); ?></a>
			<hr class="wp-header-end">
			<?php $this->render_messages(); ?>
			<form method="get">
				<input type="hidden" name="page" value="mcsm" />
				<?php
				if ( isset( $_GET['status'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					printf( '<input type="hidden" name="status" value="%s" />', esc_attr( sanitize_key( self::input_string( wp_unslash( $_GET['status'] ) ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				}
				$list_table->search_box( __( 'Search snippets', 'mcsm' ), 'mcsm-search' );
				?>
			</form>
			<form method="post">
				<?php wp_nonce_field( 'mcsm_bulk_action', 'mcsm_bulk_nonce' ); ?>
				<?php $list_table->display(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render settings page.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage snippet settings.', 'mcsm' ) );
		}

		?>
		<div class="wrap mcsm-wrap">
			<h1><?php esc_html_e( 'Snippet Settings', 'mcsm' ); ?></h1>
			<hr class="wp-header-end">
			<?php $this->render_messages(); ?>
			<?php $this->render_tools_panel(); ?>
		</div>
		<?php
	}

	/**
	 * Render the activity log page.
	 */
	public function render_activity_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view the activity log.', 'mcsm' ) );
		}

		$list_table = new MCSM_Audit_Log_Table();
		$list_table->prepare_items();
		?>
		<div class="wrap mcsm-wrap">
			<h1><?php esc_html_e( 'Activity Log', 'mcsm' ); ?></h1>
			<hr class="wp-header-end">
			<?php $this->render_messages(); ?>
			<p class="description"><?php esc_html_e( 'Administrative changes to snippets and plugin settings. Snippet code and visitor data are not stored.', 'mcsm' ); ?></p>
			<form method="get">
				<input type="hidden" name="page" value="mcsm-activity" />
				<?php $list_table->search_box( __( 'Search activity', 'mcsm' ), 'mcsm-activity-search' ); ?>
				<?php $list_table->display(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render add/edit screen.
	 */
	private function render_edit_screen() {
		$id      = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$snippet = $id ? $this->repository->get( $id ) : null;

		if ( $id && ! $snippet ) {
			wp_die( esc_html__( 'Snippet not found.', 'mcsm' ) );
		}

		$snippet = wp_parse_args(
			(array) $snippet,
			array(
				'id'           => 0,
				'name'         => '',
				'type'         => 'html',
				'code'         => '',
				'notes'        => '',
				'location'     => 'header',
				'priority'     => MCSM_Repository::DEFAULT_PRIORITY,
				'display_rule' => 'site_wide',
				'include_ids'  => '',
				'exclude_ids'  => '',
				'devices'      => 'all',
				'status'       => 'active',
				'logged_in_only' => 0,
			)
		);
		if ( in_array( $snippet['display_rule'], array( 'exclude_pages', 'exclude_posts' ), true ) ) {
			$snippet['display_rule'] = 'exclude_selected';
		}

		$title = $id ? __( 'Edit Snippet', 'mcsm' ) : __( 'Add Snippet', 'mcsm' );
		?>
		<div class="wrap mcsm-wrap">
			<h1><?php echo esc_html( $title ); ?></h1>
			<?php $this->render_messages(); ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=mcsm&action=save' ) ); ?>">
				<?php wp_nonce_field( 'mcsm_save_snippet', 'mcsm_save_nonce' ); ?>
				<input type="hidden" name="id" value="<?php echo absint( $snippet['id'] ); ?>" />
				<div class="mcsm-edit-layout">
					<div class="mcsm-main-panel">
						<table class="form-table" role="presentation">
							<tbody>
								<tr>
									<th scope="row"><label for="mcsm-name"><?php esc_html_e( 'Snippet Name', 'mcsm' ); ?></label></th>
									<td><input name="name" id="mcsm-name" type="text" class="regular-text" value="<?php echo esc_attr( $snippet['name'] ); ?>" required /></td>
								</tr>
								<tr>
									<th scope="row"><label for="mcsm-type"><?php esc_html_e( 'Code Type', 'mcsm' ); ?></label></th>
									<td>
										<select name="type" id="mcsm-type">
											<?php foreach ( MCSM_Repository::TYPES as $type ) : ?>
												<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $snippet['type'], $type ); ?>><?php echo esc_html( self::type_label( $type ) ); ?></option>
											<?php endforeach; ?>
										</select>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="mcsm-display-rule"><?php esc_html_e( 'Display Rule', 'mcsm' ); ?></label></th>
									<td>
										<select name="display_rule" id="mcsm-display-rule">
											<?php foreach ( $this->get_display_rule_options() as $rule => $label ) : ?>
												<option value="<?php echo esc_attr( $rule ); ?>" <?php selected( $snippet['display_rule'], $rule ); ?>><?php echo esc_html( $label ); ?></option>
											<?php endforeach; ?>
										</select>
									</td>
								</tr>
								<tr class="mcsm-rule-row" data-rule-row="site_wide">
									<th scope="row"><label for="mcsm-exclude-ids"><?php esc_html_e( 'Exclude', 'mcsm' ); ?></label></th>
									<td>
										<?php $this->render_content_picker( 'exclude_ids', $snippet['exclude_ids'], $this->get_picker_post_types( 'all' ), 'mcsm-exclude-ids', __( 'Search pages, posts, or CPT entries...', 'mcsm' ) ); ?>
										<p class="description"><?php esc_html_e( 'Select content where this global snippet should not render.', 'mcsm' ); ?></p>
									</td>
								</tr>
								<tr class="mcsm-rule-row" data-rule-row="specific_pages">
									<th scope="row"><label for="mcsm-page-list"><?php esc_html_e( 'Page List', 'mcsm' ); ?></label></th>
									<td>
										<?php $this->render_content_picker( 'include_ids', $snippet['include_ids'], array( 'page' ), 'mcsm-page-list', __( 'Search pages...', 'mcsm' ) ); ?>
									</td>
								</tr>
								<tr class="mcsm-rule-row" data-rule-row="specific_posts">
									<th scope="row"><label for="mcsm-post-list"><?php esc_html_e( 'Posts List', 'mcsm' ); ?></label></th>
									<td>
										<?php $this->render_content_picker( 'include_ids', $snippet['include_ids'], array( 'post' ), 'mcsm-post-list', __( 'Search posts...', 'mcsm' ) ); ?>
									</td>
								</tr>
								<?php foreach ( $this->get_picker_post_types( 'cpt' ) as $post_type ) : ?>
									<?php $rule = 'specific_cpt_' . $post_type; ?>
									<tr class="mcsm-rule-row" data-rule-row="<?php echo esc_attr( $rule ); ?>">
										<th scope="row"><label for="<?php echo esc_attr( 'mcsm-' . $post_type . '-list' ); ?>"><?php echo esc_html( $this->get_post_type_label( $post_type ) ); ?></label></th>
										<td>
											<?php $this->render_content_picker( 'include_ids', $snippet['include_ids'], array( $post_type ), 'mcsm-' . $post_type . '-list', sprintf( __( 'Search %s...', 'mcsm' ), strtolower( $this->get_post_type_label( $post_type ) ) ) ); ?>
										</td>
									</tr>
								<?php endforeach; ?>
								<tr class="mcsm-location-row">
									<th scope="row"><label for="mcsm-location"><?php esc_html_e( 'Location', 'mcsm' ); ?></label></th>
									<td>
										<select name="location" id="mcsm-location">
											<?php foreach ( MCSM_Repository::LOCATIONS as $location ) : ?>
												<option value="<?php echo esc_attr( $location ); ?>" <?php selected( $snippet['location'], $location ); ?>><?php echo esc_html( self::location_label( $location ) ); ?></option>
											<?php endforeach; ?>
										</select>
										<p class="description mcsm-body-open-help"><?php esc_html_e( 'Body works only when the active theme calls wp_body_open() immediately after the opening body tag.', 'mcsm' ); ?></p>
										<ul class="mcsm-help-list">
											<li><?php esc_html_e( 'Header = before closing </head>', 'mcsm' ); ?></li>
											<li><?php esc_html_e( 'Body = immediately after opening <body>', 'mcsm' ); ?></li>
											<li><?php esc_html_e( 'Footer = before closing </body>', 'mcsm' ); ?></li>
											<li><?php esc_html_e( 'Shortcode Only = render only where the snippet shortcode is placed', 'mcsm' ); ?></li>
										</ul>
									</td>
								</tr>
								<tr class="mcsm-priority-row">
									<th scope="row"><label for="mcsm-priority"><?php esc_html_e( 'Priority', 'mcsm' ); ?></label></th>
									<td>
										<input name="priority" id="mcsm-priority" type="number" min="<?php echo esc_attr( MCSM_Repository::MIN_PRIORITY ); ?>" max="<?php echo esc_attr( MCSM_Repository::MAX_PRIORITY ); ?>" step="1" value="<?php echo esc_attr( MCSM_Repository::sanitize_priority( $snippet['priority'] ) ); ?>" />
										<p class="description"><?php esc_html_e( 'Lower numbers run earlier on the selected WordPress hook. Default: 20.', 'mcsm' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="mcsm-devices"><?php esc_html_e( 'Devices', 'mcsm' ); ?></label></th>
									<td>
										<select name="devices" id="mcsm-devices">
											<?php foreach ( MCSM_Repository::DEVICES as $device ) : ?>
												<option value="<?php echo esc_attr( $device ); ?>" <?php selected( $snippet['devices'], $device ); ?>><?php echo esc_html( self::device_label( $device ) ); ?></option>
											<?php endforeach; ?>
										</select>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="mcsm-status"><?php esc_html_e( 'Status', 'mcsm' ); ?></label></th>
									<td>
										<div class="mcsm-toggle-setting">
											<label class="mcsm-form-toggle">
												<input class="mcsm-form-toggle-input mcsm-state-toggle" type="checkbox" name="status" id="mcsm-status" value="active" data-on-label="<?php esc_attr_e( 'Active', 'mcsm' ); ?>" data-off-label="<?php esc_attr_e( 'Inactive', 'mcsm' ); ?>" <?php checked( $snippet['status'], 'active' ); ?> />
												<span class="mcsm-form-toggle-track" aria-hidden="true">
													<span class="mcsm-form-toggle-thumb"></span>
												</span>
												<span class="mcsm-form-toggle-label mcsm-toggle-state"><?php echo esc_html( 'active' === $snippet['status'] ? __( 'Active', 'mcsm' ) : __( 'Inactive', 'mcsm' ) ); ?></span>
											</label>
											<p class="description"><?php esc_html_e( 'Enable or disable this snippet.', 'mcsm' ); ?></p>
										</div>
									</td>
								</tr>
							</tbody>
						</table>
						<h2><label for="mcsm-code"><?php esc_html_e( 'Code', 'mcsm' ); ?></label></h2>
						<div class="mcsm-validation-hints" aria-live="polite"></div>
						<div class="mcsm-code-editor-shell<?php echo 'php' === $snippet['type'] ? ' is-php' : ''; ?>">
							<div class="mcsm-php-prefix" aria-hidden="true">&lt;?php</div>
							<textarea name="code" id="mcsm-code" class="large-text code" rows="18"><?php echo esc_textarea( $snippet['code'] ); ?></textarea>
						</div>
						<?php submit_button( $id ? __( 'Update Snippet', 'mcsm' ) : __( 'Create Snippet', 'mcsm' ) ); ?>
					</div>
						<div class="mcsm-side-panel">
							<div class="mcsm-visibility-section">
								<h2><?php esc_html_e( 'Visibility', 'mcsm' ); ?></h2>
								<label class="mcsm-form-toggle">
									<input class="mcsm-form-toggle-input mcsm-state-toggle" type="checkbox" name="logged_in_only" value="1" data-on-label="<?php esc_attr_e( 'Logged-in Only', 'mcsm' ); ?>" data-off-label="<?php esc_attr_e( 'All Visitors', 'mcsm' ); ?>" <?php checked( ! empty( $snippet['logged_in_only'] ) ); ?> />
									<span class="mcsm-form-toggle-track" aria-hidden="true">
										<span class="mcsm-form-toggle-thumb"></span>
									</span>
									<span class="mcsm-form-toggle-label mcsm-toggle-state"><?php echo esc_html( ! empty( $snippet['logged_in_only'] ) ? __( 'Logged-in Only', 'mcsm' ) : __( 'All Visitors', 'mcsm' ) ); ?></span>
								</label>
								<p class="description"><?php esc_html_e( 'Choose who can see this snippet on the frontend.', 'mcsm' ); ?></p>
								<hr>
							</div>
							<div class="mcsm-shortcode-section"<?php echo 'php' === $snippet['type'] ? ' hidden' : ''; ?>>
								<h2><?php esc_html_e( 'Shortcode', 'mcsm' ); ?></h2>
								<?php if ( $id ) : ?>
									<code class="mcsm-shortcode">[mc_snippet id="<?php echo absint( $id ); ?>"]</code>
									<p class="description"><?php esc_html_e( 'Shortcodes respect status, device targeting, and visibility. Location and display rules are skipped because shortcode placement is manual.', 'mcsm' ); ?></p>
								<?php else : ?>
									<p><?php esc_html_e( 'Save the snippet to generate its shortcode.', 'mcsm' ); ?></p>
								<?php endif; ?>
								<hr>
							</div>
							<?php if ( $id ) : ?>
								<h2><?php esc_html_e( 'Snippet Info', 'mcsm' ); ?></h2>
							<p class="mcsm-info-sentence">
								<?php
								printf(
									/* translators: 1: user name, 2: date/time. */
									wp_kses_post( __( 'Created by <strong>%1$s</strong> on %2$s.', 'mcsm' ) ),
									esc_html( $this->user_display_name( absint( $snippet['created_by'] ?? 0 ) ) ),
									esc_html( self::format_datetime( $snippet['created_at'] ?? '' ) )
								);
								?>
							</p>
							<p class="mcsm-info-sentence">
								<?php
								printf(
									/* translators: 1: user name, 2: date/time. */
									wp_kses_post( __( 'Last updated by <strong>%1$s</strong> on %2$s.', 'mcsm' ) ),
									esc_html( $this->user_display_name( absint( $snippet['updated_by'] ?? 0 ) ) ),
									esc_html( self::format_datetime( $snippet['updated_at'] ?? '' ) )
								);
								?>
							</p>
							<p class="mcsm-info-sentence">
								<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'mcsm-activity', 's' => $snippet['name'] ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'View activity', 'mcsm' ); ?></a>
							</p>
							<?php endif; ?>
							<hr>
							<h2><label for="mcsm-notes"><?php esc_html_e( 'Notes / Description', 'mcsm' ); ?></label></h2>
							<textarea name="notes" id="mcsm-notes" class="widefat" rows="6" placeholder="<?php esc_attr_e( 'Internal notes for this snippet...', 'mcsm' ); ?>"><?php echo esc_textarea( $snippet['notes'] ); ?></textarea>
						</div>
					</div>
				</form>
		</div>
		<?php
	}

	/**
	 * Save snippet.
	 */
	private function handle_save() {
		check_admin_referer( 'mcsm_save_snippet', 'mcsm_save_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to save snippets.', 'mcsm' ) );
		}

		if ( ! current_user_can( 'unfiltered_html' ) ) {
			wp_die( esc_html__( 'You need the unfiltered_html capability to save executable snippet code.', 'mcsm' ) );
		}

		$id       = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$previous = $id ? $this->repository->get( $id ) : null;
		if ( $id && ! $previous ) {
			wp_die( esc_html__( 'The snippet you are trying to update no longer exists.', 'mcsm' ) );
		}

		$data = array(
			'name'         => isset( $_POST['name'] ) ? wp_unslash( $_POST['name'] ) : '',
			'type'         => isset( $_POST['type'] ) ? self::input_string( wp_unslash( $_POST['type'] ), 'html' ) : 'html',
			'display_rule' => isset( $_POST['display_rule'] ) ? wp_unslash( $_POST['display_rule'] ) : 'site_wide',
			'include_ids'  => isset( $_POST['include_ids'] ) ? wp_unslash( $_POST['include_ids'] ) : '',
			'exclude_ids'  => isset( $_POST['exclude_ids'] ) ? wp_unslash( $_POST['exclude_ids'] ) : '',
			'location'     => isset( $_POST['location'] ) ? wp_unslash( $_POST['location'] ) : 'header',
			'priority'     => isset( $_POST['priority'] ) ? wp_unslash( $_POST['priority'] ) : MCSM_Repository::DEFAULT_PRIORITY,
			'devices'      => isset( $_POST['devices'] ) ? wp_unslash( $_POST['devices'] ) : 'all',
			'status'       => isset( $_POST['status'] ) ? self::input_string( wp_unslash( $_POST['status'] ), 'inactive' ) : 'inactive',
			'logged_in_only' => isset( $_POST['logged_in_only'] ) ? 1 : 0,
			'code'         => isset( $_POST['code'] ) ? wp_unslash( $_POST['code'] ) : '',
			'notes'        => isset( $_POST['notes'] ) ? wp_unslash( $_POST['notes'] ) : '',
		);

		if ( 'php' === sanitize_key( $data['type'] ) ) {
			$validation = MCSM_PHP_Executor::validate( $data['code'] );
			if ( is_wp_error( $validation ) ) {
				wp_die(
					esc_html( $validation->get_error_message() ),
					esc_html__( 'Invalid PHP Snippet', 'mcsm' ),
					array( 'back_link' => true )
				);
			}
		}

		if ( 'active' === sanitize_key( $data['status'] ) ) {
			$validation = $this->validate_snippet_for_activation( $data );
			if ( is_wp_error( $validation ) ) {
				wp_die(
					esc_html( $validation->get_error_message() ),
					esc_html__( 'Snippet Activation Failed', 'mcsm' ),
					array( 'back_link' => true )
				);
			}
		}

		$saved_id = $this->repository->save( $data, $id );
		$message  = $id ? 'updated' : 'created';

		if ( ! $saved_id ) {
			$message = 'error';
			$saved_id = $id;
		} else {
			$saved = $this->repository->get( $saved_id );
			if ( $saved ) {
				MCSM_Audit_Log::record(
					$id ? 'snippet_updated' : 'snippet_created',
					array(
						'object_type'  => 'global_snippet',
						'object_id'    => $saved_id,
						'snippet_id'   => $saved_id,
						'snippet_name' => $saved['name'],
						'summary'      => $id ? __( 'Snippet updated.', 'mcsm' ) : __( 'Snippet created.', 'mcsm' ),
						'changes'      => $id ? $this->snippet_changes( $previous, $saved ) : $this->created_snippet_details( $saved ),
					)
				);

				if ( $id && ( $previous['status'] ?? '' ) !== ( $saved['status'] ?? '' ) ) {
					MCSM_Audit_Log::record(
						'active' === $saved['status'] ? 'snippet_activated' : 'snippet_deactivated',
						array(
							'object_type'  => 'global_snippet',
							'object_id'    => $saved_id,
							'snippet_id'   => $saved_id,
							'snippet_name' => $saved['name'],
							'summary'      => 'active' === $saved['status'] ? __( 'Snippet activated while saving.', 'mcsm' ) : __( 'Snippet deactivated while saving.', 'mcsm' ),
							'changes'      => array(
								'status' => array(
									'from' => $previous['status'],
									'to'   => $saved['status'],
								),
							),
						)
					);
				}
			}
		}

		$redirect = add_query_arg(
			array(
				'page'    => 'mcsm',
				'action'  => $saved_id ? 'edit' : 'add',
				'id'      => absint( $saved_id ),
				'message' => $message,
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Toggle a snippet status from the list table.
	 */
	public function handle_toggle_status() {
		check_ajax_referer( 'mcsm_toggle_status', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to update snippets.', 'mcsm' ) ), 403 );
		}

		$id     = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$status = isset( $_POST['status'] ) ? sanitize_key( self::input_string( wp_unslash( $_POST['status'] ) ) ) : '';

		$snippet = $id ? $this->repository->get( $id ) : null;
		if ( ! $id || ! in_array( $status, MCSM_Repository::STATUSES, true ) || ! $snippet ) {
			wp_send_json_error( array( 'message' => __( 'Invalid snippet or status.', 'mcsm' ) ), 400 );
		}

		if ( 'active' === $status && ! current_user_can( 'unfiltered_html' ) ) {
			wp_send_json_error( array( 'message' => __( 'You need the unfiltered_html capability to activate executable snippets.', 'mcsm' ) ), 403 );
		}

		if ( 'active' === $status ) {
			$validation = $this->validate_snippet_for_activation( $snippet );
			if ( is_wp_error( $validation ) ) {
				wp_send_json_error( array( 'message' => $validation->get_error_message() ), 400 );
			}
		}

		if ( ! $this->repository->update_status_many( array( $id ), $status ) ) {
			wp_send_json_error( array( 'message' => __( 'The snippet status could not be saved.', 'mcsm' ) ), 500 );
		}

		if ( $status !== ( $snippet['status'] ?? '' ) ) {
			MCSM_Audit_Log::record(
				'active' === $status ? 'snippet_activated' : 'snippet_deactivated',
				array(
					'object_type'  => 'global_snippet',
					'object_id'    => $id,
					'snippet_id'   => $id,
					'snippet_name' => $snippet['name'],
					'summary'      => 'active' === $status ? __( 'Snippet activated.', 'mcsm' ) : __( 'Snippet deactivated.', 'mcsm' ),
					'changes'      => array(
						'status' => array(
							'from' => $snippet['status'],
							'to'   => $status,
						),
					),
				)
			);
		}

		wp_send_json_success(
			array(
				'id'          => $id,
				'status'      => $status,
				'modified_at' => self::format_datetime( current_time( 'mysql' ) ),
			)
		);
	}

	/**
	 * Search public content for the snippet display-rule picker.
	 */
	public function handle_content_search() {
		check_ajax_referer( 'mcsm_search_content', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to search content.', 'mcsm' ) ), 403 );
		}

		$query = isset( $_POST['query'] ) ? sanitize_text_field( self::input_string( wp_unslash( $_POST['query'] ) ) ) : '';
		if ( strlen( $query ) < 2 ) {
			wp_send_json_success( array( 'items' => array() ) );
		}

		$requested_types = isset( $_POST['post_types'] ) ? (array) wp_unslash( $_POST['post_types'] ) : array();
		$public_types    = array_diff( get_post_types( array( 'public' => true ), 'names' ), array( 'attachment' ) );
		$post_types      = array_values( array_intersect( array_map( 'sanitize_key', $requested_types ), $public_types ) );

		if ( empty( $post_types ) ) {
			wp_send_json_success( array( 'items' => array() ) );
		}

		$post_ids = get_posts(
			array(
				'post_type'              => $post_types,
				'post_status'            => array( 'publish', 'private', 'draft', 'pending', 'future' ),
				'posts_per_page'         => 20,
				's'                      => $query,
				'orderby'                => 'relevance',
				'order'                  => 'DESC',
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$items = array();
		foreach ( $post_ids as $post_id ) {
			$post_type = get_post_type_object( get_post_type( $post_id ) );
			if ( ! $post_type ) {
				continue;
			}

			$title   = get_the_title( $post_id );
			$items[] = array(
				'id'    => absint( $post_id ),
				'title' => $title ? $title : sprintf( __( '#%d (no title)', 'mcsm' ), absint( $post_id ) ),
				'type'  => $post_type->labels->singular_name,
			);
		}

		wp_send_json_success( array( 'items' => $items ) );
	}

	/**
	 * Delete one snippet.
	 */
	private function handle_delete() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		check_admin_referer( 'mcsm_delete_snippet_' . $id );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to delete snippets.', 'mcsm' ) );
		}

		$snippet = $id ? $this->repository->get( $id ) : null;
		$this->repository->delete_many( array( $id ) );
		if ( $snippet && ! $this->repository->get( $id ) ) {
			MCSM_Audit_Log::record(
				'snippet_deleted',
				array(
					'object_type'  => 'global_snippet',
					'object_id'    => $id,
					'snippet_id'   => $id,
					'snippet_name' => $snippet['name'],
					'summary'      => __( 'Snippet deleted.', 'mcsm' ),
					'changes'      => array(
						'type'           => $snippet['type'],
						'logged_in_only' => absint( $snippet['logged_in_only'] ),
						'status'         => $snippet['status'],
						'location'       => 'php' === $snippet['type'] ? 'wordpress_hooks' : $snippet['location'],
						'priority'       => MCSM_Repository::sanitize_priority( $snippet['priority'] ?? MCSM_Repository::DEFAULT_PRIORITY ),
					),
				)
			);
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'mcsm',
					'message' => 'deleted',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Process bulk actions.
	 */
	private function handle_bulk_actions() {
		if ( empty( $_POST['action'] ) && empty( $_POST['action2'] ) ) {
			return;
		}

		check_admin_referer( 'mcsm_bulk_action', 'mcsm_bulk_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to update snippets.', 'mcsm' ) );
		}

		$primary_action = self::input_string( wp_unslash( $_POST['action'] ?? '-1' ), '-1' );
		$action         = '-1' !== $primary_action
			? sanitize_key( $primary_action )
			: sanitize_key( self::input_string( wp_unslash( $_POST['action2'] ?? '-1' ), '-1' ) );
		$ids    = isset( $_POST['snippet_ids'] ) ? $this->repository->sanitize_ids_array( wp_unslash( $_POST['snippet_ids'] ) ) : array();

		if ( empty( $ids ) || '-1' === $action ) {
			return;
		}

		$snippets = array();
		foreach ( $ids as $id ) {
			$snippet = $this->repository->get( $id );
			if ( $snippet ) {
				$snippets[ $id ] = $snippet;
			}
		}

		if ( 'activate' === $action ) {
			if ( ! current_user_can( 'unfiltered_html' ) ) {
				wp_die( esc_html__( 'You need the unfiltered_html capability to activate executable snippets.', 'mcsm' ) );
			}
			foreach ( $ids as $id ) {
				$snippet = $this->repository->get( $id );
				if ( ! $snippet ) {
					continue;
				}

				$validation = $this->validate_snippet_for_activation( $snippet );
				if ( is_wp_error( $validation ) ) {
					wp_die(
						esc_html( $validation->get_error_message() ),
						esc_html__( 'Snippet Activation Failed', 'mcsm' ),
						array( 'back_link' => true )
					);
				}
			}

			$updated = $this->repository->update_status_many( $ids, 'active' );
			if ( $updated ) {
				$this->record_bulk_status_activity( $snippets, 'active' );
			}
			$message = 'bulk-activated';
		} elseif ( 'deactivate' === $action ) {
			$updated = $this->repository->update_status_many( $ids, 'inactive' );
			if ( $updated ) {
				$this->record_bulk_status_activity( $snippets, 'inactive' );
			}
			$message = 'bulk-deactivated';
		} elseif ( 'delete' === $action ) {
			$this->repository->delete_many( $ids );
			foreach ( $snippets as $id => $snippet ) {
				if ( ! $this->repository->get( $id ) ) {
					MCSM_Audit_Log::record(
						'snippet_deleted',
						array(
							'object_type'  => 'global_snippet',
							'object_id'    => $id,
							'snippet_id'   => $id,
							'snippet_name' => $snippet['name'],
							'summary'      => __( 'Snippet deleted by bulk action.', 'mcsm' ),
							'changes'      => array(
								'type'           => $snippet['type'],
								'logged_in_only' => absint( $snippet['logged_in_only'] ),
								'status'         => $snippet['status'],
								'location'       => 'php' === $snippet['type'] ? 'wordpress_hooks' : $snippet['location'],
								'priority'       => MCSM_Repository::sanitize_priority( $snippet['priority'] ?? MCSM_Repository::DEFAULT_PRIORITY ),
							),
						)
					);
				}
			}
			$message = 'bulk-deleted';
		} else {
			return;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'mcsm',
					'message' => $message,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Record status changes performed as a bulk action.
	 *
	 * @param array  $snippets Snippets keyed by ID.
	 * @param string $status New status.
	 */
	private function record_bulk_status_activity( $snippets, $status ) {
		foreach ( $snippets as $id => $snippet ) {
			if ( $status === ( $snippet['status'] ?? '' ) ) {
				continue;
			}

			MCSM_Audit_Log::record(
				'active' === $status ? 'snippet_activated' : 'snippet_deactivated',
				array(
					'object_type'  => 'global_snippet',
					'object_id'    => $id,
					'snippet_id'   => $id,
					'snippet_name' => $snippet['name'],
					'summary'      => 'active' === $status ? __( 'Snippet activated by bulk action.', 'mcsm' ) : __( 'Snippet deactivated by bulk action.', 'mcsm' ),
					'changes'      => array(
						'status' => array(
							'from' => $snippet['status'],
							'to'   => $status,
						),
					),
				)
			);
		}
	}

	/**
	 * Describe changed snippet fields without storing executable code.
	 *
	 * @param array $previous Previous record.
	 * @param array $current Current record.
	 * @return array
	 */
	private function snippet_changes( $previous, $current ) {
		$fields  = array( 'name', 'type', 'location', 'priority', 'display_rule', 'include_ids', 'exclude_ids', 'devices', 'status', 'logged_in_only' );
		$changes = array();

		foreach ( $fields as $field ) {
			$from = $previous[ $field ] ?? '';
			$to   = $current[ $field ] ?? '';
			if ( (string) $from !== (string) $to ) {
				$changes[ $field ] = array(
					'from' => $from,
					'to'   => $to,
				);
			}
		}

		if ( (string) ( $previous['code'] ?? '' ) !== (string) ( $current['code'] ?? '' ) ) {
			$changes['code'] = true;
		}

		if ( (string) ( $previous['notes'] ?? '' ) !== (string) ( $current['notes'] ?? '' ) ) {
			$changes['notes'] = true;
		}

		return $changes;
	}

	/**
	 * Describe a newly created snippet without storing its code.
	 *
	 * @param array $snippet Snippet record.
	 * @return array
	 */
	private function created_snippet_details( $snippet ) {
		return array(
			'type'           => $snippet['type'] ?? '',
			'status'         => $snippet['status'] ?? '',
			'logged_in_only' => absint( $snippet['logged_in_only'] ?? 0 ),
			'location'       => $snippet['location'] ?? '',
			'priority'       => MCSM_Repository::sanitize_priority( $snippet['priority'] ?? MCSM_Repository::DEFAULT_PRIORITY ),
		);
	}

	/**
	 * Build changes between two flat settings arrays.
	 *
	 * @param array $previous Previous values.
	 * @param array $current Current values.
	 * @return array
	 */
	private function simple_changes( $previous, $current ) {
		$changes = array();

		foreach ( $current as $key => $value ) {
			$from = $previous[ $key ] ?? '';
			if ( (string) $from !== (string) $value ) {
				$changes[ $key ] = array(
					'from' => $from,
					'to'   => $value,
				);
			}
		}

		return $changes;
	}

	/**
	 * Describe local snippet changes without storing executable code.
	 *
	 * @param array $previous Previous snippets.
	 * @param array $current Current snippets.
	 * @return array
	 */
	private function local_snippet_changes( $previous, $current ) {
		$strip_code = static function ( $snippets ) {
			$metadata = array();
			foreach ( $snippets as $snippet ) {
				if ( ! is_array( $snippet ) ) {
					continue;
				}
				unset( $snippet['code'] );
				$metadata[] = $snippet;
			}
			return $metadata;
		};
		$previous_code = array_map(
			static function ( $snippet ) {
				return is_array( $snippet ) ? (string) ( $snippet['code'] ?? '' ) : '';
			},
			$previous
		);
		$current_code = array_map(
			static function ( $snippet ) {
				return is_array( $snippet ) ? (string) ( $snippet['code'] ?? '' ) : '';
			},
			$current
		);
		$changes = array(
			'before_count' => count( $previous ),
			'after_count'  => count( $current ),
		);

		if ( wp_json_encode( $previous_code ) !== wp_json_encode( $current_code ) ) {
			$changes['code'] = true;
		}

		if ( wp_json_encode( $strip_code( $previous ) ) !== wp_json_encode( $strip_code( $current ) ) ) {
			$changes['settings'] = true;
		}

		return $changes;
	}

	/**
	 * Current action.
	 *
	 * @return string
	 */
	private function current_action() {
		if ( isset( $_REQUEST['action'] ) && '-1' !== self::input_string( $_REQUEST['action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return sanitize_key( self::input_string( wp_unslash( $_REQUEST['action'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		if ( isset( $_REQUEST['action2'] ) && '-1' !== self::input_string( $_REQUEST['action2'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return sanitize_key( self::input_string( wp_unslash( $_REQUEST['action2'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		return '';
	}

	/**
	 * Render admin messages.
	 */
	private function render_messages() {
		$message = isset( $_GET['message'] ) ? sanitize_key( self::input_string( wp_unslash( $_GET['message'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$map     = array(
			'created'          => __( 'Snippet created.', 'mcsm' ),
			'updated'          => __( 'Snippet updated.', 'mcsm' ),
			'deleted'          => __( 'Snippet deleted.', 'mcsm' ),
			'imported'         => __( 'Import completed. Invalid or unsupported records were skipped.', 'mcsm' ),
			'settings-saved'   => __( 'Settings saved.', 'mcsm' ),
			'activity-cleared' => __( 'Activity log cleared.', 'mcsm' ),
			'bulk-activated'   => __( 'Selected snippets activated.', 'mcsm' ),
			'bulk-deactivated' => __( 'Selected snippets deactivated.', 'mcsm' ),
			'bulk-deleted'     => __( 'Selected snippets deleted.', 'mcsm' ),
			'error'            => __( 'The snippet could not be saved.', 'mcsm' ),
		);

		if ( isset( $map[ $message ] ) ) {
			$class = 'error' === $message ? 'notice notice-error' : 'notice notice-success is-dismissible';
			printf( '<div class="%s"><p>%s</p></div>', esc_attr( $class ), esc_html( $map[ $message ] ) );
		}
	}

	/**
	 * Render import/export/settings tools.
	 */
	private function render_tools_panel() {
		$export_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'   => 'mcsm-settings',
					'action' => 'export_json',
				),
				admin_url( 'admin.php' )
			),
			'mcsm_export_json'
		);
		$delete_on_uninstall = get_option( 'mcsm_delete_data_on_uninstall', 'no' );
		$output_comments     = get_option( 'mcsm_output_comments', 'no' );
		$activity_retention  = absint( get_option( MCSM_Audit_Log::RETENTION_OPTION, 180 ) );
		?>
		<div class="mcsm-settings-layout">
			<section class="mcsm-settings-section">
				<div class="mcsm-settings-section-header">
					<h2><?php esc_html_e( 'Settings', 'mcsm' ); ?></h2>
				</div>
				<form method="post" class="mcsm-settings-form" action="<?php echo esc_url( admin_url( 'admin.php?page=mcsm-settings&action=save_settings' ) ); ?>">
					<?php wp_nonce_field( 'mcsm_save_settings', 'mcsm_settings_nonce' ); ?>
					<input type="hidden" name="settings_scope" value="general" />
					<div class="mcsm-setting-row">
						<strong class="mcsm-setting-name"><?php esc_html_e( 'Delete data on uninstall', 'mcsm' ); ?></strong>
						<div class="mcsm-setting-control">
							<label class="mcsm-form-toggle">
								<input class="mcsm-form-toggle-input" type="checkbox" name="delete_data_on_uninstall" value="yes" <?php checked( $delete_on_uninstall, 'yes' ); ?> />
								<span class="mcsm-form-toggle-track" aria-hidden="true">
									<span class="mcsm-form-toggle-thumb"></span>
								</span>
							</label>
							<p class="description"><?php esc_html_e( 'Remove all snippets, activity history, and plugin settings when the plugin is deleted.', 'mcsm' ); ?></p>
						</div>
					</div>
					<div class="mcsm-setting-row">
						<strong class="mcsm-setting-name"><?php esc_html_e( 'Output identification markers', 'mcsm' ); ?></strong>
						<div class="mcsm-setting-control">
							<label class="mcsm-form-toggle">
								<input class="mcsm-form-toggle-input" type="checkbox" name="output_comments" value="yes" <?php checked( $output_comments, 'yes' ); ?> />
								<span class="mcsm-form-toggle-track" aria-hidden="true">
									<span class="mcsm-form-toggle-thumb"></span>
								</span>
							</label>
							<p class="description"><?php esc_html_e( 'Add HTML comments containing the snippet name and ID around frontend output. Keep disabled on production sites unless needed for diagnostics.', 'mcsm' ); ?></p>
						</div>
					</div>
					<div class="mcsm-settings-submit">
						<?php submit_button( __( 'Save Settings', 'mcsm' ), 'primary', 'submit', false ); ?>
					</div>
				</form>
			</section>
			<section class="mcsm-settings-section">
				<div class="mcsm-settings-section-header">
					<h2><?php esc_html_e( 'Activity Log', 'mcsm' ); ?></h2>
				</div>
				<div class="mcsm-settings-actions">
					<form class="mcsm-settings-action-row" method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=mcsm-settings&action=save_settings' ) ); ?>">
						<?php wp_nonce_field( 'mcsm_save_settings', 'mcsm_settings_nonce' ); ?>
						<input type="hidden" name="settings_scope" value="activity" />
						<label class="mcsm-retention-setting" for="mcsm-activity-retention">
							<strong><?php esc_html_e( 'Activity log retention', 'mcsm' ); ?></strong>
							<select name="activity_log_retention" id="mcsm-activity-retention">
								<?php foreach ( array( 30, 90, 180, 365 ) as $days ) : ?>
									<option value="<?php echo absint( $days ); ?>" <?php selected( $activity_retention, $days ); ?>>
										<?php echo esc_html( sprintf( _n( '%d day', '%d days', $days, 'mcsm' ), $days ) ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</label>
						<?php submit_button( __( 'Save Retention', 'mcsm' ), 'secondary', 'submit', false ); ?>
					</form>
					<div class="mcsm-settings-action-row mcsm-settings-action-buttons">
						<div class="mcsm-settings-inline-actions">
							<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=mcsm-activity' ) ); ?>"><?php esc_html_e( 'View Activity', 'mcsm' ); ?></a>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=mcsm-settings&action=clear_activity_log' ) ); ?>">
								<?php wp_nonce_field( 'mcsm_clear_activity_log' ); ?>
								<button type="submit" class="button button-link-delete mcsm-confirm-clear-activity"><?php esc_html_e( 'Clear Activity Log', 'mcsm' ); ?></button>
							</form>
						</div>
					</div>
				</div>
			</section>
			<section class="mcsm-settings-section">
				<div class="mcsm-settings-section-header">
					<h2><?php esc_html_e( 'Import / Export', 'mcsm' ); ?></h2>
				</div>
				<div class="mcsm-settings-actions">
					<div class="mcsm-settings-action-row">
						<div>
							<strong><?php esc_html_e( 'Export snippets', 'mcsm' ); ?></strong>
							<p class="description"><?php esc_html_e( 'Download all global snippets as a JSON backup.', 'mcsm' ); ?></p>
						</div>
						<a class="button button-primary" href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( 'Export JSON', 'mcsm' ); ?></a>
					</div>
					<form class="mcsm-settings-action-row" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin.php?page=mcsm-settings&action=import_json' ) ); ?>">
						<div>
							<strong><?php esc_html_e( 'Import snippets', 'mcsm' ); ?></strong>
							<p class="description"><?php esc_html_e( 'Upload a JSON export file. Imported snippets are added as new records.', 'mcsm' ); ?></p>
						</div>
								<div class="mcsm-import-controls">
							<?php wp_nonce_field( 'mcsm_import_json', 'mcsm_import_nonce' ); ?>
							<input type="file" name="mcsm_import_file" accept="application/json,.json" required />
							<?php submit_button( __( 'Import JSON', 'mcsm' ), 'secondary', 'submit', false ); ?>
						</div>
					</form>
				</div>
			</section>
		</div>
		<?php
	}

	/**
	 * Export snippets as JSON.
	 */
	private function handle_export_json() {
		check_admin_referer( 'mcsm_export_json' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to export snippets.', 'mcsm' ) );
		}

		$payload = array(
			'plugin'      => 'mcsm',
			'version'     => MCSM_VERSION,
			'exported_at' => current_time( 'mysql' ),
			'snippets'    => $this->repository->all_for_export(),
		);

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=mcsm-export-' . gmdate( 'Y-m-d-His' ) . '.json' );
		echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Import snippets from JSON.
	 */
	private function handle_import_json() {
		check_admin_referer( 'mcsm_import_json', 'mcsm_import_nonce' );

		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'unfiltered_html' ) ) {
			wp_die( esc_html__( 'You do not have permission to import executable snippets.', 'mcsm' ) );
		}

		if ( empty( $_FILES['mcsm_import_file'] ) || ! is_array( $_FILES['mcsm_import_file'] ) || empty( $_FILES['mcsm_import_file']['tmp_name'] ) ) {
			wp_safe_redirect( add_query_arg( array( 'page' => 'mcsm-settings', 'message' => 'error' ), admin_url( 'admin.php' ) ) );
			exit;
		}

		$file      = $_FILES['mcsm_import_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$file_name = isset( $file['name'] ) ? sanitize_file_name( self::input_string( wp_unslash( $file['name'] ) ) ) : '';
		$tmp_name  = isset( $file['tmp_name'] ) ? self::input_string( $file['tmp_name'] ) : '';
		$file_size = isset( $file['size'] ) ? absint( $file['size'] ) : 0;
		$file_error = isset( $file['error'] ) ? absint( $file['error'] ) : UPLOAD_ERR_NO_FILE;
		if ( UPLOAD_ERR_OK !== $file_error || 0 === $file_size || 'json' !== strtolower( pathinfo( $file_name, PATHINFO_EXTENSION ) ) || ! is_uploaded_file( $tmp_name ) || $file_size > 1048576 ) {
			wp_safe_redirect( add_query_arg( array( 'page' => 'mcsm-settings', 'message' => 'error' ), admin_url( 'admin.php' ) ) );
			exit;
		}

		$contents = file_get_contents( $tmp_name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $contents ) {
			wp_safe_redirect( add_query_arg( array( 'page' => 'mcsm-settings', 'message' => 'error' ), admin_url( 'admin.php' ) ) );
			exit;
		}

		$decoded  = json_decode( $contents, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
			wp_safe_redirect( add_query_arg( array( 'page' => 'mcsm-settings', 'message' => 'error' ), admin_url( 'admin.php' ) ) );
			exit;
		}

		$records = isset( $decoded['snippets'] ) && is_array( $decoded['snippets'] ) ? $decoded['snippets'] : $decoded;
		$records = array_slice( $records, 0, 500 );

		$result = $this->repository->import_records( $records );
		if ( ! empty( $result['imported'] ) ) {
			MCSM_Audit_Log::record(
				'snippets_imported',
				array(
					'object_type' => 'import',
					'summary'     => sprintf(
						/* translators: %d: number of imported snippets. */
						_n( '%d snippet imported.', '%d snippets imported.', $result['imported'], 'mcsm' ),
						$result['imported']
					),
					'changes'     => array(
						'imported' => absint( $result['imported'] ),
						'skipped'  => absint( $result['skipped'] ),
					),
				)
			);
		}
		wp_safe_redirect( add_query_arg( array( 'page' => 'mcsm-settings', 'message' => 'imported' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Save plugin settings.
	 */
	private function handle_save_settings() {
		check_admin_referer( 'mcsm_save_settings', 'mcsm_settings_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to save settings.', 'mcsm' ) );
		}

		$previous = array(
			'delete_on_uninstall' => get_option( 'mcsm_delete_data_on_uninstall', 'no' ),
			'output_comments'     => get_option( 'mcsm_output_comments', 'no' ),
			'retention_days'      => absint( get_option( MCSM_Audit_Log::RETENTION_OPTION, 180 ) ),
		);
		$scope = isset( $_POST['settings_scope'] ) ? sanitize_key( self::input_string( wp_unslash( $_POST['settings_scope'] ) ) ) : 'all';
		$current = $previous;

		if ( in_array( $scope, array( 'general', 'all' ), true ) ) {
			$current['delete_on_uninstall'] = isset( $_POST['delete_data_on_uninstall'] ) ? 'yes' : 'no';
			$current['output_comments']     = isset( $_POST['output_comments'] ) ? 'yes' : 'no';
		}

		if ( in_array( $scope, array( 'activity', 'all' ), true ) ) {
			$retention = isset( $_POST['activity_log_retention'] ) ? absint( $_POST['activity_log_retention'] ) : $previous['retention_days'];
			if ( ! in_array( $retention, array( 30, 90, 180, 365 ), true ) ) {
				$retention = 180;
			}
			$current['retention_days'] = $retention;
		}

		if ( 'activity' !== $scope ) {
			update_option( 'mcsm_delete_data_on_uninstall', $current['delete_on_uninstall'] );
			update_option( 'mcsm_output_comments', $current['output_comments'] );
		}
		if ( 'general' !== $scope ) {
			update_option( MCSM_Audit_Log::RETENTION_OPTION, $current['retention_days'] );
		}

		$changes = $this->simple_changes( $previous, $current );
		if ( $changes ) {
			MCSM_Audit_Log::record(
				'settings_updated',
				array(
					'object_type' => 'settings',
					'summary'     => __( 'Plugin settings updated.', 'mcsm' ),
					'changes'     => $changes,
				)
			);
		}

		wp_safe_redirect( add_query_arg( array( 'page' => 'mcsm-settings', 'message' => 'settings-saved' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Clear all activity entries.
	 */
	private function handle_clear_activity_log() {
		check_admin_referer( 'mcsm_clear_activity_log' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to clear the activity log.', 'mcsm' ) );
		}

		MCSM_Audit_Log::clear();
		wp_safe_redirect( add_query_arg( array( 'page' => 'mcsm-activity', 'message' => 'activity-cleared' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Format date/time for admin display.
	 *
	 * @param string $datetime Date/time.
	 * @return string
	 */
	public static function format_datetime( $datetime ) {
		if ( empty( $datetime ) || '0000-00-00 00:00:00' === $datetime ) {
			return __( 'Unknown', 'mcsm' );
		}

		return mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $datetime );
	}

	/**
	 * Get user display name.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	private function user_display_name( $user_id ) {
		$user = $user_id ? get_userdata( $user_id ) : false;
		return $user ? $user->display_name : __( 'Unknown', 'mcsm' );
	}

	/**
	 * Render searchable content picker.
	 *
	 * @param string $field_name Field name.
	 * @param string $selected_csv Selected IDs.
	 * @param array  $post_type_names Post types.
	 * @param string $input_id Input ID.
	 * @param string $placeholder Placeholder.
	 */
	private function render_content_picker( $field_name, $selected_csv, $post_type_names, $input_id, $placeholder ) {
		$selected   = array_filter( array_map( 'absint', explode( ',', (string) $selected_csv ) ) );
		$post_types = get_post_types( array( 'public' => true ), 'objects' );
		unset( $post_types['attachment'] );

		$allowed_types = array_values( array_intersect( $post_type_names, array_keys( $post_types ) ) );
		$options       = array();

		$selected_posts = empty( $selected ) || empty( $allowed_types )
			? array()
			: get_posts(
				array(
					'post_type'              => $allowed_types,
					'post_status'            => array( 'publish', 'private', 'draft', 'pending', 'future' ),
					'post__in'               => $selected,
					'posts_per_page'         => count( $selected ),
					'orderby'                => 'post__in',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);

		foreach ( $selected_posts as $post ) {
			$title     = get_the_title( $post );
			$options[] = array(
				'id'    => absint( $post->ID ),
				'title' => $title ? $title : sprintf( __( '#%d (no title)', 'mcsm' ), absint( $post->ID ) ),
				'type'  => $post_types[ $post->post_type ]->labels->singular_name,
			);
		}

		?>
		<div class="mcsm-content-picker" data-field-name="<?php echo esc_attr( $field_name ); ?>" data-post-types="<?php echo esc_attr( implode( ',', $allowed_types ) ); ?>">
			<div class="mcsm-picker-control">
				<input type="search" id="<?php echo esc_attr( $input_id ); ?>" class="regular-text mcsm-picker-search" placeholder="<?php echo esc_attr( $placeholder ); ?>" autocomplete="off" />
				<div class="mcsm-picker-dropdown" hidden>
					<div class="mcsm-picker-empty"><?php esc_html_e( 'Enter at least two characters to search.', 'mcsm' ); ?></div>
				</div>
			</div>
			<div class="mcsm-picker-selected">
				<?php foreach ( $options as $option ) : ?>
					<span class="mcsm-picker-chip" data-id="<?php echo esc_attr( $option['id'] ); ?>">
						<span><?php echo esc_html( $option['title'] ); ?></span>
						<small><?php echo esc_html( $option['type'] ); ?></small>
						<button type="button" class="mcsm-picker-remove" aria-label="<?php esc_attr_e( 'Remove', 'mcsm' ); ?>">×</button>
						<input type="hidden" name="<?php echo esc_attr( $field_name ); ?>[]" value="<?php echo esc_attr( $option['id'] ); ?>" />
					</span>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Get post types used by content pickers.
	 *
	 * @param string $group Group.
	 * @return array
	 */
	private function get_picker_post_types( $group = 'all' ) {
		$post_types = array_diff( get_post_types( array( 'public' => true ), 'names' ), array( 'attachment' ) );

		if ( 'cpt' === $group ) {
			return array_values( array_diff( $post_types, array( 'page', 'post' ) ) );
		}

		return array_values( $post_types );
	}

	/**
	 * Get display rule options.
	 *
	 * @return array
	 */
	private function get_display_rule_options() {
		$options = array(
			'site_wide'      => self::display_rule_label( 'site_wide' ),
			'specific_pages' => self::display_rule_label( 'specific_pages' ),
			'specific_posts' => self::display_rule_label( 'specific_posts' ),
		);

		foreach ( $this->get_picker_post_types( 'cpt' ) as $post_type ) {
			$options[ 'specific_cpt_' . $post_type ] = sprintf(
				/* translators: %s: post type label. */
				__( 'Specific %s', 'mcsm' ),
				$this->get_post_type_label( $post_type )
			);
		}

		return $options;
	}

	/**
	 * Get a post type label.
	 *
	 * @param string $post_type Post type.
	 * @return string
	 */
	private function get_post_type_label( $post_type ) {
		$object = get_post_type_object( $post_type );
		return $object ? $object->labels->name : $post_type;
	}

	/**
	 * Add local snippet metaboxes to public post types.
	 */
	public function register_local_snippet_metaboxes() {
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'unfiltered_html' ) ) {
			return;
		}

		$post_types = get_post_types( array( 'public' => true ), 'names' );
		$post_types = array_diff( $post_types, array( 'attachment' ) );

		foreach ( $post_types as $post_type ) {
			add_meta_box(
				'mcsm-local-snippets',
				__( 'MCSM', 'mcsm' ),
				array( $this, 'render_local_snippet_metabox' ),
				$post_type,
				'normal',
				'default'
			);
		}
	}

	/**
	 * Render local snippet metabox.
	 *
	 * @param WP_Post $post Post.
	 */
	public function render_local_snippet_metabox( $post ) {
		wp_nonce_field( 'mcsm_save_local_snippets_' . $post->ID, 'mcsm_local_nonce' );

		$snippets = get_post_meta( $post->ID, '_mcsm_local_snippets', true );
		if ( ! is_array( $snippets ) ) {
			$snippets = array();
		}

		?>
		<div class="mcsm-local-box">
			<div class="mcsm-local-snippets" data-next-index="<?php echo esc_attr( count( $snippets ) ); ?>">
				<?php
				if ( ! empty( $snippets ) ) {
					foreach ( $snippets as $index => $snippet ) {
						$this->render_local_snippet_row( $snippet, absint( $index ) );
					}
				}
				?>
			</div>
			<p><button type="button" class="button mcsm-add-local-snippet"><?php esc_html_e( 'Add Snippet', 'mcsm' ); ?></button></p>
			<script type="text/html" id="tmpl-mcsm-local-snippet-row">
				<?php $this->render_local_snippet_row( array(), '__INDEX__' ); ?>
			</script>
		</div>
		<?php
	}

	/**
	 * Render one local snippet row.
	 *
	 * @param array      $snippet Snippet.
	 * @param int|string $index Index.
	 */
	private function render_local_snippet_row( $snippet, $index ) {
		$snippet = wp_parse_args(
			(array) $snippet,
			array(
				'name'       => '',
				'type'       => 'html',
				'location'   => 'footer',
				'devices'    => 'all',
				'status'     => 'active',
				'logged_in_only' => 0,
				'code'       => '',
			)
		);
		$prefix = 'mcsm_local_snippets[' . $index . ']';
		$code_id = 'mcsm-local-code-' . $index;
		$is_new = '__INDEX__' === (string) $index;
		$classes = 'mcsm-local-snippet' . ( $is_new ? ' is-open' : '' ) . ( 'php' === $snippet['type'] ? ' is-php' : '' );
		$summary = $snippet['name'] ? $snippet['name'] : __( 'Snippet', 'mcsm' );
		?>
		<div class="<?php echo esc_attr( $classes ); ?>">
				<div class="mcsm-local-snippet-header">
					<button type="button" class="mcsm-local-snippet-toggle" aria-expanded="<?php echo $is_new ? 'true' : 'false'; ?>">
					<span class="mcsm-local-summary-main">
						<strong class="mcsm-local-summary-name"><?php echo esc_html( $summary ); ?></strong>
						<span class="mcsm-local-summary-meta">
							<span class="mcsm-pill mcsm-pill-status <?php echo 'active' === $snippet['status'] ? 'is-active' : 'is-inactive'; ?>"><?php echo esc_html( 'active' === $snippet['status'] ? __( 'Active', 'mcsm' ) : __( 'Inactive', 'mcsm' ) ); ?></span>
							<span class="mcsm-pill"><?php echo esc_html( self::type_label( $snippet['type'] ) ); ?></span>
							<span class="mcsm-pill"><?php echo esc_html( 'php' === $snippet['type'] ? __( 'WordPress hooks', 'mcsm' ) : self::location_label( $snippet['location'] ) ); ?></span>
							<span class="mcsm-pill"><?php echo esc_html( self::device_label( $snippet['devices'] ) ); ?></span>
							<span class="mcsm-pill mcsm-pill-visibility"<?php echo empty( $snippet['logged_in_only'] ) ? ' hidden' : ''; ?>><?php esc_html_e( 'Logged-in', 'mcsm' ); ?></span>
						</span>
					</span>
					<span class="dashicons dashicons-arrow-down-alt2 mcsm-local-arrow" aria-hidden="true"></span>
				</button>
				<button type="button" class="button mcsm-remove-local-snippet"><?php esc_html_e( 'Remove', 'mcsm' ); ?></button>
			</div>
			<div class="mcsm-local-snippet-body">
				<div class="mcsm-local-grid">
					<div class="mcsm-local-status-control">
						<span class="mcsm-field-label"><?php esc_html_e( 'Status', 'mcsm' ); ?></span>
						<label class="mcsm-form-toggle">
							<input class="mcsm-form-toggle-input mcsm-local-status-field mcsm-state-toggle" type="checkbox" name="<?php echo esc_attr( $prefix ); ?>[status]" value="active" data-on-label="<?php esc_attr_e( 'Active', 'mcsm' ); ?>" data-off-label="<?php esc_attr_e( 'Inactive', 'mcsm' ); ?>" <?php checked( $snippet['status'], 'active' ); ?> />
							<span class="mcsm-form-toggle-track" aria-hidden="true">
								<span class="mcsm-form-toggle-thumb"></span>
							</span>
							<span class="mcsm-form-toggle-label mcsm-toggle-state"><?php echo esc_html( 'active' === $snippet['status'] ? __( 'Active', 'mcsm' ) : __( 'Inactive', 'mcsm' ) ); ?></span>
						</label>
					</div>
					<label>
						<span><?php esc_html_e( 'Name', 'mcsm' ); ?></span>
						<input type="text" name="<?php echo esc_attr( $prefix ); ?>[name]" value="<?php echo esc_attr( $snippet['name'] ); ?>" class="widefat mcsm-local-name-field" />
					</label>
					<label>
						<span><?php esc_html_e( 'Type', 'mcsm' ); ?></span>
							<select name="<?php echo esc_attr( $prefix ); ?>[type]" class="widefat mcsm-local-type-field">
								<?php foreach ( MCSM_Repository::TYPES as $type ) : ?>
							<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $snippet['type'], $type ); ?>><?php echo esc_html( self::type_label( $type ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label class="mcsm-local-location-control">
						<span><?php esc_html_e( 'Location', 'mcsm' ); ?></span>
						<select name="<?php echo esc_attr( $prefix ); ?>[location]" class="widefat mcsm-local-location-field">
							<?php foreach ( MCSM_Repository::AUTOMATIC_LOCATIONS as $location ) : ?>
								<option value="<?php echo esc_attr( $location ); ?>" <?php selected( $snippet['location'], $location ); ?>><?php echo esc_html( self::location_label( $location ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label>
						<span><?php esc_html_e( 'Devices', 'mcsm' ); ?></span>
						<select name="<?php echo esc_attr( $prefix ); ?>[devices]" class="widefat mcsm-local-devices-field">
							<?php foreach ( MCSM_Repository::DEVICES as $device ) : ?>
								<option value="<?php echo esc_attr( $device ); ?>" <?php selected( $snippet['devices'], $device ); ?>><?php echo esc_html( self::device_label( $device ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<div class="mcsm-local-visibility-control">
						<span class="mcsm-field-label"><?php esc_html_e( 'Visibility', 'mcsm' ); ?></span>
						<label class="mcsm-form-toggle">
							<input class="mcsm-form-toggle-input mcsm-local-visibility-field mcsm-state-toggle" type="checkbox" name="<?php echo esc_attr( $prefix ); ?>[logged_in_only]" value="1" data-on-label="<?php esc_attr_e( 'Logged-in Only', 'mcsm' ); ?>" data-off-label="<?php esc_attr_e( 'All Visitors', 'mcsm' ); ?>" <?php checked( ! empty( $snippet['logged_in_only'] ) ); ?> />
							<span class="mcsm-form-toggle-track" aria-hidden="true">
								<span class="mcsm-form-toggle-thumb"></span>
							</span>
							<span class="mcsm-form-toggle-label mcsm-toggle-state"><?php echo esc_html( ! empty( $snippet['logged_in_only'] ) ? __( 'Logged-in Only', 'mcsm' ) : __( 'All Visitors', 'mcsm' ) ); ?></span>
						</label>
						<p class="description"><?php esc_html_e( 'Choose who can see this snippet on the frontend.', 'mcsm' ); ?></p>
					</div>
				</div>
				<div class="mcsm-local-code-field">
					<label class="mcsm-code-editor-label" for="<?php echo esc_attr( $code_id ); ?>"><?php esc_html_e( 'Code', 'mcsm' ); ?></label>
					<div class="mcsm-code-editor-shell<?php echo 'php' === $snippet['type'] ? ' is-php' : ''; ?>">
						<div class="mcsm-php-prefix" aria-hidden="true">&lt;?php</div>
						<textarea id="<?php echo esc_attr( $code_id ); ?>" name="<?php echo esc_attr( $prefix ); ?>[code]" class="widefat code mcsm-local-code" rows="8"><?php echo esc_textarea( $snippet['code'] ); ?></textarea>
					</div>
					<div class="mcsm-validation-hints" aria-live="polite"></div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Save local snippets.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post Post object.
	 */
	public function save_local_snippets( $post_id, $post ) {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		$post_types = array_diff( get_post_types( array( 'public' => true ), 'names' ), array( 'attachment' ) );
		if ( ! $post || ! in_array( $post->post_type, $post_types, true ) ) {
			return;
		}

		if ( ! isset( $_POST['mcsm_local_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( self::input_string( wp_unslash( $_POST['mcsm_local_nonce'] ) ) ), 'mcsm_save_local_snippets_' . $post_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) || ! current_user_can( 'manage_options' ) || ! current_user_can( 'unfiltered_html' ) ) {
			return;
		}

		$previous     = get_post_meta( $post_id, '_mcsm_local_snippets', true );
		$previous     = is_array( $previous ) ? $previous : array();
		$raw_snippets = isset( $_POST['mcsm_local_snippets'] ) ? (array) wp_unslash( $_POST['mcsm_local_snippets'] ) : array();
		$snippets     = array();

		foreach ( $raw_snippets as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$code = isset( $raw['code'] ) ? self::input_string( $raw['code'] ) : '';
			$name = isset( $raw['name'] ) ? sanitize_text_field( self::input_string( $raw['name'] ) ) : '';
			$type = $this->sanitize_local_choice( $raw['type'] ?? 'html', MCSM_Repository::TYPES, 'html' );

			if ( '' === trim( $code ) ) {
				continue;
			}

			if ( 'php' === $type ) {
				$validation = MCSM_PHP_Executor::validate( $code );
				if ( is_wp_error( $validation ) ) {
					set_transient(
						'mcsm_local_snippet_error_' . get_current_user_id(),
						$validation->get_error_message(),
						MINUTE_IN_SECONDS
					);
					return;
				}
			}

			$snippets[] = array(
				'name'           => $name ? $name : __( 'Local Snippet', 'mcsm' ),
				'type'           => $type,
				'location'       => $this->sanitize_local_choice( $raw['location'] ?? 'footer', MCSM_Repository::AUTOMATIC_LOCATIONS, 'footer' ),
				'devices'        => $this->sanitize_local_choice( $raw['devices'] ?? 'all', MCSM_Repository::DEVICES, 'all' ),
				'status'         => $this->sanitize_local_choice( $raw['status'] ?? 'inactive', MCSM_Repository::STATUSES, 'inactive' ),
				'logged_in_only' => ! empty( $raw['logged_in_only'] ) ? 1 : 0,
				'code'           => $code,
			);
		}

		if ( empty( $snippets ) ) {
			delete_post_meta( $post_id, '_mcsm_local_snippets' );
			if ( ! empty( $previous ) ) {
				MCSM_Audit_Log::record(
					'local_updated',
					array(
						'object_type'  => 'local_snippet',
						'object_id'    => $post_id,
						'snippet_name' => get_the_title( $post_id ),
						'summary'      => __( 'Local snippets removed.', 'mcsm' ),
						'changes'      => $this->local_snippet_changes( $previous, array() ),
					)
				);
			}
			return;
		}

		update_post_meta( $post_id, '_mcsm_local_snippets', $snippets );
		if ( wp_json_encode( $previous ) !== wp_json_encode( $snippets ) ) {
			MCSM_Audit_Log::record(
				'local_updated',
				array(
					'object_type'  => 'local_snippet',
					'object_id'    => $post_id,
					'snippet_name' => get_the_title( $post_id ),
					'summary'      => __( 'Local snippets updated.', 'mcsm' ),
					'changes'      => $this->local_snippet_changes( $previous, $snippets ),
				)
			);
		}
	}

	/**
	 * Check current post edit screen.
	 *
	 * @return bool
	 */
	private function is_supported_post_edit_screen() {
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'unfiltered_html' ) ) {
			return false;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'post' !== $screen->base || empty( $screen->post_type ) ) {
			return false;
		}

		$post_types = array_diff( get_post_types( array( 'public' => true ), 'names' ), array( 'attachment' ) );
		return in_array( $screen->post_type, $post_types, true );
	}

	/**
	 * Display a local PHP validation error after a post save attempt.
	 */
	public function render_local_snippet_notice() {
		$transient_key = 'mcsm_local_snippet_error_' . get_current_user_id();
		$message       = get_transient( $transient_key );

		if ( ! $message ) {
			return;
		}

		delete_transient( $transient_key );
		printf(
			'<div class="notice notice-error is-dismissible"><p><strong>%1$s</strong> %2$s</p></div>',
			esc_html__( 'Local snippet was not saved.', 'mcsm' ),
			esc_html( $message )
		);
	}

	/**
	 * Explain why PHP snippets are not running when emergency safe mode is on.
	 */
	public function render_php_safe_mode_notice() {
		if ( ! MCSM_PHP_Executor::is_disabled() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s</p></div>',
			esc_html__( 'MCSM PHP safe mode is enabled.', 'mcsm' ),
			esc_html__( 'PHP snippets will not run until MCSM_DISABLE_PHP_SNIPPETS is removed or set to false in wp-config.php. HTML, JavaScript, and CSS snippets are unaffected.', 'mcsm' )
		);
	}

	/**
	 * Sanitize local snippet select values.
	 *
	 * @param string $value Value.
	 * @param array  $allowed Allowed values.
	 * @param string $fallback Fallback.
	 * @return string
	 */
	private function sanitize_local_choice( $value, $allowed, $fallback ) {
		$value = sanitize_key( self::input_string( $value ) );
		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}

	/**
	 * Normalize untrusted scalar input to a string.
	 *
	 * @param mixed  $value Input value.
	 * @param string $default Fallback.
	 * @return string
	 */
	private static function input_string( $value, $default = '' ) {
		return is_scalar( $value ) ? (string) $value : $default;
	}

	/**
	 * Validate a snippet before changing its status to active.
	 *
	 * @param array $snippet Snippet record.
	 * @return true|WP_Error
	 */
	private function validate_snippet_for_activation( $snippet ) {
		if ( '' === trim( (string) ( $snippet['code'] ?? '' ) ) ) {
			return new WP_Error( 'mcsm_empty_snippet', __( 'Empty snippets cannot be activated.', 'mcsm' ) );
		}

		if ( 'php' === sanitize_key( self::input_string( $snippet['type'] ?? '' ) ) ) {
			return MCSM_PHP_Executor::validate( $snippet['code'] ?? '' );
		}

		return true;
	}

	/**
	 * Type label.
	 *
	 * @param string $type Type.
	 * @return string
	 */
	public static function type_label( $type ) {
			$labels = array(
				'html'       => __( 'HTML', 'mcsm' ),
				'javascript' => __( 'JavaScript', 'mcsm' ),
				'css'        => __( 'CSS', 'mcsm' ),
				'php'        => __( 'PHP', 'mcsm' ),
			);

		return $labels[ $type ] ?? $type;
	}

	/**
	 * Location label.
	 *
	 * @param string $location Location.
	 * @return string
	 */
	public static function location_label( $location ) {
		$labels = array(
			'header'         => __( 'Header', 'mcsm' ),
			'body_open'      => __( 'Body', 'mcsm' ),
			'footer'         => __( 'Footer', 'mcsm' ),
			'shortcode_only' => __( 'Shortcode Only', 'mcsm' ),
		);

		return $labels[ $location ] ?? $location;
	}

	/**
	 * Display rule label.
	 *
	 * @param string $rule Rule.
	 * @return string
	 */
	public static function display_rule_label( $rule ) {
		$labels = array(
			'site_wide'           => __( 'Site-wide', 'mcsm' ),
			'specific_pages'      => __( 'Specific Pages', 'mcsm' ),
			'specific_posts'      => __( 'Specific Posts', 'mcsm' ),
			'exclude_selected'    => __( 'Site-wide except selected', 'mcsm' ),
			'exclude_pages'       => __( 'Site-wide except selected', 'mcsm' ),
			'exclude_posts'       => __( 'Site-wide except selected', 'mcsm' ),
		);

		if ( 0 === strpos( $rule, 'specific_cpt_' ) ) {
			$post_type = substr( $rule, strlen( 'specific_cpt_' ) );
			$object    = get_post_type_object( $post_type );
			if ( $object ) {
				return sprintf(
					/* translators: %s: post type label. */
					__( 'Specific %s', 'mcsm' ),
					$object->labels->name
				);
			}
		}

		return $labels[ $rule ] ?? $rule;
	}

	/**
	 * Device label.
	 *
	 * @param string $device Device.
	 * @return string
	 */
	public static function device_label( $device ) {
		$labels = array(
			'all'     => __( 'All Devices', 'mcsm' ),
			'desktop' => __( 'Desktop Only', 'mcsm' ),
			'mobile'  => __( 'Mobile Only', 'mcsm' ),
		);

		return $labels[ $device ] ?? $device;
	}
}
