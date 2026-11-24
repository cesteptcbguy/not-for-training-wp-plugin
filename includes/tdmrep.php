<?php
/**
 * /.well-known/tdmrep.json (W3C TDMRep), served by WordPress so no file has
 * to be copied to the server.
 *
 * @package NotForTraining
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', 'notfortraining_add_rewrite_rules' );
add_action( 'init', 'notfortraining_maybe_flush_rewrite_rules', 99 );
add_filter( 'query_vars', 'notfortraining_query_vars' );
add_action( 'parse_request', 'notfortraining_serve_tdmrep' );

/**
 * The rewrite regex for tdmrep.json, relative to the home URL.
 *
 * @return string
 */
function notfortraining_tdmrep_regex() {
	return '^\.well-known/tdmrep\.json$';
}

/**
 * Route /.well-known/tdmrep.json to WordPress.
 */
function notfortraining_add_rewrite_rules() {
	add_rewrite_rule( notfortraining_tdmrep_regex(), 'index.php?notfortraining_tdmrep=1', 'top' );
}

/**
 * Take the tdmrep.json rule back out (on deactivation, before the flush).
 */
function notfortraining_remove_rewrite_rules() {
	global $wp_rewrite;
	if ( $wp_rewrite instanceof WP_Rewrite ) {
		unset( $wp_rewrite->extra_rules_top[ notfortraining_tdmrep_regex() ] );
	}
}

/**
 * Flush once when the plugin's rules are new to this site: after an update
 * that changes them, or on sites of a network where the plugin was
 * network-activated (the activation hook only flushes the current site).
 */
function notfortraining_maybe_flush_rewrite_rules() {
	if ( get_option( 'notfortraining_rewrite_version' ) === NOTFORTRAINING_REWRITE_VERSION ) {
		return;
	}
	flush_rewrite_rules( false );
	update_option( 'notfortraining_rewrite_version', NOTFORTRAINING_REWRITE_VERSION, false );
}

/**
 * Register the query var the rewrite rule sets.
 *
 * @param string[] $vars Public query vars.
 * @return string[]
 */
function notfortraining_query_vars( $vars ) {
	$vars[] = 'notfortraining_tdmrep';
	return $vars;
}

/**
 * The tdmrep.json document: every path under the home URL has TDM rights
 * reserved. For a site at the domain root that's
 * [{"location":"/*","tdm-reservation":1}]; a site in /blog/ covers "/blog/*".
 *
 * @return array<int, array<string, mixed>>
 */
function notfortraining_tdmrep_data() {
	$path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
	$path = trailingslashit( '/' . ltrim( $path, '/' ) );

	$data = array(
		array(
			'location'        => $path . '*',
			'tdm-reservation' => 1,
		),
	);

	/**
	 * Filters the tdmrep.json document, for example to add a "tdm-policy"
	 * URL to the rule.
	 *
	 * @param array $data List of TDMRep rules.
	 */
	return apply_filters( 'notfortraining_tdmrep', $data );
}

/**
 * Answer /.well-known/tdmrep.json with the JSON document and stop.
 *
 * @param WP $wp Current request.
 */
function notfortraining_serve_tdmrep( $wp ) {
	if ( empty( $wp->query_vars['notfortraining_tdmrep'] ) ) {
		return;
	}
	if ( ! notfortraining_get_option( 'tdm_reservation' ) ) {
		// Turned off: a plain 404, not the home page the bare query var would show.
		$wp->query_vars = array( 'error' => '404' );
		return;
	}
	wp_send_json( notfortraining_tdmrep_data(), 200, JSON_UNESCAPED_SLASHES );
}
