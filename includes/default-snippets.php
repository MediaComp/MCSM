<?php
/**
 * Snippets installed with a fresh plugin database.
 *
 * Add, remove, or edit records in this catalog without changing activation logic.
 *
 * @package MCSnippetManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	array(
		'name'         => __( 'Disable Comments', 'mcsm' ),
		'type'         => 'php',
		'code'         => <<<'PHP'
// Disable comments and trackbacks for all post types.
add_action( 'init', function () {
	foreach ( get_post_types( array(), 'names' ) as $post_type ) {
		remove_post_type_support( $post_type, 'comments' );
		remove_post_type_support( $post_type, 'trackbacks' );
	}

	remove_action( 'admin_bar_menu', 'wp_admin_bar_comments_menu', 60 );
}, 100 );

// Close comments and pingbacks on the frontend.
add_filter( 'comments_open', '__return_false', 20 );
add_filter( 'pings_open', '__return_false', 20 );

// Hide existing comments without deleting them.
add_filter( 'comments_array', '__return_empty_array', 10 );

// Remove comments from the WordPress administration interface.
add_action( 'admin_menu', function () {
	remove_menu_page( 'edit-comments.php' );
}, 999 );

add_action( 'wp_dashboard_setup', function () {
	remove_meta_box( 'dashboard_recent_comments', 'dashboard', 'normal' );
} );

// Redirect direct access to the comments administration page.
add_action( 'admin_init', function () {
	global $pagenow;

	if ( 'edit-comments.php' === $pagenow ) {
		wp_safe_redirect( admin_url() );
		return;
	}
} );
PHP,
		'notes'        => __( 'Disables new comments and pingbacks, hides existing comments without deleting them, and removes comment management from the WordPress admin interface.', 'mcsm' ),
		'location'     => 'header',
		'display_rule' => 'site_wide',
		'include_ids'  => '',
		'exclude_ids'  => '',
		'devices'      => 'all',
		'status'       => 'inactive',
		'logged_in_only' => 0,
	),
);
