<?php
/**
 * All in One SEO:
 *
 * - It prints its own robots meta tag and removes every wp_robots filter,
 *   so noai and noimageai go into its tag through its aioseo_robots_meta
 *   filter (a keyed array, joined with ", ").
 * - It rebuilds robots.txt on robots_txt at priority 10000, re-parsing what
 *   came before. Its parser gives user agents that share a group only the
 *   group's first rule, and drops comments (including our marker line).
 *   So with AIOSEO active, the plugin's block goes in after AIOSEO's
 *   output instead of before it, exactly as written.
 *
 * @package NotForTraining
 */

defined( 'ABSPATH' ) || exit;

/**
 * Hook in.
 */
function notfortraining_compat_aioseo_register() {
	add_filter( 'aioseo_robots_meta', 'notfortraining_compat_aioseo_robots_meta' );
	remove_filter( 'robots_txt', 'notfortraining_robots_txt', 999 );
	add_filter( 'robots_txt', 'notfortraining_robots_txt', notfortraining_compat_aioseo_robots_priority(), 2 );
}

/**
 * Unhook, restoring the normal robots.txt priority.
 */
function notfortraining_compat_aioseo_unregister() {
	remove_filter( 'aioseo_robots_meta', 'notfortraining_compat_aioseo_robots_meta' );
	remove_filter( 'robots_txt', 'notfortraining_robots_txt', notfortraining_compat_aioseo_robots_priority() );
	add_filter( 'robots_txt', 'notfortraining_robots_txt', 999, 2 );
}

/**
 * Just after AIOSEO's robots.txt builder (10000).
 *
 * @return int
 */
function notfortraining_compat_aioseo_robots_priority() {
	return 10001;
}

/**
 * Add noai and noimageai after AIOSEO's own values.
 *
 * @param array $attributes Directives, keyed.
 * @return array
 */
function notfortraining_compat_aioseo_robots_meta( $attributes ) {
	if ( ! is_array( $attributes ) || ! notfortraining_get_option( 'noai_signals' ) ) {
		return $attributes;
	}
	$attributes['noai']      = 'noai';
	$attributes['noimageai'] = 'noimageai';
	return $attributes;
}
