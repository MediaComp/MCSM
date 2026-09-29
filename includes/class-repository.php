<?php
/**
 * Database access for snippets.
 *
 * @package MCSnippetManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MCSM_Repository {
	const TYPES               = array( 'html', 'javascript', 'css', 'php' );
	const LOCATIONS           = array( 'header', 'body_open', 'footer', 'shortcode_only' );
	const AUTOMATIC_LOCATIONS = array( 'header', 'body_open', 'footer' );
	const DEVICES             = array( 'all', 'desktop', 'mobile' );
	const STATUSES            = array( 'active', 'inactive' );
	const DEFAULT_PRIORITY    = 20;
	const MIN_PRIORITY        = -9999;
	const MAX_PRIORITY        = 9999;

	/**
	 * Per-request cache for active snippet queries.
	 *
	 * @var array
	 */
	private $active_cache = array();

	/**
	 * Get the full database table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'mc_snippets';
	}

	/**
	 * Fetch snippets for the admin table.
	 *
	 * @param array $args Query args.
	 * @return array
	 */
	public function query( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'status'   => '',
			'type'     => '',
			'search'   => '',
			'orderby'  => 'id',
			'order'    => 'DESC',
			'per_page' => 20,
			'offset'   => 0,
		);
		$args     = wp_parse_args( $args, $defaults );
		$where    = array( '1=1' );
		$params   = array();

		if ( in_array( $args['status'], self::STATUSES, true ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}

		if ( in_array( $args['type'], self::TYPES, true ) ) {
			$where[]  = 'type = %s';
			$params[] = $args['type'];
		}

		if ( '' !== $args['search'] ) {
			$where[]  = '(name LIKE %s OR code LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$params[] = $like;
			$params[] = $like;
		}

		$allowed_orderby = array( 'id', 'name', 'type', 'location', 'priority', 'devices', 'status', 'logged_in_only', 'created_at', 'updated_at' );
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'id';
		$order           = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';
		$limit           = max( 1, absint( $args['per_page'] ) );
		$offset          = max( 0, absint( $args['offset'] ) );
		$table_name      = self::table_name();
		$where_sql       = implode( ' AND ', $where );
		$params[]        = $limit;
		$params[]        = $offset;

		$sql = "SELECT * FROM {$table_name} WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Count snippets for the admin table.
	 *
	 * @param array $args Query args.
	 * @return int
	 */
	public function count( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'status' => '',
			'type'   => '',
			'search' => '',
		);
		$args     = wp_parse_args( $args, $defaults );
		$where    = array( '1=1' );
		$params   = array();

		if ( in_array( $args['status'], self::STATUSES, true ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}

		if ( in_array( $args['type'], self::TYPES, true ) ) {
			$where[]  = 'type = %s';
			$params[] = $args['type'];
		}

		if ( '' !== $args['search'] ) {
			$where[]  = '(name LIKE %s OR code LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$params[] = $like;
			$params[] = $like;
		}

		$table_name = self::table_name();
		$where_sql  = implode( ' AND ', $where );
		$sql        = "SELECT COUNT(*) FROM {$table_name} WHERE {$where_sql}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $params ) {
			return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Get a snippet by ID.
	 *
	 * @param int $id Snippet ID.
	 * @return array|null
	 */
	public function get( $id ) {
		global $wpdb;

		$table_name = self::table_name();
		$row        = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", absint( $id ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * Fetch active snippets for one location.
	 *
	 * @param string $location Location.
	 * @return array
	 */
	public function get_active_by_location( $location ) {
		global $wpdb;

		if ( ! in_array( $location, self::AUTOMATIC_LOCATIONS, true ) ) {
			return array();
		}

		$cache_key = 'location:' . $location;
		if ( isset( $this->active_cache[ $cache_key ] ) ) {
			return $this->active_cache[ $cache_key ];
		}

		$table_name = self::table_name();

		$this->active_cache[ $cache_key ] = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table_name} WHERE status = %s AND location = %s AND type <> %s ORDER BY priority ASC, id ASC", 'active', $location, 'php' ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return $this->active_cache[ $cache_key ];
	}

	/**
	 * Fetch active snippets by type.
	 *
	 * @param string $type Snippet type.
	 * @return array
	 */
	public function get_active_by_type( $type ) {
		global $wpdb;

		if ( ! in_array( $type, self::TYPES, true ) ) {
			return array();
		}

		$cache_key = 'type:' . $type;
		if ( isset( $this->active_cache[ $cache_key ] ) ) {
			return $this->active_cache[ $cache_key ];
		}

		$table_name = self::table_name();

		$this->active_cache[ $cache_key ] = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table_name} WHERE status = %s AND type = %s ORDER BY id ASC", 'active', $type ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return $this->active_cache[ $cache_key ];
	}

	/**
	 * Save a snippet.
	 *
	 * @param array $data Snippet data.
	 * @param int   $id Optional ID.
	 * @return int|false
	 */
	public function save( $data, $id = 0 ) {
		global $wpdb;

		$now        = current_time( 'mysql' );
		$table_name = self::table_name();
		$name       = sanitize_text_field( $this->string_value( $data['name'] ?? '' ) );
		$record     = array(
			'name'         => '' !== $name ? $name : __( 'Untitled Snippet', 'mcsm' ),
			'type'         => $this->sanitize_choice( $data['type'] ?? 'html', self::TYPES, 'html' ),
			'code'         => $this->string_value( $data['code'] ?? '' ),
			'notes'        => sanitize_textarea_field( $this->string_value( $data['notes'] ?? '' ) ),
			'location'     => $this->sanitize_choice( $data['location'] ?? 'header', self::LOCATIONS, 'header' ),
			'priority'     => self::sanitize_priority( $data['priority'] ?? self::DEFAULT_PRIORITY ),
			'display_rule' => $this->sanitize_display_rule( $data['display_rule'] ?? 'site_wide' ),
			'include_ids'  => $this->sanitize_ids_csv( $data['include_ids'] ?? '' ),
			'exclude_ids'  => $this->sanitize_ids_csv( $data['exclude_ids'] ?? '' ),
			'devices'      => $this->sanitize_choice( $data['devices'] ?? 'all', self::DEVICES, 'all' ),
			'status'       => $this->sanitize_choice( $data['status'] ?? 'inactive', self::STATUSES, 'inactive' ),
			'logged_in_only' => ! empty( $data['logged_in_only'] ) ? 1 : 0,
			'updated_by'   => get_current_user_id(),
			'updated_at'   => $now,
		);

		if ( $id ) {
			$result = $wpdb->update(
				$table_name,
				$record,
				array( 'id' => absint( $id ) ),
				array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' ),
				array( '%d' )
			);

			if ( false !== $result ) {
				$this->active_cache = array();
			}

			return false === $result ? false : absint( $id );
		}

		$record['created_at'] = $now;
		$record['created_by'] = get_current_user_id();
		$result               = $wpdb->insert(
			$table_name,
			$record,
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%d' )
		);

		if ( false !== $result ) {
			$this->active_cache = array();
		}

		return false === $result ? false : (int) $wpdb->insert_id;
	}

	/**
	 * Delete snippets.
	 *
	 * @param array $ids IDs.
	 * @return void
	 */
	public function delete_many( $ids ) {
		global $wpdb;

		$ids = $this->sanitize_ids_array( $ids );
		if ( empty( $ids ) ) {
			return;
		}

		$table_name   = self::table_name();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$sql          = "DELETE FROM {$table_name} WHERE id IN ({$placeholders})"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( $sql, $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->active_cache = array();
	}

	/**
	 * Update status for snippets.
	 *
	 * @param array  $ids IDs.
	 * @param string $status Status.
	 * @return bool Whether the database update succeeded.
	 */
	public function update_status_many( $ids, $status ) {
		global $wpdb;

		$ids    = $this->sanitize_ids_array( $ids );
		$status = $this->sanitize_choice( $status, self::STATUSES, 'inactive' );
		if ( empty( $ids ) ) {
			return false;
		}

		$table_name   = self::table_name();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$params       = array_merge( array( $status, get_current_user_id(), current_time( 'mysql' ) ), $ids );
		$sql          = "UPDATE {$table_name} SET status = %s, updated_by = %d, updated_at = %s WHERE id IN ({$placeholders})"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = false !== $wpdb->query( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( $result ) {
			$this->active_cache = array();
		}

		return $result;
	}

	/**
	 * Fetch all snippets for export.
	 *
	 * @return array
	 */
	public function all_for_export() {
		global $wpdb;

		$table_name = self::table_name();
		return $wpdb->get_results( "SELECT * FROM {$table_name} ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Import snippets from decoded JSON records.
	 *
	 * @param array $records Records.
	 * @return array{imported:int,skipped:int}
	 */
	public function import_records( $records ) {
		$result = array(
			'imported' => 0,
			'skipped'  => 0,
		);

		foreach ( $records as $record ) {
			if ( ! is_array( $record ) ) {
				$result['skipped']++;
				continue;
			}

			$type = $this->sanitize_choice( $record['type'] ?? 'html', self::TYPES, 'html' );
			$code = $this->string_value( $record['code'] ?? '' );

			if ( 'php' === $type && is_wp_error( MCSM_PHP_Executor::validate( $code ) ) ) {
				$result['skipped']++;
				continue;
			}

			$saved = $this->save(
				array(
					'name'         => $record['name'] ?? '',
					'type'         => $type,
					'code'         => $code,
					'notes'        => $record['notes'] ?? '',
					'location'     => $record['location'] ?? 'header',
					'priority'     => $record['priority'] ?? self::DEFAULT_PRIORITY,
					'display_rule' => $record['display_rule'] ?? 'site_wide',
					'include_ids'  => $record['include_ids'] ?? '',
					'exclude_ids'  => $record['exclude_ids'] ?? '',
					'devices'      => $record['devices'] ?? 'all',
					'status'       => $record['status'] ?? 'inactive',
					'logged_in_only' => $record['logged_in_only'] ?? 0,
				)
			);

			if ( $saved ) {
				$result['imported']++;
			} else {
				$result['skipped']++;
			}
		}

		return $result;
	}

	/**
	 * Normalize a WordPress hook priority.
	 *
	 * @param mixed $value Priority value.
	 * @return int
	 */
	public static function sanitize_priority( $value ) {
		if ( ! is_scalar( $value ) || ! preg_match( '/^-?\d+$/', trim( (string) $value ) ) ) {
			return self::DEFAULT_PRIORITY;
		}

		return max( self::MIN_PRIORITY, min( self::MAX_PRIORITY, (int) $value ) );
	}

	/**
	 * Normalize a choice value.
	 *
	 * @param string $value Value.
	 * @param array  $allowed Allowed values.
	 * @param string $fallback Fallback.
	 * @return string
	 */
	private function sanitize_choice( $value, $allowed, $fallback ) {
		$value = sanitize_key( $this->string_value( $value ) );
		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}

	/**
	 * Normalize display rules, including legacy values from earlier builds.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private function sanitize_display_rule( $value ) {
		$value = sanitize_key( $this->string_value( $value ) );

		if ( in_array( $value, array( 'specific_pages', 'specific_posts', 'exclude_selected', 'exclude_pages', 'exclude_posts' ), true ) ) {
			return in_array( $value, array( 'exclude_pages', 'exclude_posts' ), true ) ? 'exclude_selected' : $value;
		}

		if ( 0 === strpos( $value, 'specific_cpt_' ) ) {
			$post_type = substr( $value, strlen( 'specific_cpt_' ) );
			$allowed   = array_diff( get_post_types( array( 'public' => true ), 'names' ), array( 'attachment', 'page', 'post' ) );
			if ( in_array( $post_type, $allowed, true ) ) {
				return 'specific_cpt_' . $post_type;
			}
		}

		if ( in_array( $value, array( 'exclude_selected', 'exclude_pages', 'exclude_posts' ), true ) ) {
			return 'exclude_selected';
		}

		return 'site_wide';
	}

	/**
	 * Safely coerce scalar input to a string.
	 *
	 * @param mixed $value Input value.
	 * @return string
	 */
	private function string_value( $value ) {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Normalize CSV IDs.
	 *
	 * @param string|array $value Value.
	 * @return string
	 */
	private function sanitize_ids_csv( $value ) {
		return implode( ',', $this->sanitize_ids_array( is_array( $value ) ? $value : explode( ',', $this->string_value( $value ) ) ) );
	}

	/**
	 * Normalize IDs.
	 *
	 * @param array $ids IDs.
	 * @return array
	 */
	public function sanitize_ids_array( $ids ) {
		return array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) );
	}

}
