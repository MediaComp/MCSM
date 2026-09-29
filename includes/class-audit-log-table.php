<?php
/**
 * Activity log list table.
 *
 * @package MCSnippetManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class MCSM_Audit_Log_Table extends WP_List_Table {
	/**
	 * Supported event labels.
	 *
	 * @return array
	 */
	public static function event_labels() {
		return array(
			'snippet_created'     => __( 'Created', 'mcsm' ),
			'snippet_updated'     => __( 'Updated', 'mcsm' ),
			'snippet_activated'   => __( 'Activated', 'mcsm' ),
			'snippet_deactivated' => __( 'Deactivated', 'mcsm' ),
			'snippet_deleted'     => __( 'Deleted', 'mcsm' ),
			'local_updated'       => __( 'Local snippets updated', 'mcsm' ),
			'snippets_imported'   => __( 'Imported', 'mcsm' ),
			'settings_updated'    => __( 'Settings updated', 'mcsm' ),
		);
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'activity',
				'plural'   => 'activities',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'created_at' => __( 'Date', 'mcsm' ),
			'user_id'    => __( 'User', 'mcsm' ),
			'event'      => __( 'Action', 'mcsm' ),
			'snippet'    => __( 'Snippet', 'mcsm' ),
			'summary'    => __( 'Details', 'mcsm' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'created_at' => array( 'created_at', true ),
			'user_id'    => array( 'user_id', false ),
			'event'      => array( 'event', false ),
			'snippet'    => array( 'snippet_name', false ),
		);
	}

	/**
	 * Prepare rows.
	 */
	public function prepare_items() {
		$per_page     = $this->get_items_per_page( 'mcsm_activity_per_page', 20 );
		$current_page = $this->get_pagenum();
		$args         = array(
			'event'    => isset( $_REQUEST['event_type'] ) ? sanitize_key( self::input_string( wp_unslash( $_REQUEST['event_type'] ) ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'user_id'  => isset( $_REQUEST['activity_user'] ) ? absint( $_REQUEST['activity_user'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'search'   => isset( $_REQUEST['s'] ) ? sanitize_text_field( self::input_string( wp_unslash( $_REQUEST['s'] ) ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'orderby'  => isset( $_GET['orderby'] ) ? sanitize_key( self::input_string( wp_unslash( $_GET['orderby'] ) ) ) : 'created_at', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'order'    => isset( $_GET['order'] ) ? sanitize_key( self::input_string( wp_unslash( $_GET['order'] ) ) ) : 'DESC', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'per_page' => $per_page,
			'offset'   => ( $current_page - 1 ) * $per_page,
		);

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );
		$this->items           = MCSM_Audit_Log::query( $args );
		$total_items           = MCSM_Audit_Log::count( $args );

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => ceil( $total_items / $per_page ),
			)
		);
	}

	/**
	 * Render filters above the table.
	 *
	 * @param string $which Position.
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		$current_event = isset( $_REQUEST['event_type'] ) ? sanitize_key( self::input_string( wp_unslash( $_REQUEST['event_type'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_user  = isset( $_REQUEST['activity_user'] ) ? absint( $_REQUEST['activity_user'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="alignleft actions">
			<label class="screen-reader-text" for="mcsm-event-filter"><?php esc_html_e( 'Filter by action', 'mcsm' ); ?></label>
			<select name="event_type" id="mcsm-event-filter">
				<option value=""><?php esc_html_e( 'All actions', 'mcsm' ); ?></option>
				<?php foreach ( self::event_labels() as $event => $label ) : ?>
					<option value="<?php echo esc_attr( $event ); ?>" <?php selected( $current_event, $event ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<label class="screen-reader-text" for="mcsm-user-filter"><?php esc_html_e( 'Filter by user', 'mcsm' ); ?></label>
			<select name="activity_user" id="mcsm-user-filter">
				<option value="0"><?php esc_html_e( 'All users', 'mcsm' ); ?></option>
				<?php foreach ( MCSM_Audit_Log::get_user_ids() as $user_id ) : ?>
					<?php $user = get_userdata( $user_id ); ?>
					<?php if ( $user ) : ?>
						<option value="<?php echo absint( $user_id ); ?>" <?php selected( $current_user, $user_id ); ?>><?php echo esc_html( $user->display_name ); ?></option>
					<?php endif; ?>
				<?php endforeach; ?>
			</select>
			<?php submit_button( __( 'Filter', 'mcsm' ), 'secondary', 'filter_action', false ); ?>
		</div>
		<?php
	}

	/**
	 * Default column output.
	 *
	 * @param array  $item Row.
	 * @param string $column_name Column.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		if ( 'created_at' === $column_name ) {
			return esc_html( MCSM_Admin::format_datetime( $item['created_at'] ?? '' ) );
		}

		if ( 'user_id' === $column_name ) {
			$user = get_userdata( absint( $item['user_id'] ?? 0 ) );
			return esc_html( $user ? $user->display_name : __( 'Unknown', 'mcsm' ) );
		}

		if ( 'event' === $column_name ) {
			$labels = self::event_labels();
			$event  = sanitize_key( $item['event'] ?? '' );
			return sprintf(
				'<span class="mcsm-activity-event mcsm-activity-event-%1$s">%2$s</span>',
				esc_attr( $event ),
				esc_html( $labels[ $event ] ?? ucwords( str_replace( '_', ' ', $event ) ) )
			);
		}

		if ( 'snippet' === $column_name ) {
			$name = $item['snippet_name'] ?? '';
			if ( '' === $name ) {
				return '&mdash;';
			}

			if ( 'global_snippet' === ( $item['object_type'] ?? '' ) && absint( $item['snippet_id'] ?? 0 ) && 'snippet_deleted' !== ( $item['event'] ?? '' ) ) {
				$url = add_query_arg(
					array(
						'page'   => 'mcsm',
						'action' => 'edit',
						'id'     => absint( $item['snippet_id'] ),
					),
					admin_url( 'admin.php' )
				);
				return sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html( $name ) );
			}

			if ( 'local_snippet' === ( $item['object_type'] ?? '' ) && absint( $item['object_id'] ?? 0 ) ) {
				$url = get_edit_post_link( absint( $item['object_id'] ), 'raw' );
				if ( $url ) {
					return sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html( $name ) );
				}
			}

			return esc_html( $name );
		}

		if ( 'summary' === $column_name ) {
			$output  = esc_html( $item['summary'] ?? '' );
			$changes = json_decode( $item['changes'] ?? '', true );
			if ( is_array( $changes ) && ! empty( $changes ) ) {
				$output .= $this->render_changes( $changes, $item['event'] ?? '' );
			}
			return $output;
		}

		return '';
	}

	/**
	 * Empty table message.
	 */
	public function no_items() {
		esc_html_e( 'No activity has been recorded yet.', 'mcsm' );
	}

	/**
	 * Render compact expandable change details.
	 *
	 * @param array  $changes Changes.
	 * @param string $event Event key.
	 * @return string
	 */
	private function render_changes( $changes, $event = '' ) {
		$labels = array(
			'name'              => __( 'Name', 'mcsm' ),
			'type'              => __( 'Code Type', 'mcsm' ),
			'location'          => __( 'Location', 'mcsm' ),
			'display_rule'      => __( 'Display Rule', 'mcsm' ),
			'include_ids'       => __( 'Included content', 'mcsm' ),
			'exclude_ids'       => __( 'Excluded content', 'mcsm' ),
			'devices'           => __( 'Devices', 'mcsm' ),
			'status'            => __( 'Status', 'mcsm' ),
			'logged_in_only'    => __( 'Visibility', 'mcsm' ),
			'notes'             => __( 'Notes', 'mcsm' ),
			'code'              => __( 'Code', 'mcsm' ),
			'before_count'      => __( 'Previous count', 'mcsm' ),
			'after_count'       => __( 'New count', 'mcsm' ),
			'delete_on_uninstall' => __( 'Delete data on uninstall', 'mcsm' ),
			'output_comments'   => __( 'Output identification markers', 'mcsm' ),
			'retention_days'    => __( 'Retention', 'mcsm' ),
			'imported'          => __( 'Imported', 'mcsm' ),
			'skipped'           => __( 'Skipped', 'mcsm' ),
			'settings'          => __( 'Snippet settings', 'mcsm' ),
		);
		$items = array();

		foreach ( $changes as $key => $value ) {
			$label = $labels[ $key ] ?? ucwords( str_replace( '_', ' ', $key ) );
			if ( is_array( $value ) && array_key_exists( 'from', $value ) && array_key_exists( 'to', $value ) ) {
				$items[] = sprintf(
					'<li><strong>%1$s:</strong> %2$s &rarr; %3$s</li>',
					esc_html( $label ),
					esc_html( self::display_value( $key, $value['from'] ) ),
					esc_html( self::display_value( $key, $value['to'] ) )
				);
			} else {
				$items[] = sprintf( '<li><strong>%1$s:</strong> %2$s</li>', esc_html( $label ), esc_html( self::display_value( $key, $value ) ) );
			}
		}

		$summary = in_array( $event, array( 'snippet_created', 'snippet_deleted', 'snippets_imported' ), true )
			? __( 'View details', 'mcsm' )
			: __( 'View changes', 'mcsm' );

		return sprintf(
			'<details class="mcsm-activity-details"><summary>%1$s</summary><ul>%2$s</ul></details>',
			esc_html( $summary ),
			implode( '', $items )
		);
	}

	/**
	 * Format stored values for people.
	 *
	 * @param string $key Field key.
	 * @param mixed  $value Value.
	 * @return string
	 */
	private static function display_value( $key, $value ) {
		if ( in_array( $key, array( 'code', 'notes', 'settings' ), true ) ) {
			return __( 'Modified', 'mcsm' );
		}

		if ( 'logged_in_only' === $key ) {
			return empty( $value ) ? __( 'All Visitors', 'mcsm' ) : __( 'Logged-in Only', 'mcsm' );
		}

		if ( 'status' === $key ) {
			return 'active' === $value ? __( 'Active', 'mcsm' ) : __( 'Inactive', 'mcsm' );
		}

		if ( 'location' === $key ) {
			if ( 'wordpress_hooks' === $value ) {
				return __( 'WordPress hooks', 'mcsm' );
			}
			return MCSM_Admin::location_label( $value );
		}

		if ( 'display_rule' === $key ) {
			return MCSM_Admin::display_rule_label( $value );
		}

		if ( 'devices' === $key ) {
			return MCSM_Admin::device_label( $value );
		}

		if ( 'type' === $key ) {
			return MCSM_Admin::type_label( $value );
		}

		if ( 'retention_days' === $key ) {
			$days = absint( $value );
			return sprintf( _n( '%d day', '%d days', $days, 'mcsm' ), $days );
		}

		if ( in_array( $key, array( 'delete_on_uninstall', 'output_comments' ), true ) ) {
			return in_array( $value, array( 1, '1', 'yes', true ), true ) ? __( 'Enabled', 'mcsm' ) : __( 'Disabled', 'mcsm' );
		}

		if ( is_bool( $value ) ) {
			return $value ? __( 'Yes', 'mcsm' ) : __( 'No', 'mcsm' );
		}

		return is_scalar( $value ) && '' !== (string) $value ? (string) $value : __( 'None', 'mcsm' );
	}

	/**
	 * Coerce request values to strings.
	 *
	 * @param mixed  $value Value.
	 * @param string $fallback Fallback.
	 * @return string
	 */
	private static function input_string( $value, $fallback = '' ) {
		return is_scalar( $value ) ? (string) $value : $fallback;
	}
}
