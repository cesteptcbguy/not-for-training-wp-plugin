<?php
/**
 * Rank Math SEO prints its own robots meta tag and removes every wp_robots
 * filter, so noai and noimageai go into its tag through its
 * rank_math/frontend/robots filter (a keyed array, joined with ", ").
 *
 * @package NotForTraining
 */

defined( 'ABSPATH' ) || exit;

/**
 * Hook in.
 */
function notfortraining_compat_rank_math_register() {
	add_filter( 'rank_math/frontend/robots', 'notfortraining_compat_rank_math_robots' );
}

/**
 * Unhook.
 */
function notfortraining_compat_rank_math_unregister() {
	remove_filter( 'rank_math/frontend/robots', 'notfortraining_compat_rank_math_robots' );
}

/**
 * Add noai and noimageai after Rank Math's own values.
 *
 * @param array $robots Directives, keyed.
 * @return array
 */
function notfortraining_compat_rank_math_robots( $robots ) {
	if ( ! is_array( $robots ) || ! notfortraining_get_option( 'noai_signals' ) ) {
		return $robots;
	}
	$robots['noai']      = 'noai';
	$robots['noimageai'] = 'noimageai';
	return $robots;
}
