<?php
/**
 * LiteSpeed Cache: with "Load JS Delayed", every script waits for the
 * visitor's first interaction, so the first right-click on an image would
 * still open the menu. LiteSpeed leaves scripts with a data-no-defer
 * attribute alone (not combined, deferred or delayed), so the save
 * friction script gets one. Its CSS isn't affected by LiteSpeed's delay.
 *
 * @package NotForTraining
 */

defined( 'ABSPATH' ) || exit;

/**
 * Hook in.
 */
function notfortraining_compat_litespeed_register() {
	add_filter( 'wp_inline_script_attributes', 'notfortraining_compat_litespeed_no_defer' );
}

/**
 * Unhook.
 */
function notfortraining_compat_litespeed_unregister() {
	remove_filter( 'wp_inline_script_attributes', 'notfortraining_compat_litespeed_no_defer' );
}

/**
 * Add data-no-defer to the save friction script tag.
 *
 * @param array $attributes Script tag attributes.
 * @return array
 */
function notfortraining_compat_litespeed_no_defer( $attributes ) {
	if ( is_array( $attributes ) && 'notfortraining-save-friction-js-after' === ( $attributes['id'] ?? '' ) ) {
		$attributes['data-no-defer'] = '1';
	}
	return $attributes;
}
