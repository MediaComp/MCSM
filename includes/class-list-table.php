<?php
/**
 * Snippets list table.
 *
 * @package MCSnippetManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class MCSM_List_Table extends WP_List_Table {
	/**
	 * Repository.
	 *
	 * @var MCSM_Repository
	 */
	private $repository;

	/**
	 * Current status filter.
	 *
	 * @var string
	 */
	private $status_filter = '';

	/**
	 * Constructor.
	 *
	 * @param MCSM_Repository $repository Repository.
	 */
	public function __construct( MCSM_Repository $repository ) {
		parent::__construct(
			array(
				'singular' => 'snippet',
				'plural'   => 'snippets',
				'ajax'     => false,
			)
		);

		$this->repository = $repository;
	}

	/**
	 * Define table columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'cb'           => '<input type="checkbox" />',
			'status'       => __( 'Status', 'mcsm' ),
			'name'         => __( 'Snippet Name', 'mcsm' ),
			'logged_in_only' => __( 'Visibility', 'mcsm' ),
			'id'           => __( 'ID', 'mcsm' ),
			'display_rule' => __( 'Display Rule', 'mcsm' ),
			'location'     => __( 'Location', 'mcsm' ),
			'priority'     => __( 'Priority', 'mcsm' ),
			'type'         => __( 'Code Type', 'mcsm' ),
			'devices'      => __( 'Devices', 'mcsm' ),
			'shortcode'    => __( 'Shortcode', 'mcsm' ),
			'updated_at'   => __( 'Last Modified', 'mcsm' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'id'       => array( 'id', true ),
			'name'     => array( 'name', false ),
			'type'     => array( 'type', false ),
			'location' => array( 'location', false ),
			'priority' => array( 'priority', false ),
			'devices'  => array( 'devices', false ),
			'status'   => array( 'status', false ),
			'logged_in_only' => array( 'logged_in_only', false ),
			'updated_at' => array( 'updated_at', false ),
		);
	}

	/**
	 * Bulk actions.
	 *
	 * @return array
	 */
	protected function get_bulk_actions() {
		return array(
			'activate'   => __( 'Activate', 'mcsm' ),
			'deactivate' => __( 'Deactivate', 'mcsm' ),
			'delete'     => __( 'Delete', 'mcsm' ),
		);
	}

	/**
	 * Status views.
	 *
	 * @return array
	 */
	protected function get_views() {
		$base_url = admin_url( 'admin.php?page=mcsm' );
		$counts   = array(
			'all'      => $this->repository->count(),
			'active'   => $this->repository->count( array( 'status' => 'active' ) ),
			'inactive' => $this->repository->count( array( 'status' => 'inactive' ) ),
		);
		$views    = array();
		$labels   = array(
			'all'      => __( 'All', 'mcsm' ),
			'active'   => __( 'Active', 'mcsm' ),
			'inactive' => __( 'Inactive', 'mcsm' ),
		);

		foreach ( $labels as $status => $label ) {
			$url     = 'all' === $status ? $base_url : add_query_arg( 'status', $status, $base_url );
			$current = ( 'all' === $status && '' === $this->status_filter ) || $status === $this->status_filter;
			$views[ $status ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url( $url ),
				$current ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				absint( $counts[ $status ] )
			);
		}

		return $views;
	}

	/**
	 * Prepare rows.
	 */
	public function prepare_items() {
		$per_page            = $this->get_items_per_page( 'mcsm_snippets_per_page', 20 );
		$current_page        = $this->get_pagenum();
		$this->status_filter = isset( $_GET['status'] ) ? sanitize_key( self::input_string( wp_unslash( $_GET['status'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$type_filter         = isset( $_REQUEST['snippet_type'] ) ? sanitize_key( self::input_string( wp_unslash( $_REQUEST['snippet_type'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search              = isset( $_REQUEST['s'] ) ? sanitize_text_field( self::input_string( wp_unslash( $_REQUEST['s'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$orderby             = isset( $_GET['orderby'] ) ? sanitize_key( self::input_string( wp_unslash( $_GET['orderby'] ) ) ) : 'id'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order               = isset( $_GET['order'] ) ? sanitize_key( self::input_string( wp_unslash( $_GET['order'] ) ) ) : 'DESC'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$args = array(
			'status'   => $this->status_filter,
			'type'     => $type_filter,
			'search'   => $search,
			'orderby'  => $orderby,
			'order'    => $order,
			'per_page' => $per_page,
			'offset'   => ( $current_page - 1 ) * $per_page,
		);

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );
		$this->items           = $this->repository->query( $args );
		$total_items           = $this->repository->count( $args );

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => ceil( $total_items / $per_page ),
			)
		);
	}

	/**
	 * Checkbox column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="snippet_ids[]" value="%d" />', absint( $item['id'] ) );
	}

	/**
	 * Name column with row actions.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_name( $item ) {
		$edit_url   = add_query_arg(
			array(
				'page'   => 'mcsm',
				'action' => 'edit',
				'id'     => absint( $item['id'] ),
			),
			admin_url( 'admin.php' )
		);
		$delete_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'   => 'mcsm',
					'action' => 'delete',
					'id'     => absint( $item['id'] ),
				),
				admin_url( 'admin.php' )
			),
			'mcsm_delete_snippet_' . absint( $item['id'] )
		);
		$actions    = array(
			'edit'   => sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html__( 'Edit', 'mcsm' ) ),
			'delete' => sprintf( '<a class="submitdelete mcsm-confirm-delete" href="%s">%s</a>', esc_url( $delete_url ), esc_html__( 'Delete', 'mcsm' ) ),
		);

		return sprintf(
			'<strong><a class="row-title" href="%s">%s</a></strong>%s',
			esc_url( $edit_url ),
			esc_html( $item['name'] ),
			$this->row_actions( $actions )
		);
	}

	/**
	 * Status column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_status( $item ) {
		$is_active = 'active' === $item['status'];
		$label     = $is_active
			? sprintf( __( 'Deactivate %s', 'mcsm' ), $item['name'] )
			: sprintf( __( 'Activate %s', 'mcsm' ), $item['name'] );

		return sprintf(
			'<button type="button" class="mcsm-status-toggle" role="switch" aria-checked="%1$s" aria-label="%2$s" data-snippet-id="%3$d"><span class="mcsm-status-toggle-track" aria-hidden="true"><span class="mcsm-status-toggle-thumb"></span></span></button>',
			$is_active ? 'true' : 'false',
			esc_attr( $label ),
			absint( $item['id'] )
		);
	}

	/**
	 * Shortcode column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_shortcode( $item ) {
		if ( 'php' === ( $item['type'] ?? '' ) ) {
			return '&mdash;';
		}

		return sprintf( '<code class="mcsm-shortcode">[mc_snippet id="%d"]</code>', absint( $item['id'] ) );
	}

	/**
	 * Last modified column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_updated_at( $item ) {
		return esc_html( MCSM_Admin::format_datetime( $item['updated_at'] ?? '' ) );
	}

	/**
	 * Default column renderer.
	 *
	 * @param array  $item Row.
	 * @param string $column_name Column.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'id':
				return absint( $item['id'] );
			case 'logged_in_only':
				return ! empty( $item['logged_in_only'] ) ? '<span class="mcsm-visibility-logged-in">' . esc_html__( 'Logged-in', 'mcsm' ) . '</span>' : '<span class="mcsm-visibility-all">' . esc_html__( 'All', 'mcsm' ) . '</span>';
			case 'display_rule':
				return esc_html( MCSM_Admin::display_rule_label( $item['display_rule'] ) );
			case 'location':
				if ( 'php' === ( $item['type'] ?? '' ) ) {
					return esc_html__( 'WordPress hooks', 'mcsm' );
				}
				return esc_html( MCSM_Admin::location_label( $item['location'] ) );
			case 'priority':
				if ( 'php' === ( $item['type'] ?? '' ) || 'shortcode_only' === ( $item['location'] ?? '' ) ) {
					return '&mdash;';
				}
				return MCSM_Repository::sanitize_priority( $item['priority'] ?? MCSM_Repository::DEFAULT_PRIORITY );
			case 'type':
				return esc_html( MCSM_Admin::type_label( $item['type'] ) );
			case 'devices':
				return esc_html( MCSM_Admin::device_label( $item['devices'] ) );
			default:
				return '';
		}
	}

	/**
	 * Extra filters.
	 *
	 * @param string $which Position.
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		$current = isset( $_REQUEST['snippet_type'] ) ? sanitize_key( self::input_string( wp_unslash( $_REQUEST['snippet_type'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="alignleft actions">
			<label class="screen-reader-text" for="mcsm-snippet-type-filter"><?php esc_html_e( 'Filter by snippet type', 'mcsm' ); ?></label>
			<select name="snippet_type" id="mcsm-snippet-type-filter">
				<option value=""><?php esc_html_e( 'All snippet types', 'mcsm' ); ?></option>
				<?php foreach ( MCSM_Repository::TYPES as $type ) : ?>
					<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $current, $type ); ?>><?php echo esc_html( MCSM_Admin::type_label( $type ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( __( 'Filter', 'mcsm' ), '', 'filter_action', false ); ?>
		</div>
		<?php
	}

	/**
	 * Normalize untrusted scalar input to a string.
	 *
	 * @param mixed $value Input value.
	 * @return string
	 */
	private static function input_string( $value ) {
		return is_scalar( $value ) ? (string) $value : '';
	}
}
