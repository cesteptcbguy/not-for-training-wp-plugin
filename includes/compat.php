<?php
/**
 * Compatibility shims for SEO plugins that print their own robots meta
 * tag (instead of core's wp_robots) or rebuild robots.txt. Each shim is
 * loaded only when its plugin is active, detected by that plugin's own
 * version constant once all plugins have loaded.
 *
 * @package NotForTraining
 */

defined( 'ABSPATH' ) || exit;

add_action( 'plugins_loaded', 'notfortraining_load_compat', 20 );

/**
 * Shims by file name, and whether their plugin is active.
 *
 * @return array<string, bool>
 */
function notfortraining_compat_active() {
	return array(
		'rank-math' => defined( 'RANK_MATH_VERSION' ),
		'aioseo'    => defined( 'AIOSEO_VERSION' ),
		'seopress'  => defined( 'SEOPRESS_VERSION' ),
	);
}

/**
 * Load and register the shims for active plugins.
 *
 * @param array<string, bool>|null $active Shim => active; null to detect.
 */
function notfortraining_load_compat( $active = null ) {
	$active = is_array( $active ) ? $active : notfortraining_compat_active();
	foreach ( $active as $shim => $is_active ) {
		if ( ! $is_active || ! preg_match( '/^[a-z-]+$/', $shim ) ) {
			continue;
		}
		$file = NOTFORTRAINING_DIR . 'includes/compat/' . $shim . '.php';
		if ( is_file( $file ) ) {
			require_once $file;
			call_user_func( 'notfortraining_compat_' . str_replace( '-', '_', $shim ) . '_register' );
		}
	}
}
