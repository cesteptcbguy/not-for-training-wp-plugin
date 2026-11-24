<?php
/**
 * Test stub of W3 Total Cache's public purge function, loaded only in a separate
 * PHPUnit process. It counts calls in $GLOBALS['notfortraining_stub_calls'].
 *
 * @package NotForTraining
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Stands in for W3 Total Cache's own function.

/**
 * Stub of w3tc_flush_all().
 *
 * @param mixed $extras Unused.
 */
function w3tc_flush_all( $extras = null ) {
	unset( $extras );
	$GLOBALS['notfortraining_stub_calls'] = ( $GLOBALS['notfortraining_stub_calls'] ?? 0 ) + 1;
}
