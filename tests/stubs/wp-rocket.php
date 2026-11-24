<?php
/**
 * Test stub of WP Rocket's public purge function, loaded only in a separate
 * PHPUnit process. It counts calls in $GLOBALS['notfortraining_stub_calls'].
 *
 * @package NotForTraining
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Stands in for WP Rocket's own function.

/**
 * Stub of rocket_clean_domain().
 *
 * @param mixed $lang Unused.
 */
function rocket_clean_domain( $lang = '' ) {
	unset( $lang );
	$GLOBALS['notfortraining_stub_calls'] = ( $GLOBALS['notfortraining_stub_calls'] ?? 0 ) + 1;
}
