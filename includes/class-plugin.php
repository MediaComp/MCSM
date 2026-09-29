<?php
/**
 * Main plugin bootstrap.
 *
 * @package MCSnippetManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MCSM_Plugin {
	/**
	 * Register plugin services.
	 */
	public static function init() {
		add_action( MCSM_Audit_Log::CLEANUP_HOOK, array( 'MCSM_Audit_Log', 'cleanup' ) );

		if ( get_option( 'mcsm_version' ) !== MCSM_VERSION ) {
			MCSM_Activator::activate();
			update_option( 'mcsm_version', MCSM_VERSION );
		}

		$repository = new MCSM_Repository();

		if ( is_admin() ) {
			$admin = new MCSM_Admin( $repository );
			$admin->init();
		}

		$renderer = new MCSM_Renderer( $repository );
		$renderer->init();

		$shortcodes = new MCSM_Shortcodes( $repository );
		$shortcodes->init();
	}
}
