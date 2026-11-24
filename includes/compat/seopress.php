<?php
/**
 * SEOPress prints its own robots meta tag from wp_head
 * (seopress_titles_advanced_robots_hook) but leaves core's wp_robots
 * running, so adding noai to core's list would print a second robots tag.
 * When SEOPress's tag is going out, noai and noimageai go into it through
 * its seopress_titles_robots_attrs filter (a list) and come out of core's
 * list. When it isn't (its Titles module is off, or the site discourages
 * search engines), core's tag keeps them.
 *
 * @package NotForTraining
 */

defined( 'ABSPATH' ) || exit;

/**
 * Hook in.
 */
function notfortraining_compat_seopress_register() {
	add_filter( 'seopress_titles_robots_attrs', 'notfortraining_compat_seopress_attrs' );
	add_filter( 'wp_robots', 'notfortraining_compat_seopress_wp_robots', 1000 );
}

/**
 * Unhook.
 */
function notfortraining_compat_seopress_unregister() {
	remove_filter( 'seopress_titles_robots_attrs', 'notfortraining_compat_seopress_attrs' );
	remove_filter( 'wp_robots', 'notfortraining_compat_seopress_wp_robots', 1000 );
}

/**
 * Whether SEOPress will print its own robots meta tag on this request.
 *
 * @return bool
 */
function notfortraining_compat_seopress_prints_robots() {
	return false !== has_action( 'wp_head', 'seopress_titles_advanced_robots_hook' );
}

/**
 * Add noai and noimageai after SEOPress's own values.
 *
 * @param array $attrs Directive strings.
 * @return array
 */
function notfortraining_compat_seopress_attrs( $attrs ) {
	if ( ! is_array( $attrs ) || ! notfortraining_get_option( 'noai_signals' ) ) {
		return $attrs;
	}
	foreach ( array( 'noai', 'noimageai' ) as $value ) {
		if ( ! in_array( $value, $attrs, true ) ) {
			$attrs[] = $value;
		}
	}
	return $attrs;
}

/**
 * Keep noai and noimageai out of core's tag while SEOPress prints its own,
 * so the page has one robots tag.
 *
 * @param array $robots Core's directives.
 * @return array
 */
function notfortraining_compat_seopress_wp_robots( $robots ) {
	if ( is_array( $robots ) && notfortraining_compat_seopress_prints_robots() ) {
		unset( $robots['noai'], $robots['noimageai'] );
	}
	return $robots;
}
