<?php
/**
 * Activation and uninstall routines.
 *
 * @package MCSnippetManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MCSM_Activator {
	/**
	 * Create or update the custom snippets table.
	 */
	public static function activate() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = MCSM_Repository::table_name();
		$charset_collate = $wpdb->get_charset_collate();
		$table_existed   = $table_name === $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) )
		);

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(191) NOT NULL DEFAULT '',
			type varchar(20) NOT NULL DEFAULT 'html',
			code longtext NOT NULL,
			notes text NULL,
			location varchar(20) NOT NULL DEFAULT 'header',
			priority int NOT NULL DEFAULT 20,
			display_rule varchar(40) NOT NULL DEFAULT 'site_wide',
			include_ids text NULL,
			exclude_ids text NULL,
			devices varchar(20) NOT NULL DEFAULT 'all',
			status varchar(20) NOT NULL DEFAULT 'inactive',
			logged_in_only tinyint(1) unsigned NOT NULL DEFAULT 0,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY type (type),
			KEY location (location),
			KEY status_type (status, type),
			KEY status_location (status, location),
			KEY status_location_priority (status, location, priority),
			KEY updated_by (updated_by)
		) {$charset_collate};";

		dbDelta( $sql );

		$activity_table = MCSM_Audit_Log::table_name();
		$activity_sql   = "CREATE TABLE {$activity_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event varchar(40) NOT NULL DEFAULT '',
			object_type varchar(40) NOT NULL DEFAULT '',
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			snippet_id bigint(20) unsigned NOT NULL DEFAULT 0,
			snippet_name varchar(191) NOT NULL DEFAULT '',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			summary varchar(255) NOT NULL DEFAULT '',
			changes longtext NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY event (event),
			KEY user_id (user_id),
			KEY object_id (object_id),
			KEY snippet_id (snippet_id)
		) {$charset_collate};";

		dbDelta( $activity_sql );

		if ( ! $table_existed ) {
			self::install_default_snippets();
		}

		if ( false === get_option( MCSM_Audit_Log::RETENTION_OPTION, false ) ) {
			add_option( MCSM_Audit_Log::RETENTION_OPTION, 180 );
		}

		if ( ! wp_next_scheduled( MCSM_Audit_Log::CLEANUP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', MCSM_Audit_Log::CLEANUP_HOOK );
		}

		update_option( 'mcsm_version', MCSM_VERSION );
	}

	/**
	 * Remove scheduled maintenance when the plugin is disabled.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( MCSM_Audit_Log::CLEANUP_HOOK );
	}

	/**
	 * Install the maintainable starter snippet catalog on fresh databases.
	 */
	private static function install_default_snippets() {
		$catalog = require MCSM_PLUGIN_DIR . 'includes/default-snippets.php';
		$catalog = apply_filters( 'mcsm_default_snippets', is_array( $catalog ) ? $catalog : array() );

		if ( empty( $catalog ) ) {
			return;
		}

		$repository = new MCSM_Repository();

		foreach ( $catalog as $snippet ) {
			if ( is_array( $snippet ) ) {
				$repository->save( $snippet );
			}
		}
	}
}
