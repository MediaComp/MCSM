<?php
/**
 * Shortcode support.
 *
 * @package MCSnippetManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MCSM_Shortcodes {
	/**
	 * Repository.
	 *
	 * @var MCSM_Repository
	 */
	private $repository;

	/**
	 * Renderer.
	 *
	 * @var MCSM_Renderer
	 */
	private $renderer;

	/**
	 * Constructor.
	 *
	 * @param MCSM_Repository $repository Repository.
	 */
	public function __construct( MCSM_Repository $repository ) {
		$this->repository = $repository;
		$this->renderer   = new MCSM_Renderer( $repository );
	}

	/**
	 * Register shortcodes.
	 */
	public function init() {
		add_shortcode( 'mc_snippet', array( $this, 'render' ) );
	}

	/**
	 * Render a snippet shortcode.
	 *
	 * Shortcodes are manual placement, so location and page display rules are not applied here.
	 * Active status and device rules are still respected.
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public function render( $atts ) {
		$atts = shortcode_atts(
			array(
				'id' => 0,
			),
			$atts,
			'mc_snippet'
		);

		$snippet = $this->repository->get( absint( $atts['id'] ) );
		if ( ! $snippet || 'php' === ( $snippet['type'] ?? '' ) || ! $this->renderer->should_render( $snippet, false ) ) {
			return '';
		}

		ob_start();
		$this->renderer->output_snippet( $snippet );
		return (string) ob_get_clean();
	}
}
