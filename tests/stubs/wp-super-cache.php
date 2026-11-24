<?php
/**
 * Test stub of WP Super Cache's public purge function, loaded only in a separate
 * PHPUnit process. It counts calls in $GLOBALS['notfortraining_stub_calls'].
 *
 * @package NotForTraining
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Stands in for WP Super Cache's own function.

/**
 * Stub of wp_cache_clear_cache().
 *
 * @param mixed $blog_id Unused.
 */
function wp_cache_clear_cache( $blog_id = 0 ) {
	unset( $blog_id );
	$GLOBALS['notfortraining_stub_calls'] = ( $GLOBALS['notfortraining_stub_calls'] ?? 0 ) + 1;
}
