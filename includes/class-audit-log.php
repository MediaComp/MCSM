<?php
/**
 * Activity log storage and cleanup.
 *
 * @package MCSnippetManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MCSM_Audit_Log {
	const RETENTION_OPTION = 'mcsm_activity_log_retention';
	const CLEANUP_HOOK     = 'mcsm_cleanup_activity_log';

	/**
	 * Get the activity table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'mc_snippet_activity';
	}

	/**
	 * Record an administrative activity.
	 *
	 * @param string $event Event key.
	 * @param array  $data Event data.
	 * @return int|false
	 */
	public static function record( $event, $data = array() ) {
		global $wpdb;

		$event = sanitize_key( $event );
		if ( '' === $event ) {
			return false;
		}

		$changes = isset( $data['changes'] ) && is_array( $data['changes'] )
			? self::sanitize_changes( $data['changes'] )
			: array();

		$result = $wpdb->insert(
			self::table_name(),
			array(
				'event'        => $event,
				'object_type'  => sanitize_key( $data['object_type'] ?? 'global_snippet' ),
				'object_id'    => absint( $data['object_id'] ?? 0 ),
				'snippet_id'   => absint( $data['snippet_id'] ?? 0 ),
				'snippet_name' => sanitize_text_field( self::string_value( $data['snippet_name'] ?? '' ) ),
				'user_id'      => get_current_user_id(),
				'summary'      => sanitize_text_field( self::string_value( $data['summary'] ?? '' ) ),
				'changes'      => $changes ? wp_json_encode( $changes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : '',
				'created_at'   => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%d', '%d', '%s', '%d', '%s', '%s', '%s' )
		);

		return false === $result ? false : (int) $wpdb->insert_id;
	}

	/**
	 * Query activity rows.
	 *
	 * @param array $args Query arguments.
	 * @return array
	 */
	public static function query( $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'event'    => '',
				'user_id'  => 0,
				'search'   => '',
				'orderby'  => 'created_at',
				'order'    => 'DESC',
				'per_page' => 20,
				'offset'   => 0,
			)
		);

		list( $where_sql, $params ) = self::build_where( $args );
		$allowed_orderby = array( 'id', 'event', 'user_id', 'snippet_name', 'created_at' );
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
		$order           = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';
		$params[]        = max( 1, absint( $args['per_page'] ) );
		$params[]        = max( 0, absint( $args['offset'] ) );
		$table_name      = self::table_name();
		$sql             = "SELECT * FROM {$table_name} WHERE {$where_sql} ORDER BY {$orderby} {$order}, id {$order} LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Count activity rows.
	 *
	 * @param array $args Query arguments.
	 * @return int
	 */
	public static function count( $args = array() ) {
		global $wpdb;

		list( $where_sql, $params ) = self::build_where( $args );
		$table_name = self::table_name();
		$sql        = "SELECT COUNT(*) FROM {$table_name} WHERE {$where_sql}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $params
			? (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Get users represented in the activity log.
	 *
	 * @return array
	 */
	public static function get_user_ids() {
		global $wpdb;

		$table_name = self::table_name();
		return array_map( 'absint', $wpdb->get_col( "SELECT DISTINCT user_id FROM {$table_name} WHERE user_id > 0 ORDER BY user_id ASC" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Delete all activity rows.
	 *
	 * @return bool
	 */
	public static function clear() {
		global $wpdb;

		return false !== $wpdb->query( 'DELETE FROM ' . self::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Delete expired rows in small batches.
	 */
	public static function cleanup() {
		global $wpdb;

		$retention = absint( get_option( self::RETENTION_OPTION, 180 ) );
		if ( ! in_array( $retention, array( 30, 90, 180, 365 ), true ) ) {
			$retention = 180;
		}

		$cutoff     = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( $retention * DAY_IN_SECONDS ) );
		$table_name = self::table_name();
		$ids        = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table_name} WHERE created_at < %s ORDER BY id ASC LIMIT 500", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$cutoff
			)
		);

		if ( empty( $ids ) ) {
			return;
		}

		$ids          = array_map( 'absint', $ids );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table_name} WHERE id IN ({$placeholders})", $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Build a safe WHERE clause.
	 *
	 * @param array $args Query arguments.
	 * @return array
	 */
	private static function build_where( $args ) {
		global $wpdb;

		$args   = wp_parse_args( $args, array( 'event' => '', 'user_id' => 0, 'search' => '' ) );
		$where  = array( '1=1' );
		$params = array();

		if ( '' !== sanitize_key( $args['event'] ) ) {
			$where[]  = 'event = %s';
			$params[] = sanitize_key( $args['event'] );
		}

		if ( absint( $args['user_id'] ) ) {
			$where[]  = 'user_id = %d';
			$params[] = absint( $args['user_id'] );
		}

		$search = sanitize_text_field( self::string_value( $args['search'] ) );
		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(snippet_name LIKE %s OR summary LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}

		return array( implode( ' AND ', $where ), $params );
	}

	/**
	 * Sanitize nested change metadata without allowing executable content.
	 *
	 * @param array $changes Changes.
	 * @return array
	 */
	private static function sanitize_changes( $changes ) {
		$clean = array();

		foreach ( $changes as $key => $value ) {
			$key = sanitize_key( $key );
			if ( '' === $key ) {
				continue;
			}

			if ( is_array( $value ) ) {
				$clean[ $key ] = self::sanitize_changes( $value );
			} elseif ( is_bool( $value ) ) {
				$clean[ $key ] = $value;
			} elseif ( is_numeric( $value ) ) {
				$clean[ $key ] = 0 + $value;
			} elseif ( is_scalar( $value ) ) {
				$clean[ $key ] = sanitize_text_field( (string) $value );
			}
		}

		return $clean;
	}

	/**
	 * Coerce scalar input to a string.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function string_value( $value ) {
		return is_scalar( $value ) ? (string) $value : '';
	}
}
