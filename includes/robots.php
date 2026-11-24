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
 * already in place. The block starts with the marker line
 * "# Not For Training". Only appends: the "User-agent: *" group is never edited,
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

	$block = notfortraining_robots_block();
	if ( '' === $block ) {
		return $output;
	}

	return rtrim( (string) $output ) . "\n\n" . $block;
}

/**
 * The marker line that starts the plugin's robots.txt block, so the status
 * check (and anyone reading the file) can tell the rules are ours.
 *
 * @return string
 */
function notfortraining_robots_marker() {
	return '# Not For Training';
}

/**
 * The block of rules the plugin adds to robots.txt for a mode: the marker
 * line, the AI training group, then the AI search group. Empty in "off"
 * mode or when both lists are empty.
 *
 * @param string|null $mode "training", "all" or "off"; null for the setting.
 * @return string
 */
function notfortraining_robots_block( $mode = null ) {
	$mode = null === $mode ? notfortraining_get_option( 'robots_mode' ) : $mode;
	if ( 'off' === $mode ) {
		return '';
	}

	$bots   = notfortraining_get_bots();
	$groups = array();

	if ( $bots['training'] ) {
		$groups[] = "# AI training and dataset crawlers are not permitted.\n"
			. notfortraining_robots_user_agents( $bots['training'] )
			. "Disallow: /\n";
	}

	if ( $bots['search'] ) {
		if ( 'all' === $mode ) {
			$groups[] = "# AI search and user-requested fetches are not permitted.\n"
				. notfortraining_robots_user_agents( $bots['search'] )
				. "Disallow: /\n";
		} else {
			$groups[] = "# AI search and user-requested fetches are permitted, same rules as search engines.\n"
				. notfortraining_robots_user_agents( $bots['search'] )
				. implode( "\n", notfortraining_robots_search_rules() ) . "\n";
		}
	}

	if ( ! $groups ) {
		return '';
	}

	return notfortraining_robots_marker() . "\n" . implode( "\n", $groups );
}

/**
 * Parse robots.txt into groups, the way RFC 9309 reads them: consecutive
 * User-agent lines open a group, and the rules after them belong to it.
 * Comments, blank lines and other directives (Sitemap, Crawl-delay) are
 * skipped.
 *
 * @param string $text robots.txt.
 * @return array<int, array{agents: string[], rules: array<int, array{0: string, 1: string}>}>
 *         Agents lowercased; rules as array( "allow"|"disallow", path ).
 */
function notfortraining_parse_robots_txt( $text ) {
	$groups  = array();
	$current = -1;
	$in_ua   = false;
	foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
		$line = trim( preg_replace( '/#.*$/', '', $line ) );
		if ( '' === $line || false === strpos( $line, ':' ) ) {
			continue;
		}
		list( $field, $value ) = array_map( 'trim', explode( ':', $line, 2 ) );
		$field                 = strtolower( $field );

		if ( 'user-agent' === $field ) {
			if ( ! $in_ua ) {
				$groups[] = array(
					'agents' => array(),
					'rules'  => array(),
				);
				$current  = count( $groups ) - 1;
				$in_ua    = true;
			}
			$groups[ $current ]['agents'][] = strtolower( $value );
			continue;
		}

		$in_ua = false;
		if ( $current >= 0 && ( 'allow' === $field || 'disallow' === $field ) ) {
			$groups[ $current ]['rules'][] = array( $field, $value );
		}
	}
	return $groups;
}

/**
 * The group a crawler follows: the one naming it, else the "*" group.
 *
 * @param array  $groups Parsed groups.
 * @param string $agent  User agent token.
 * @return array|null
 */
function notfortraining_robots_group_for( array $groups, $agent ) {
	$agent    = strtolower( $agent );
	$fallback = null;
	foreach ( $groups as $group ) {
		if ( in_array( $agent, $group['agents'], true ) ) {
			return $group;
		}
		if ( null === $fallback && in_array( '*', $group['agents'], true ) ) {
			$fallback = $group;
		}
	}
	return $fallback;
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
 * Whether the current request is a front-end page or feed: not wp-admin,
 * admin-ajax, cron, wp-login.php, REST or XML-RPC, and not robots.txt or a
 * sitemap. Feeds count: they carry full post content and image URLs, and
 * a TDM reservation applies to any resource.
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
		if ( ! empty( $wp->query_vars['feed'] ) ) {
			return true;
		}
		foreach ( array( 'robots', 'sitemap', 'sitemap-stylesheet', 'rest_route' ) as $var ) {
			if ( ! empty( $wp->query_vars[ $var ] ) ) {
				return false;
			}
		}
	}
	return true;
}

/**
 * The headers to send for this request: notfortraining_response_headers()
 * on front-end pages and feeds, nothing anywhere else.
 *
 * @param WP|null $wp Current request.
 * @return array<string, string> Header name => value.
 */
function notfortraining_headers_for_request( $wp = null ) {
	return notfortraining_is_front_end_request( $wp ) ? notfortraining_response_headers() : array();
}

/**
 * Send the noai and TDM headers on front-end pages and feeds. X-Robots-Tag is
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
