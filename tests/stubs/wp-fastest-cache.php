<?php
/**
 * Test stub of WP Fastest Cache's public purge function, loaded only in a separate
 * PHPUnit process. It counts calls in $GLOBALS['notfortraining_stub_calls'].
 *
 * @package NotForTraining
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Stands in for WP Fastest Cache's own function.

/**
 * Stub of wpfc_clear_all_cache().
 *
 * @param mixed $minified Unused.
 */
function wpfc_clear_all_cache( $minified = false ) {
	unset( $minified );
	$GLOBALS['notfortraining_stub_calls'] = ( $GLOBALS['notfortraining_stub_calls'] ?? 0 ) + 1;
}
