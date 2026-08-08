<?php
/**
 * S5 shell theme — bootstrap.
 *
 * Step 0: enqueue hook stub only. No logic. The design-system stylesheet
 * enqueue, the canvas/runtime template, and everything else land in later
 * Build Order steps. This file exists so the enqueue seam is present and
 * obvious, not to do work yet.
 *
 * @package S5\Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueue front-end assets.
 *
 * Intentionally empty in Step 0. Later steps enqueue the compiled design
 * system here (see Authoring Contract Part A / PRD §5).
 *
 * @return void
 */
function s5_theme_enqueue_assets(): void {
	// No assets in Step 0.
}
add_action( 'wp_enqueue_scripts', 's5_theme_enqueue_assets' );
