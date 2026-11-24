<?php
/**
 * Status checks: is the plugin's output actually reaching visitors and
 * crawlers? Each check returns a result array with these keys:
 *
 * - id:      the check, e.g. "robots_served".
 * - status:  "ok", "warn", "problem", "info" or "unknown".
 * - reason:  a short machine-readable outcome, e.g. "physical_file".
 * - message: text for people.
 * - action:  optional, "copy_rules" or "download_tdmrep".
 *
 * Checks that need to see the site from outside make loopback requests to
 * it. A loopback that fails, times out, or gets an answer that says nothing
 * about the plugin (401, 429, 5xx) gives "unknown", never "problem": that's
 * usually a host setting, not a fault in the plugin or the site.
 *
 * Results are cached for 10 minutes (60 seconds if any check couldn't run),
 * and cleared when the settings change.
 *
 * @package NotForTraining
 */

defined( 'ABSPATH' ) || exit;

add_action( 'update_option_notfortraining_settings', 'notfortraining_clear_status' );
add_action( 'add_option_notfortraining_settings', 'notfortraining_clear_status' );

/**
 * Transient that holds the last results.
 *
 * @return string
 */
function notfortraining_status_transient() {
	return 'notfortraining_status';
}

/**
 * All check results, from the cache when it's fresh.
 *
 * @param bool $refresh Ignore the cache and run the checks again.
 * @return array<int, array{id: string, status: string, reason: string, message: string, action?: string}>
 */
function notfortraining_get_status( $refresh = false ) {
	if ( ! $refresh ) {
		$cached = notfortraining_get_cached_status();
		if ( null !== $cached ) {
			return $cached;
		}
	}
	$results = notfortraining_run_status_checks();
	set_transient( notfortraining_status_transient(), $results, notfortraining_status_cache_lifetime( $results ) );
	return $results;
}

/**
 * Cached results, without running anything.
 *
 * @return array|null Results, or null if none are cached.
 */
function notfortraining_get_cached_status() {
	$cached = get_transient( notfortraining_status_transient() );
	return is_array( $cached ) ? $cached : null;
}

/**
 * How long to keep a set of results: 10 minutes, or 60 seconds if any
 * check couldn't run or a page cache was just cleared, so a passing
 * hiccup doesn't stick around.
 *
 * @param array $results Results.
 * @return int Seconds.
 */
function notfortraining_status_cache_lifetime( array $results ) {
	foreach ( $results as $result ) {
		if ( 'unknown' === ( $result['status'] ?? '' ) || 'missing_after_purge' === ( $result['reason'] ?? '' ) ) {
			return MINUTE_IN_SECONDS;
		}
	}
	return 10 * MINUTE_IN_SECONDS;
}

/**
 * Forget the cached results.
 */
function notfortraining_clear_status() {
	delete_transient( notfortraining_status_transient() );
}

/**
 * Run every check. Checks whose setting is off, or that have nothing to
 * report, return nothing.
 *
 * @return array
 */
function notfortraining_run_status_checks() {
	$context = notfortraining_status_context();

	$results = array();
	foreach ( notfortraining_status_check_ids() as $id ) {
		$result = notfortraining_run_status_check( $id, $context );
		if ( $result ) {
			$results[] = $result;
		}
	}
	return $results;
}

/**
 * Check IDs, in display order.
 *
 * @return string[]
 */
function notfortraining_status_check_ids() {
	return array( 'robots_served', 'search_open', 'noai_tag', 'tdmrep', 'image_editor', 'page_cache', 'cloudflare', 'other_ai_plugins' );
}

/**
 * Run one check.
 *
 * @param string     $id      Check ID.
 * @param array|null $context Shared loopback responses; fetched lazily if null.
 * @return array|null Result, or null if skipped or nothing to report.
 */
function notfortraining_run_status_check( $id, $context = null ) {
	$context = null === $context ? notfortraining_status_context() : $context;
	switch ( $id ) {
		case 'robots_served':
			return notfortraining_check_robots_served( $context );
		case 'search_open':
			return notfortraining_check_search_open( $context );
		case 'noai_tag':
			return notfortraining_check_noai_tag( $context );
		case 'tdmrep':
			return notfortraining_check_tdmrep( $context );
		case 'image_editor':
			return notfortraining_check_image_editor();
		case 'page_cache':
			return notfortraining_check_page_cache();
		case 'cloudflare':
			return notfortraining_check_cloudflare( $context );
		case 'other_ai_plugins':
			return notfortraining_check_other_ai_plugins();
	}
	return null;
}

/**
 * Loopback responses, fetched at most once each per run and only when a
 * check needs them.
 *
 * @return array{fetch: callable}
 */
function notfortraining_status_context() {
	$cache = array();
	return array(
		'fetch' => static function ( $key ) use ( &$cache ) {
			if ( ! array_key_exists( $key, $cache ) ) {
				// The homepage is fetched exactly as visitors get it, so a page
				// cache serving an old copy is caught, not bypassed.
				$cache[ $key ] = notfortraining_status_fetch( notfortraining_status_url( $key ), 'home' !== $key );
			}
			return $cache[ $key ];
		},
		'seen'  => static function () use ( &$cache ) {
			return $cache;
		},
	);
}

/**
 * URL for a loopback target.
 *
 * @param string $key "robots", "home" or "tdmrep".
 * @return string
 */
function notfortraining_status_url( $key ) {
	if ( 'robots' === $key ) {
		// Crawlers only ever read robots.txt at the root of the host.
		return notfortraining_site_root_url() . '/robots.txt';
	}
	if ( 'tdmrep' === $key ) {
		return home_url( '/.well-known/tdmrep.json' );
	}
	return home_url( '/' );
}

/**
 * Scheme, host and port of the home URL, with no path.
 *
 * @return string
 */
function notfortraining_site_root_url() {
	$parts = wp_parse_url( home_url() );
	$root  = ( $parts['scheme'] ?? 'http' ) . '://' . ( $parts['host'] ?? '' );
	if ( ! empty( $parts['port'] ) ) {
		$root .= ':' . $parts['port'];
	}
	return $root;
}

/**
 * Whether WordPress is served from a subfolder of its host.
 *
 * @return bool
 */
function notfortraining_is_subdirectory_install() {
	return '' !== trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
}

/**
 * One loopback GET.
 *
 * With $bypass_cache, a random query arg and a no-cache header ask every
 * cache in between for a fresh answer (robots.txt, tdmrep.json: is the
 * site producing the right thing?). Without it, the URL and headers are
 * what a visitor's browser sends, so a page cache answers as it would for
 * them (the homepage: are visitors getting the tags?). That includes a
 * browser's Accept header: some page caches (Cache Enabler) only serve
 * cached pages to requests that accept text/html, and cURL's default
 * Accept header (any type) would get a fresh page instead.
 *
 * @param string $url          URL.
 * @param bool   $bypass_cache Ask caches for a fresh copy.
 * @return array{code: int, body: string, headers: array}|null Null when the
 *         request failed, timed out, or got an answer that says nothing
 *         about the plugin (401, 407, 408, 429, 5xx).
 */
function notfortraining_status_fetch( $url, $bypass_cache = true ) {
	$args = array(
		// Five seconds: the WP HTTP API runs requests one after another,
		// so three loopbacks can take up to 15 seconds in all.
		'timeout'     => 5,
		'redirection' => 3,
		/** This filter is documented in wp-includes/class-wp-http-streams.php */
		'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's filter for loopback requests, applied as Site Health does.
	);
	if ( $bypass_cache ) {
		$url             = add_query_arg( 'notfortraining-check', (string) wp_rand(), $url );
		$args['headers'] = array( 'Cache-Control' => 'no-cache' );
	} else {
		$args['headers'] = array( 'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8' );
	}
	$response = wp_remote_get( $url, $args );
	if ( is_wp_error( $response ) ) {
		return null;
	}
	$code = (int) wp_remote_retrieve_response_code( $response );
	if ( $code < 200 || $code >= 500 || in_array( $code, array( 401, 407, 408, 429 ), true ) ) {
		return null;
	}

	$headers = wp_remote_retrieve_headers( $response );
	return array(
		'code'    => $code,
		'body'    => (string) wp_remote_retrieve_body( $response ),
		'headers' => is_object( $headers ) && method_exists( $headers, 'getAll' ) ? $headers->getAll() : (array) $headers,
	);
}

/**
 * Build a result.
 *
 * @param string $id      Check ID.
 * @param string $status  ok, warn, problem, info or unknown.
 * @param string $reason  Outcome code.
 * @param string $message Text.
 * @param string $action  Optional action.
 * @return array
 */
function notfortraining_status_result( $id, $status, $reason, $message, $action = '' ) {
	$result = array(
		'id'      => $id,
		'status'  => $status,
		'reason'  => $reason,
		'message' => $message,
	);
	if ( '' !== $action ) {
		$result['action'] = $action;
	}
	return $result;
}

/**
 * The "couldn't check" result.
 *
 * @param string $id Check ID.
 * @return array
 */
function notfortraining_status_unknown( $id ) {
	return notfortraining_status_result( $id, 'unknown', 'unreachable', __( "Couldn't check this from your site. That's often a host setting, not a problem with the plugin.", 'not-for-training' ) );
}

/**
 * Paths where a physical robots.txt would shadow WordPress's.
 *
 * @return string[]
 */
function notfortraining_physical_robots_paths() {
	if ( ! function_exists( 'get_home_path' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	$paths = array_unique( array( trailingslashit( get_home_path() ) . 'robots.txt', trailingslashit( ABSPATH ) . 'robots.txt' ) );

	/**
	 * Filters where the status check looks for a physical robots.txt.
	 *
	 * @param string[] $paths Absolute paths.
	 */
	return (array) apply_filters( 'notfortraining_physical_robots_paths', $paths );
}

/**
 * Whether a physical robots.txt exists at the site root.
 *
 * @return bool
 */
function notfortraining_has_physical_robots() {
	return null !== notfortraining_physical_robots_path();
}

/**
 * The physical robots.txt at the site root, if there is one.
 *
 * @return string|null Absolute path.
 */
function notfortraining_physical_robots_path() {
	foreach ( notfortraining_physical_robots_paths() as $path ) {
		if ( is_file( $path ) ) {
			return $path;
		}
	}
	return null;
}

/**
 * Normalize robots.txt text for comparison: LF line endings, no trailing
 * whitespace on any line, runs of blank lines collapsed to one (they mean
 * nothing to crawlers), no trailing blank lines.
 *
 * @param string $text Text.
 * @return string
 */
function notfortraining_normalize_robots_text( $text ) {
	$lines = array_map( 'rtrim', preg_split( '/\r\n|\r|\n/', (string) $text ) );
	$text  = preg_replace( '/\n{3,}/', "\n\n", implode( "\n", $lines ) );
	return rtrim( (string) $text );
}

/**
 * Cut the plugin's block out of robots.txt text: from the marker line
 * through as many blank-line-separated chunks as the current block has
 * (the marker and its first group, then each further group).
 *
 * @param string $text robots.txt text.
 * @return string|null The block, normalized; null if there's no marker line.
 */
function notfortraining_extract_robots_block( $text ) {
	$lines = explode( "\n", notfortraining_normalize_robots_text( $text ) );
	$start = array_search( notfortraining_robots_marker(), $lines, true );
	if ( false === $start ) {
		return null;
	}

	$expected = notfortraining_normalize_robots_text( notfortraining_robots_block() );
	$chunks   = max( 1, count( preg_split( '/\n\s*\n/', $expected ) ) );

	// Blank lines are single after normalizing, so each one ends a chunk.
	$block = array();
	$seen  = 1;
	$count = count( $lines );
	for ( $i = $start; $i < $count; $i++ ) {
		if ( '' === $lines[ $i ] ) {
			if ( $seen >= $chunks ) {
				break;
			}
			++$seen;
		}
		$block[] = $lines[ $i ];
	}
	return implode( "\n", $block );
}

/**
 * Whether served robots.txt text carries the plugin's block for the
 * current mode: the marker line, the training group with Disallow: /, and
 * the search group with its expected last rule.
 *
 * @param string $text robots.txt.
 * @return bool
 */
function notfortraining_robots_has_our_rules( $text ) {
	if ( ! preg_match( '/^' . preg_quote( notfortraining_robots_marker(), '/' ) . '\s*$/m', $text ) ) {
		return false;
	}
	$groups = notfortraining_parse_robots_txt( $text );
	$bots   = notfortraining_get_bots();
	$mode   = notfortraining_get_option( 'robots_mode' );

	$expect = array();
	if ( $bots['training'] ) {
		$expect[] = array( $bots['training'], array( 'disallow', '/' ) );
	}
	if ( $bots['search'] ) {
		$expect[] = array( $bots['search'], 'all' === $mode ? array( 'disallow', '/' ) : array( 'allow', '/' ) );
	}

	foreach ( $expect as $want ) {
		list( $agents, $last_rule ) = $want;
		$agents                     = array_map( 'strtolower', $agents );
		$found                      = false;
		foreach ( $groups as $group ) {
			if ( ! array_diff( $agents, $group['agents'] ) && $group['rules'] && end( $group['rules'] ) === $last_rule ) {
				$found = true;
				break;
			}
		}
		if ( ! $found ) {
			return false;
		}
	}
	return true;
}

/**
 * 1. robots_served: the plugin's rules reach /robots.txt.
 *
 * Skipped when the AI bot rules are off, and when the site discourages
 * search engines (the plugin deliberately adds nothing then; search_open
 * reports that). On a subfolder install, robots.txt is read only at the
 * domain root, which this WordPress doesn't control: info, no request.
 *
 * @param array $context Loopback context.
 * @return array|null
 */
function notfortraining_check_robots_served( array $context ) {
	$id = 'robots_served';
	if ( 'off' === notfortraining_get_option( 'robots_mode' ) || '0' === (string) get_option( 'blog_public' ) ) {
		return null;
	}
	if ( notfortraining_is_subdirectory_install() ) {
		return notfortraining_status_result( $id, 'info', 'subdirectory', __( 'Your site is in a subfolder, so robots.txt is controlled by the main site at the top of your domain. Ask whoever runs it to add these rules.', 'not-for-training' ), 'copy_rules' );
	}

	$physical = notfortraining_physical_robots_path();
	if ( null !== $physical ) {
		return notfortraining_check_physical_robots( $id, (string) notfortraining_filesystem()->get_contents( $physical ) );
	}

	$response = $context['fetch']( 'robots' );
	if ( $response && 200 === $response['code'] && notfortraining_robots_has_our_rules( $response['body'] ) ) {
		return notfortraining_status_result( $id, 'ok', 'ok', __( 'AI rules are in your robots.txt.', 'not-for-training' ) );
	}
	if ( ! $response ) {
		return notfortraining_status_unknown( $id );
	}
	return notfortraining_status_result( $id, 'problem', 'replaced', __( "Your robots.txt doesn't include the AI rules. Another plugin, an SEO plugin's robots editor, or your host may be replacing it. Turn that off, or paste these rules there.", 'not-for-training' ), 'copy_rules' );
}

/**
 * The result for a physical robots.txt, from its contents. The web server
 * sends this file instead of WordPress's robots.txt, so the plugin can't
 * change it:
 *
 * - No marker line: the AI rules aren't there (problem).
 * - The marker and exactly the block the plugin would add now: the rules
 *   are there but frozen, and won't follow plugin updates (info). Someone
 *   pasted them in, or an SEO plugin saved WordPress's robots.txt as a
 *   file (Yoast's "Create robots.txt file" does).
 * - The marker with a different block: an old copy (warn).
 *
 * @param string $id       Check ID.
 * @param string $contents File contents.
 * @return array
 */
function notfortraining_check_physical_robots( $id, $contents ) {
	$found = notfortraining_extract_robots_block( $contents );
	if ( null === $found ) {
		return notfortraining_status_result( $id, 'problem', 'physical_file', __( "Your site has its own robots.txt file, so the plugin can't add the AI rules. Remove that file, or ask your host or web person to paste these rules into it.", 'not-for-training' ), 'copy_rules' );
	}
	if ( notfortraining_normalize_robots_text( notfortraining_robots_block() ) === $found ) {
		return notfortraining_status_result( $id, 'info', 'frozen_current', __( "Your AI rules are saved in a robots.txt file, so they won't update on their own. If this plugin updates its bot list, this line will tell you.", 'not-for-training' ) );
	}
	return notfortraining_status_result( $id, 'warn', 'frozen_stale', __( 'Your robots.txt file has an old copy of the AI rules. Replace that part of the file with these.', 'not-for-training' ), 'copy_rules' );
}

/**
 * Whether a robots.txt group blocks the whole site: "Disallow: /" (or
 * "/*") with no "Allow: /" to cancel it.
 *
 * @param array|null $group Parsed group.
 * @return bool
 */
function notfortraining_robots_group_blocks_all( $group ) {
	if ( ! $group ) {
		return false;
	}
	$blocked = false;
	foreach ( $group['rules'] as $rule ) {
		if ( 'allow' === $rule[0] && in_array( $rule[1], array( '/', '/*' ), true ) ) {
			return false;
		}
		if ( 'disallow' === $rule[0] && in_array( $rule[1], array( '/', '/*' ), true ) ) {
			$blocked = true;
		}
	}
	return $blocked;
}

/**
 * 2. search_open: search engines can crawl the site. Always runs: it's
 * about the site, and never blames the plugin. On a subfolder install only
 * blog_public is checked: the robots.txt at the domain root belongs to the
 * main site, so there's nothing to report when blog_public is 1.
 *
 * @param array $context Loopback context.
 * @return array|null
 */
function notfortraining_check_search_open( array $context ) {
	$id      = 'search_open';
	$blocked = notfortraining_status_result( $id, 'problem', 'blocked', __( "Search engines are blocked from your whole site. This plugin didn't do that. Check Settings > Reading and your SEO plugin.", 'not-for-training' ) );

	if ( '0' === (string) get_option( 'blog_public' ) ) {
		return $blocked;
	}
	if ( notfortraining_is_subdirectory_install() ) {
		return null;
	}

	$response = $context['fetch']( 'robots' );
	if ( ! $response ) {
		return notfortraining_status_unknown( $id );
	}
	if ( 200 === $response['code'] ) {
		$groups = notfortraining_parse_robots_txt( $response['body'] );
		foreach ( array( '*', 'googlebot', 'bingbot' ) as $agent ) {
			if ( notfortraining_robots_group_blocks_all( notfortraining_robots_group_for( $groups, $agent ) ) ) {
				return $blocked;
			}
		}
	}
	return notfortraining_status_result( $id, 'ok', 'ok', __( 'Google and other search engines can still find your site.', 'not-for-training' ) );
}

/**
 * The content of every robots meta tag in an HTML page.
 *
 * @param string $html HTML.
 * @return string[]
 */
function notfortraining_robots_meta_contents( $html ) {
	$contents = array();
	if ( ! preg_match_all( '/<meta\b[^>]*>/i', $html, $tags ) ) {
		return $contents;
	}
	foreach ( $tags[0] as $tag ) {
		if ( ! preg_match( '/\bname\s*=\s*(["\']?)robots\1(?=[\s\/>])/i', $tag ) ) {
			continue;
		}
		$content    = preg_match( '/\bcontent\s*=\s*(["\'])(.*?)\1/is', $tag, $found ) ? $found[2] : '';
		$contents[] = strtolower( html_entity_decode( $content, ENT_QUOTES ) );
	}
	return $contents;
}

/**
 * 3. noai_tag: the homepage has one robots meta tag carrying noai and
 * noimageai.
 *
 * @param array $context Loopback context.
 * @return array|null
 */
function notfortraining_check_noai_tag( array $context ) {
	$id = 'noai_tag';
	if ( ! notfortraining_get_option( 'noai_signals' ) ) {
		return null;
	}
	$response = $context['fetch']( 'home' );
	if ( ! $response || 200 !== $response['code'] ) {
		return notfortraining_status_unknown( $id );
	}

	$tags = notfortraining_robots_meta_contents( $response['body'] );
	if ( count( $tags ) > 1 ) {
		return notfortraining_status_result( $id, 'warn', 'duplicate', __( 'Your homepage has more than one robots tag. Another plugin may be adding its own.', 'not-for-training' ) );
	}
	$values = $tags ? array_map( 'trim', explode( ',', $tags[0] ) ) : array();
	if ( in_array( 'noai', $values, true ) && in_array( 'noimageai', $values, true ) ) {
		return notfortraining_status_result( $id, 'ok', 'ok', __( 'No-AI tags are on your pages.', 'not-for-training' ) );
	}
	if ( notfortraining_cache_recently_purged() ) {
		return notfortraining_status_result( $id, 'warn', 'missing_after_purge', __( 'Your page cache was just cleared. Some caching plugins take up to a minute to catch up. Check again in a minute.', 'not-for-training' ) );
	}
	return notfortraining_status_result( $id, 'warn', 'missing', __( "No-AI tags aren't showing on your homepage yet. If you use a caching plugin, clear its cache, then check again.", 'not-for-training' ) );
}

/**
 * 4. tdmrep: /.well-known/tdmrep.json answers with the plugin's document.
 *
 * @param array $context Loopback context.
 * @return array|null
 */
function notfortraining_check_tdmrep( array $context ) {
	$id = 'tdmrep';
	if ( ! notfortraining_get_option( 'tdm_reservation' ) ) {
		return null;
	}
	if ( notfortraining_is_subdirectory_install() ) {
		return notfortraining_status_result( $id, 'info', 'subdirectory', __( "Your site is in a subfolder, so the rights-reserved file can't sit where the standard expects. The notice in your pages still works.", 'not-for-training' ) );
	}

	$response = $context['fetch']( 'tdmrep' );
	if ( ! $response ) {
		return notfortraining_status_unknown( $id );
	}
	if ( 200 === $response['code'] && json_decode( $response['body'], true ) === notfortraining_tdmrep_data() ) {
		return notfortraining_status_result( $id, 'ok', 'ok', __( 'Your rights-reserved notice is published.', 'not-for-training' ) );
	}
	return notfortraining_status_result( $id, 'problem', 'unreachable_file', __( "Your rights-reserved file isn't reachable. Your host may handle that folder itself. Download the file and upload it to the .well-known folder at the top of your site.", 'not-for-training' ), 'download_tdmrep' );
}

/**
 * 5. image_editor: WordPress makes sizes with Imagick (keeps EXIF, IPTC,
 * XMP) rather than GD (drops them).
 *
 * @return array|null
 */
function notfortraining_check_image_editor() {
	$id     = 'image_editor';
	$editor = notfortraining_image_editor_class();
	if ( null === $editor ) {
		return notfortraining_status_unknown( $id );
	}
	if ( is_a( $editor, 'WP_Image_Editor_Imagick', true ) ) {
		return notfortraining_status_result( $id, 'ok', 'imagick', __( 'Your server keeps copyright info in smaller copies of images.', 'not-for-training' ) );
	}
	if ( is_a( $editor, 'WP_Image_Editor_GD', true ) ) {
		return notfortraining_status_result( $id, 'warn', 'gd', __( 'Your server makes smaller copies of images in a way that drops copyright info. Your original upload keeps it. Ask your host to turn on Imagick.', 'not-for-training' ) );
	}
	return null; // Some other editor: nothing to say about it.
}

/**
 * The class WordPress picks to edit JPEGs, found the same way an upload
 * does: wp_get_image_editor() on a real 1x1 JPEG shipped with the plugin
 * (only read, never changed).
 *
 * @return string|null Class name, or null if no editor could load it.
 */
function notfortraining_image_editor_class() {
	$editor = wp_get_image_editor( NOTFORTRAINING_DIR . 'assets/editor-check.jpg' );
	return is_wp_error( $editor ) ? null : get_class( $editor );
}

/**
 * Known page cache plugins, by folder name.
 *
 * @return array<string, string> Folder => plugin name.
 */
function notfortraining_known_cache_plugins() {
	return array(
		'wp-super-cache'   => 'WP Super Cache',
		'w3-total-cache'   => 'W3 Total Cache',
		'wp-rocket'        => 'WP Rocket',
		'litespeed-cache'  => 'LiteSpeed Cache',
		'wp-fastest-cache' => 'WP Fastest Cache',
		'cache-enabler'    => 'Cache Enabler',
	);
}

/**
 * Other AI-blocking plugins on WordPress.org, by folder name (their slug).
 *
 * @return array<string, string> Folder => plugin name.
 */
function notfortraining_known_ai_plugins() {
	return array(
		'block-ai-crawlers'         => 'Block AI Crawlers',
		'ai-blackout'               => 'AI Blackout',
		'simple-noai-and-noimageai' => 'Simple NoAI and NoImageAI',
		'ai-scrape-protect'         => 'AI Scrape Protect',
		'windcodex-scraperblock'    => 'WindCodex ScraperBlock',
		'vetobot'                   => 'VetoBot',
	);
}

/**
 * Names of the active plugins (site and network) from a folder list.
 *
 * @param array<string, string> $known Folder => name.
 * @return string[]
 */
function notfortraining_active_plugins_named( array $known ) {
	$active = (array) get_option( 'active_plugins', array() );
	if ( is_multisite() ) {
		$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
	}
	$names = array();
	foreach ( $active as $basename ) {
		$folder = strtok( (string) $basename, '/' );
		if ( isset( $known[ $folder ] ) ) {
			$names[ $folder ] = $known[ $folder ];
		}
	}
	return array_values( $names );
}

/**
 * Whether WP_CACHE is on (set in wp-config.php by most page caches).
 *
 * @return bool
 */
function notfortraining_wp_cache_enabled() {
	/**
	 * Filters whether the status check treats WP_CACHE as on.
	 *
	 * @param bool $enabled WP_CACHE is defined and true.
	 */
	return (bool) apply_filters( 'notfortraining_wp_cache_enabled', defined( 'WP_CACHE' ) && WP_CACHE );
}

/**
 * 6. page_cache: a page cache is in use (WP_CACHE, or a known plugin).
 *
 * @return array|null
 */
function notfortraining_check_page_cache() {
	if ( ! notfortraining_wp_cache_enabled() && ! notfortraining_active_plugins_named( notfortraining_known_cache_plugins() ) ) {
		return null;
	}
	return notfortraining_status_result( 'page_cache', 'info', 'cache', __( 'You use a caching plugin. After changing these settings, clear its cache.', 'not-for-training' ) );
}

/**
 * 7. cloudflare: a loopback response came through Cloudflare (cf-ray).
 *
 * @param array $context Loopback context.
 * @return array|null
 */
function notfortraining_check_cloudflare( array $context ) {
	$responses = $context['seen']();
	if ( ! $responses ) {
		$responses = array( 'home' => $context['fetch']( 'home' ) );
	}
	foreach ( $responses as $response ) {
		if ( $response && array_key_exists( 'cf-ray', array_change_key_case( (array) $response['headers'] ) ) ) {
			return notfortraining_status_result( 'cloudflare', 'info', 'cloudflare', __( "Your site uses Cloudflare. It has its own AI bot settings. If you use them, make sure they don't block Google.", 'not-for-training' ) );
		}
	}
	return null;
}

/**
 * 8. other_ai_plugins: another AI-blocking plugin is active.
 *
 * @return array|null
 */
function notfortraining_check_other_ai_plugins() {
	$names = notfortraining_active_plugins_named( notfortraining_known_ai_plugins() );
	if ( ! $names ) {
		return null;
	}
	return notfortraining_status_result(
		'other_ai_plugins',
		'warn',
		'other_plugin',
		sprintf(
			/* translators: %s: plugin name, or a comma-separated list of names. */
			__( 'Another AI-blocking plugin is on: %s. Use one or the other so the rules don\'t double up.', 'not-for-training' ),
			implode( ', ', $names )
		)
	);
}
