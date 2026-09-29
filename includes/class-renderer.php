<?php
/**
 * Frontend snippet output.
 *
 * @package MCSnippetManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MCSM_Renderer {
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
	 * Register render hooks.
	 */
	public function init() {
		add_action( 'init', array( $this, 'execute_site_wide_php_snippets' ), 0 );
		add_action( 'wp', array( $this, 'execute_php_snippets' ), 20 );
		$this->register_global_render_hooks();
		add_action( 'wp_head', array( $this, 'render_local_header' ), MCSM_Repository::DEFAULT_PRIORITY );
		add_action( 'wp_body_open', array( $this, 'render_local_body_open' ), MCSM_Repository::DEFAULT_PRIORITY );
		add_action( 'wp_footer', array( $this, 'render_local_footer' ), MCSM_Repository::DEFAULT_PRIORITY );
	}

	/**
	 * Register active global snippets on their selected WordPress hooks.
	 */
	private function register_global_render_hooks() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		$hooks = array(
			'header'    => 'wp_head',
			'body_open' => 'wp_body_open',
			'footer'    => 'wp_footer',
		);

		foreach ( $hooks as $location => $hook ) {
			foreach ( $this->repository->get_active_by_location( $location ) as $snippet ) {
				$priority = MCSM_Repository::sanitize_priority( $snippet['priority'] ?? MCSM_Repository::DEFAULT_PRIORITY );
				add_action(
					$hook,
					function () use ( $snippet ) {
						$this->render_registered_snippet( $snippet );
					},
					$priority
				);
			}
		}
	}

	/**
	 * Render one global snippet registered on a WordPress hook.
	 *
	 * @param array $snippet Snippet.
	 */
	private function render_registered_snippet( $snippet ) {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		if ( $this->should_render( $snippet, true ) ) {
			$this->output_snippet( $snippet );
		}
	}

	/**
	 * Execute site-wide PHP snippets early enough to register WordPress hooks.
	 */
	public function execute_site_wide_php_snippets() {
		if ( MCSM_PHP_Executor::is_disabled() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		foreach ( $this->repository->get_active_by_type( 'php' ) as $snippet ) {
			if ( ! $this->is_unconditional_site_wide( $snippet ) ) {
				continue;
			}

			if ( $this->should_render( $snippet, false ) ) {
				MCSM_PHP_Executor::execute( $snippet );
			}
		}
	}

	/**
	 * Execute conditional global and local PHP snippets after the main query is ready.
	 */
	public function execute_php_snippets() {
		if ( MCSM_PHP_Executor::is_disabled() || is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		foreach ( $this->repository->get_active_by_type( 'php' ) as $snippet ) {
			if ( $this->is_unconditional_site_wide( $snippet ) ) {
				continue;
			}

			if ( $this->should_render( $snippet, true ) ) {
				MCSM_PHP_Executor::execute( $snippet );
			}
		}

		$this->execute_local_php_snippets();
	}

	/**
	 * Render header snippets.
	 */
	public function render_header() {
		$this->render_location( 'header' );
	}

	/**
	 * Render body-open snippets.
	 */
	public function render_body_open() {
		$this->render_location( 'body_open' );
	}

	/**
	 * Render footer snippets.
	 */
	public function render_footer() {
		$this->render_location( 'footer' );
	}

	/**
	 * Render local header snippets at the default priority.
	 */
	public function render_local_header() {
		$this->render_local_snippets( 'header' );
	}

	/**
	 * Render local body-open snippets at the default priority.
	 */
	public function render_local_body_open() {
		$this->render_local_snippets( 'body_open' );
	}

	/**
	 * Render local footer snippets at the default priority.
	 */
	public function render_local_footer() {
		$this->render_local_snippets( 'footer' );
	}

	/**
	 * Render snippets for a hook location.
	 *
	 * @param string $location Location.
	 */
	private function render_location( $location ) {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		$snippets = $this->repository->get_active_by_location( $location );

		foreach ( $snippets as $snippet ) {
			if ( 'php' === ( $snippet['type'] ?? '' ) ) {
				continue;
			}

			if ( $this->should_render( $snippet, true ) ) {
				$this->output_snippet( $snippet );
			}
		}

		$this->render_local_snippets( $location );
	}

	/**
	 * Determine if a snippet can render.
	 *
	 * @param array $snippet Snippet.
	 * @param bool  $respect_display_rules Whether to evaluate page/post display rules.
	 * @return bool
	 */
	public function should_render( $snippet, $respect_display_rules = true ) {
		if ( empty( $snippet['code'] ) || 'active' !== ( $snippet['status'] ?? '' ) ) {
			return false;
		}

		if ( ! empty( $snippet['logged_in_only'] ) && ! is_user_logged_in() ) {
			return false;
		}

		if ( ! $this->device_matches( $snippet['devices'] ?? 'all' ) ) {
			return false;
		}

		if ( $respect_display_rules && ! $this->display_rule_matches( $snippet ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Print one snippet.
	 *
	 * @param array $snippet Snippet.
	 */
	public function output_snippet( $snippet ) {
		$code = (string) ( $snippet['code'] ?? '' );
		if ( '' === trim( $code ) || 'php' === ( $snippet['type'] ?? '' ) ) {
			return;
		}

		$snippet_id = isset( $snippet['id'] ) ? sanitize_text_field( (string) $snippet['id'] ) : '0';

		$show_comments = 'yes' === get_option( 'mcsm_output_comments', 'no' );

		if ( $show_comments ) {
			echo "\n<!-- MCSM: " . esc_html( $snippet['name'] ?? 'Snippet' ) . ' #' . esc_html( $snippet_id ) . " -->\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo $this->wrap_code( $snippet['type'] ?? 'html', $code ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( $show_comments ) {
			echo "\n<!-- /MCSM -->\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/**
	 * Wrap CSS/JS snippets when admins enter raw code.
	 *
	 * @param string $type Type.
	 * @param string $code Code.
	 * @return string
	 */
	private function wrap_code( $type, $code ) {
		$type    = sanitize_key( $type );
		$trimmed = trim( $code );

		if ( 'css' === $type && ! preg_match( '/^\s*<style[\s>]/i', $trimmed ) ) {
			return "<style>\n" . $code . "\n</style>";
		}

		if ( 'javascript' === $type && ! preg_match( '/^\s*<script[\s>]/i', $trimmed ) ) {
			return "<script>\n" . $code . "\n</script>";
		}

		return $code;
	}

	/**
	 * Check the device rule.
	 *
	 * @param string $device_rule Device rule.
	 * @return bool
	 */
	private function device_matches( $device_rule ) {
		$is_mobile = wp_is_mobile();

		if ( 'mobile' === $device_rule ) {
			return $is_mobile;
		}

		if ( 'desktop' === $device_rule ) {
			return ! $is_mobile;
		}

		return true;
	}

	/**
	 * Check the display rule.
	 *
	 * @param array $snippet Snippet.
	 * @return bool
	 */
	private function display_rule_matches( $snippet ) {
		$rule        = $snippet['display_rule'] ?? 'site_wide';
		$current_id  = get_queried_object_id();
		$include_ids = $this->csv_to_ids( $snippet['include_ids'] ?? '' );
		$exclude_ids = $this->csv_to_ids( $snippet['exclude_ids'] ?? '' );

		switch ( $rule ) {
			case 'specific_pages':
				return is_page() && $current_id && in_array( $current_id, $include_ids, true );

			case 'specific_posts':
				return is_single() && 'post' === get_post_type( $current_id ) && in_array( $current_id, $include_ids, true );

			case 'exclude_selected':
			case 'exclude_pages':
			case 'exclude_posts':
				return ! ( is_singular() && $current_id && in_array( $current_id, $exclude_ids, true ) );

			case 'site_wide':
				return ! ( is_singular() && $current_id && in_array( $current_id, $exclude_ids, true ) );
		}

		if ( 0 === strpos( $rule, 'specific_cpt_' ) ) {
			$post_type = substr( $rule, strlen( 'specific_cpt_' ) );
			return is_singular( $post_type ) && $current_id && in_array( $current_id, $include_ids, true );
		}

		return true;
	}

	/**
	 * Determine whether a PHP snippet can run before the main query exists.
	 *
	 * @param array $snippet Snippet.
	 * @return bool
	 */
	private function is_unconditional_site_wide( $snippet ) {
		return 'site_wide' === ( $snippet['display_rule'] ?? 'site_wide' )
			&& empty( $this->csv_to_ids( $snippet['exclude_ids'] ?? '' ) );
	}

	/**
	 * Render snippets stored on the current page, post, or public CPT.
	 *
	 * @param string $location Location.
	 */
	private function render_local_snippets( $location ) {
		if ( ! is_singular() ) {
			return;
		}

		$post_id  = get_queried_object_id();
		$snippets = get_post_meta( $post_id, '_mcsm_local_snippets', true );

		if ( ! is_array( $snippets ) ) {
			return;
		}

		foreach ( $snippets as $index => $snippet ) {
			if ( 'php' === ( $snippet['type'] ?? '' ) ) {
				continue;
			}

			if ( $location !== ( $snippet['location'] ?? '' ) ) {
				continue;
			}

			$snippet['id']           = 'local-' . absint( $post_id ) . '-' . absint( $index );
			$snippet['display_rule'] = 'site_wide';

			if ( $this->should_render( $snippet, false ) ) {
				$this->output_snippet( $snippet );
			}
		}
	}

	/**
	 * Execute PHP snippets stored on the current singular item.
	 */
	private function execute_local_php_snippets() {
		if ( ! is_singular() ) {
			return;
		}

		$post_id  = get_queried_object_id();
		$snippets = get_post_meta( $post_id, '_mcsm_local_snippets', true );

		if ( ! is_array( $snippets ) ) {
			return;
		}

		foreach ( $snippets as $index => $snippet ) {
			if ( 'php' !== ( $snippet['type'] ?? '' ) ) {
				continue;
			}

			$snippet['id']           = 'local-' . absint( $post_id ) . '-' . absint( $index );
			$snippet['display_rule'] = 'site_wide';

			if ( $this->should_render( $snippet, false ) ) {
				MCSM_PHP_Executor::execute( $snippet );
			}
		}
	}

	/**
	 * Convert CSV IDs to integers.
	 *
	 * @param string $csv CSV.
	 * @return array
	 */
	private function csv_to_ids( $csv ) {
		return array_values( array_filter( array_map( 'absint', explode( ',', (string) $csv ) ) ) );
	}

}
