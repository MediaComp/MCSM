<?php
/**
 * Plugin Name: MCSM
 * Description: Manage trusted HTML, JavaScript, CSS, and PHP snippets with global or content-specific display rules.
 * Version: 1.3.0
 * Author: Media Components LLC
 * Text Domain: mcsm
 * License: Proprietary - Media Components LLC Internal Use Only
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * @package MCSnippetManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MCSM_VERSION', '1.3.0' );
define( 'MCSM_PLUGIN_FILE', __FILE__ );
define( 'MCSM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MCSM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once MCSM_PLUGIN_DIR . 'includes/class-repository.php';
require_once MCSM_PLUGIN_DIR . 'includes/class-audit-log.php';
require_once MCSM_PLUGIN_DIR . 'includes/class-activator.php';
require_once MCSM_PLUGIN_DIR . 'includes/class-php-executor.php';
require_once MCSM_PLUGIN_DIR . 'includes/class-renderer.php';
require_once MCSM_PLUGIN_DIR . 'includes/class-shortcodes.php';
require_once MCSM_PLUGIN_DIR . 'includes/class-admin.php';
require_once MCSM_PLUGIN_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'MCSM_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'MCSM_Activator', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'MCSM_Plugin', 'init' ) );
