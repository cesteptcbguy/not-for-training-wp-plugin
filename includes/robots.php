<?php
/**
 * Crawler and AI opt-out signals: robots.txt groups, the noai robots meta,
 * the tdm-reservation meta, and the matching response headers.
 *
 * @package NotForTraining
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'robots_txt', 'notfortraining_robots_txt', 999, 2 );
add_filter( 'wp_robots', 'notfortraining_wp_robots', 999 );
add_action( 'wp_head', 'notfortraining_tdm_meta', 1 );
add_action( 'send_headers', 'notfortraining_send_headers' );

/**
 * Append the AI crawler groups to WordPress's virtual robots.txt.
 *
 * Runs late (999) so core's rules, SEO plugins' rules and Sitemap lines are
 * already in place. Only appends: the "User-agent: *" group is never edited,
 * so search engines read exactly what they did before. A site set to
 * discourage search engines is returned unchanged.
 *
 * In "training" mode the search group isn't just "Allow: /": a crawler that
 * matches its own group ignores the "*" group, so the group repeats the
 * admin and add-to-cart exceptions, specific rules first and "Allow: /" last,
 * so first-match and longest-match parsers read it the same way.
 *
 * @param string $output    robots.txt so far.
 * @param string $is_public The blog_public option ("1" or "0").
 * @return string
 */
function notfortraining_robots_txt( $output, $is_public ) {
	if ( ! $is_public ) {
		return $output;
	}

	$mode = notfortraining_get_option( 'robots_mode' );
	if ( 'off' === $mode ) {
		return $output;
	}

	$bots  = notfortraining_get_bots();
	$block = '';

	if ( $bots['training'] ) {
		$block .= "\n# Not For Training: AI training and dataset crawlers are not permitted.\n";
		$block .= notfortraining_robots_user_agents( $bots['training'] );
		$block .= "Disallow: /\n";
	}

	if ( $bots['search'] ) {
		if ( 'all' === $mode ) {
			$block .= "\n# Not For Training: AI search and user-requested fetches are not permitted.\n";
			$block .= notfortraining_robots_user_agents( $bots['search'] );
			$block .= "Disallow: /\n";
		} else {
			$block .= "\n# Not For Training: AI search and user-requested fetches are permitted, same rules as search engines.\n";
			$block .= notfortraining_robots_user_agents( $bots['search'] );
			$block .= implode( "\n", notfortraining_robots_search_rules() ) . "\n";
		}
	}

	if ( '' === $block ) {
		return $output;
	}

	return rtrim( (string) $output ) . "\n" . $block;
}

/**
 * One "User-agent:" line per token.
 *
 * @param string[] $agents User agent tokens.
 * @return string
 */
function notfortraining_robots_user_agents( array $agents ) {
	$lines = '';
	foreach ( $agents as $agent ) {
		$lines .= 'User-agent: ' . $agent . "\n";
	}
	return $lines;
}

/**
 * Rules for the AI search group in "training" mode: the same exceptions
 * search engines get from the "*" group (core's admin rules, with the same
 * subdirectory prefix core uses, plus WooCommerce's add-to-cart rules),
 * then "Allow: /" last.
 *
 * @return string[]
 */
function notfortraining_robots_search_rules() {
	$path = (string) wp_parse_url( site_url(), PHP_URL_PATH );
	$path = untrailingslashit( $path );

	return array(
		'Allow: ' . $path . '/wp-admin/admin-ajax.php',
		'Disallow: ' . $path . '/wp-admin/',
		'Disallow: /*?add-to-cart=',
		'Disallow: /*?*add-to-cart=',
		'Allow: /',
	);
}

/**
 * Add noai and noimageai to core's robots meta list, so the page keeps one
 * robots meta tag with every other plugin's values plus these two. Search
 * engines ignore values they don't know, so indexing and image previews are
 * unchanged.
 *
 * @param array<string, bool|string> $robots Robots directives.
 * @return array<string, bool|string>
 */
function notfortraining_wp_robots( $robots ) {
	if ( ! notfortraining_get_option( 'noai_signals' ) ) {
		return $robots;
	}
	$robots              = (array) $robots;
	$robots['noai']      = true;
	$robots['noimageai'] = true;
	return $robots;
}

/**
 * TDM reservation (W3C TDMRep): text and data mining rights are reserved.
 * Pairs with /.well-known/tdmrep.json.
 */
function notfortraining_tdm_meta() {
	if ( ! notfortraining_get_option( 'tdm_reservation' ) ) {
		return;
	}
	echo '<meta name="tdm-reservation" content="1">' . "\n";
}

/**
 * The headers to send on front-end responses.
 *
 * @return array<string, string> Header name => value.
 */
function notfortraining_response_headers() {
	$headers = array();
	if ( notfortraining_get_option( 'noai_signals' ) ) {
		$headers['X-Robots-Tag'] = 'noai, noimageai';
	}
	if ( notfortraining_get_option( 'tdm_reservation' ) ) {
		$headers['tdm-reservation'] = '1';
	}
	return $headers;
}

/**
 * Whether the current request is a front-end page view: not wp-admin,
 * admin-ajax, cron, wp-login.php, REST or XML-RPC, and not a feed,
 * robots.txt or sitemap.
 *
 * On send_headers the main query hasn't run yet, so is_feed() and friends
 * are still false; the parsed query vars on $wp are checked instead.
 *
 * @param WP|null $wp Current request, when known.
 * @return bool
 */
function notfortraining_is_front_end_request( $wp = null ) {
	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || is_login() ) {
		return false;
	}
	if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {
		return false;
	}
	if ( $wp instanceof WP ) {
		foreach ( array( 'feed', 'robots', 'sitemap', 'sitemap-stylesheet', 'rest_route' ) as $var ) {
			if ( ! empty( $wp->query_vars[ $var ] ) ) {
				return false;
			}
		}
	}
	return true;
}

/**
 * The headers to send for this request: notfortraining_response_headers()
 * on front-end page views, nothing anywhere else.
 *
 * @param WP|null $wp Current request.
 * @return array<string, string> Header name => value.
 */
function notfortraining_headers_for_request( $wp = null ) {
	return notfortraining_is_front_end_request( $wp ) ? notfortraining_response_headers() : array();
}

/**
 * Send the noai and TDM headers on front-end page views. X-Robots-Tag is
 * added alongside any existing one (for example a noindex from an SEO
 * plugin), not in place of it.
 *
 * These only reach responses PHP renders: pages a caching plugin serves
 * straight from the web server, and image files, need the same headers set
 * in the server config.
 *
 * @param WP $wp Current request.
 */
function notfortraining_send_headers( $wp = null ) {
	if ( headers_sent() ) {
		return;
	}
	foreach ( notfortraining_headers_for_request( $wp ) as $name => $value ) {
		header( $name . ': ' . $value, 'X-Robots-Tag' !== $name );
	}
}
