<?php
/**
 * Status check engine, with every loopback mocked through pre_http_request.
 *
 * @package NotForTraining
 */

/**
 * Tests for includes/status.php.
 */
class NotForTraining_Test_Status extends NotForTraining_TestCase {

	/**
	 * Mocked responses by URL path: array( code, body, headers ) or WP_Error.
	 *
	 * @var array
	 */
	private $responses = array();

	/**
	 * Requests made: array( url, args ).
	 *
	 * @var array
	 */
	private $requests = array();

	/**
	 * Temp directory standing in for the site root.
	 *
	 * @var string
	 */
	private $root;

	/**
	 * Set up: a healthy site by default, nothing on disk at the root.
	 */
	public function set_up() {
		parent::set_up();
		update_option( 'blog_public', '1' );
		notfortraining_clear_status();

		$this->root = trailingslashit( get_temp_dir() ) . 'notfortraining-root-' . wp_rand();
		wp_mkdir_p( $this->root );
		add_filter( 'notfortraining_physical_robots_paths', array( $this, 'physical_paths' ) );
		add_filter( 'pre_http_request', array( $this, 'mock_http' ), 10, 3 );

		$this->responses = array(
			'/robots.txt'              => $this->ok( $this->served_robots() ),
			'/'                        => $this->ok( "<html><head><meta name='robots' content='max-image-preview:large, noai, noimageai' />\n</head></html>" ),
			'/.well-known/tdmrep.json' => $this->ok( '[{"location":"/*","tdm-reservation":1}]' ),
		);
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'mock_http' ), 10 );
		remove_filter( 'notfortraining_physical_robots_paths', array( $this, 'physical_paths' ) );
		if ( is_file( $this->root . '/robots.txt' ) ) {
			unlink( $this->root . '/robots.txt' );
		}
		rmdir( $this->root );
		notfortraining_clear_status();
		parent::tear_down();
	}

	/**
	 * Point the physical robots.txt check at the temp root.
	 *
	 * @return string[]
	 */
	public function physical_paths() {
		return array( $this->root . '/robots.txt' );
	}

	/**
	 * Serve the mocked response for a URL.
	 *
	 * @param false|array $pre  Short-circuit value.
	 * @param array       $args Request args.
	 * @param string      $url  URL.
	 * @return array|WP_Error
	 */
	public function mock_http( $pre, $args, $url ) {
		$this->requests[] = array( $url, $args );
		$path             = (string) wp_parse_url( $url, PHP_URL_PATH );
		$path             = '' === $path ? '/' : $path;
		$response         = $this->responses[ $path ] ?? array( 404, 'Not found', array() );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return array(
			'headers'  => $response[2],
			'body'     => $response[1],
			'response' => array(
				'code'    => $response[0],
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * A 200 response.
	 *
	 * @param string $body    Body.
	 * @param array  $headers Headers.
	 * @return array
	 */
	private function ok( $body, $headers = array() ) {
		return array( 200, $body, $headers );
	}

	/**
	 * A timed-out request.
	 *
	 * @return WP_Error
	 */
	private function timeout() {
		return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 10001 milliseconds' );
	}

	/**
	 * The robots.txt WordPress would serve right now.
	 *
	 * @return string
	 */
	private function served_robots() {
		return apply_filters( 'robots_txt', "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n", '1' );
	}

	/**
	 * Run one check.
	 *
	 * @param string $id Check ID.
	 * @return array|null
	 */
	private function check( $id ) {
		return notfortraining_run_status_check( $id );
	}

	/**
	 * Assert a result's status and reason.
	 *
	 * @param string     $status Expected status.
	 * @param string     $reason Expected reason.
	 * @param array|null $result Result.
	 */
	private function assertResult( $status, $reason, $result ) {
		$this->assertIsArray( $result );
		$this->assertSame( array( $status, $reason ), array( $result['status'], $result['reason'] ), $result['message'] );
	}

	/*
	 * 1. robots_served.
	 */

	/**
	 * Our rules are served, in both blocking modes.
	 */
	public function test_robots_served_ok() {
		$this->assertResult( 'ok', 'ok', $this->check( 'robots_served' ) );
		$this->assertSame( 'AI rules are in your robots.txt.', $this->check( 'robots_served' )['message'] );

		$this->set_settings( array( 'robots_mode' => 'all' ) );
		$this->responses['/robots.txt'] = $this->ok( $this->served_robots() );
		$this->assertResult( 'ok', 'ok', $this->check( 'robots_served' ) );
	}

	/**
	 * A physical file wins, whether the loopback worked or not.
	 */
	public function test_robots_served_physical_file() {
		file_put_contents( $this->root . '/robots.txt', "User-agent: *\nDisallow:\n" );
		$this->responses['/robots.txt'] = $this->ok( "User-agent: *\nDisallow:\n" );

		$result = $this->check( 'robots_served' );
		$this->assertResult( 'problem', 'physical_file', $result );
		$this->assertSame( 'copy_rules', $result['action'] );
		$this->assertStringStartsWith( 'Your site has its own robots.txt file', $result['message'] );

		$this->responses['/robots.txt'] = $this->timeout();
		$this->assertResult( 'problem', 'physical_file', $this->check( 'robots_served' ) );
	}

	/**
	 * A physical file that already holds our rules (pasted in) is fine.
	 */
	public function test_robots_served_physical_file_with_our_rules() {
		file_put_contents( $this->root . '/robots.txt', $this->served_robots() );
		$this->assertResult( 'ok', 'ok', $this->check( 'robots_served' ) );
	}

	/**
	 * Something else answers robots.txt: replaced.
	 */
	public function test_robots_served_replaced() {
		$cases = array(
			'core only'         => $this->ok( "User-agent: *\nDisallow: /wp-admin/\n" ),
			'marker, no groups' => $this->ok( "# Not For Training\nUser-agent: *\nDisallow:\n" ),
			'groups, no marker' => $this->ok( str_replace( '# Not For Training', '# Something else', $this->served_robots() ) ),
			'404'               => array( 404, 'Not found', array() ),
		);
		foreach ( $cases as $label => $response ) {
			$this->responses['/robots.txt'] = $response;
			$result                         = $this->check( 'robots_served' );
			$this->assertResult( 'problem', 'replaced', $result );
			$this->assertSame( 'copy_rules', $result['action'], $label );
		}
	}

	/**
	 * Failed requests are unknown, never a problem.
	 */
	public function test_robots_served_unknown() {
		foreach ( array( $this->timeout(), array( 500, '', array() ), array( 503, '', array() ), array( 401, '', array() ), array( 429, '', array() ) ) as $response ) {
			$this->responses['/robots.txt'] = $response;
			$this->assertResult( 'unknown', 'unreachable', $this->check( 'robots_served' ) );
		}
		$this->assertSame( "Couldn't check this from your site. That's often a host setting, not a problem with the plugin.", $this->check( 'robots_served' )['message'] );
	}

	/**
	 * Skipped when the rules are off or the site discourages search engines.
	 */
	public function test_robots_served_skipped() {
		$this->set_settings( array( 'robots_mode' => 'off' ) );
		$this->assertNull( $this->check( 'robots_served' ) );

		$this->set_settings( array( 'robots_mode' => 'training' ) );
		update_option( 'blog_public', '0' );
		$this->assertNull( $this->check( 'robots_served' ) );
	}

	/**
	 * Run a check with WordPress in a subfolder of its domain.
	 *
	 * @param string $id Check ID.
	 * @return array|null
	 */
	private function check_in_subfolder( $id ) {
		$home = static function () {
			return 'http://example.org/blog';
		};
		add_filter( 'home_url', $home );
		$this->requests = array();
		$result         = $this->check( $id );
		remove_filter( 'home_url', $home );
		return $result;
	}

	/**
	 * Subfolder install: info with the rules to hand over, no request, and
	 * a physical file in the subfolder doesn't change that.
	 */
	public function test_robots_served_subdirectory() {
		file_put_contents( $this->root . '/robots.txt', "User-agent: *\nDisallow:\n" );

		$result = $this->check_in_subfolder( 'robots_served' );
		$this->assertResult( 'info', 'subdirectory', $result );
		$this->assertSame( 'copy_rules', $result['action'] );
		$this->assertSame( 'Your site is in a subfolder, so robots.txt is controlled by the main site at the top of your domain. Ask whoever runs it to add these rules.', $result['message'] );
		$this->assertSame( array(), $this->requests );
	}

	/*
	 * 2. search_open.
	 */

	/**
	 * Normal robots.txt, including our AI groups in "all" mode: open.
	 */
	public function test_search_open_ok() {
		$this->assertResult( 'ok', 'ok', $this->check( 'search_open' ) );
		$this->assertSame( 'Google and other search engines can still find your site.', $this->check( 'search_open' )['message'] );

		$this->set_settings( array( 'robots_mode' => 'all' ) );
		$this->responses['/robots.txt'] = $this->ok( $this->served_robots() );
		$this->assertResult( 'ok', 'ok', $this->check( 'search_open' ) );

		$this->responses['/robots.txt'] = $this->ok( "User-agent: Googlebot\nDisallow: /\nAllow: /\n" );
		$this->assertResult( 'ok', 'ok', $this->check( 'search_open' ) );

		$this->responses['/robots.txt'] = $this->ok( "User-agent: *\nDisallow: /private/\n" );
		$this->assertResult( 'ok', 'ok', $this->check( 'search_open' ) );
	}

	/**
	 * Whole-site blocks for *, Googlebot or Bingbot.
	 */
	public function test_search_open_blocked() {
		$cases = array(
			"User-agent: *\nDisallow: /\n",
			"User-agent: *\nDisallow: /*\n",
			"User-agent: *\nDisallow:\n\nUser-agent: Googlebot\nDisallow: /\n",
			"User-agent: *\nDisallow:\n\nUser-agent: bingbot\nDisallow: /\n",
		);
		foreach ( $cases as $robots ) {
			$this->responses['/robots.txt'] = $this->ok( $robots );
			$result                         = $this->check( 'search_open' );
			$this->assertResult( 'problem', 'blocked', $result );
			$this->assertSame( "Search engines are blocked from your whole site. This plugin didn't do that. Check Settings > Reading and your SEO plugin.", $result['message'] );
		}
	}

	/**
	 * Discourage search engines is a problem even if the loopback fails.
	 */
	public function test_search_open_blog_public_zero() {
		update_option( 'blog_public', '0' );
		$this->responses['/robots.txt'] = $this->timeout();
		$this->assertResult( 'problem', 'blocked', $this->check( 'search_open' ) );
	}

	/**
	 * Subfolder install: blog_public still counts, robots.txt isn't read.
	 */
	public function test_search_open_subdirectory() {
		$this->responses['/robots.txt'] = $this->ok( "User-agent: *\nDisallow: /\n" );

		$this->assertNull( $this->check_in_subfolder( 'search_open' ) );
		$this->assertSame( array(), $this->requests );

		update_option( 'blog_public', '0' );
		$this->assertResult( 'problem', 'blocked', $this->check_in_subfolder( 'search_open' ) );
		$this->assertSame( array(), $this->requests );
	}

	/**
	 * Failed request: unknown.
	 */
	public function test_search_open_unknown() {
		$this->responses['/robots.txt'] = $this->timeout();
		$this->assertResult( 'unknown', 'unreachable', $this->check( 'search_open' ) );
	}

	/*
	 * 3. noai_tag.
	 */

	/**
	 * One robots meta with both values, in any attribute style.
	 */
	public function test_noai_ok() {
		$pages = array(
			"<meta name='robots' content='max-image-preview:large, noai, noimageai' />",
			'<meta name="robots" content="index, follow, noai, noimageai">',
			'<META content="NOAI,NOIMAGEAI" NAME=robots>',
			'<meta name="robots" content="noimageai, noai"><meta name="description" content="noai">',
		);
		foreach ( $pages as $html ) {
			$this->responses['/'] = $this->ok( "<html><head>$html</head></html>" );
			$this->assertResult( 'ok', 'ok', $this->check( 'noai_tag' ) );
		}
		$this->assertSame( 'No-AI tags are on your pages.', $this->check( 'noai_tag' )['message'] );
	}

	/**
	 * No robots meta, or one without our values: missing.
	 */
	public function test_noai_missing() {
		foreach ( array( '<meta name="description" content="x">', '<meta name="robots" content="index, follow">', '<meta name="robots" content="noai">' ) as $html ) {
			$this->responses['/'] = $this->ok( "<html><head>$html</head></html>" );
			$result               = $this->check( 'noai_tag' );
			$this->assertResult( 'warn', 'missing', $result );
			$this->assertSame( "No-AI tags aren't showing on your homepage yet. If you use a caching plugin, clear its cache, then check again.", $result['message'] );
		}
	}

	/**
	 * Two robots metas: duplicate, even if one has our values.
	 */
	public function test_noai_duplicate() {
		$this->responses['/'] = $this->ok( '<meta name="robots" content="noai, noimageai"><meta name="robots" content="index, follow">' );
		$result               = $this->check( 'noai_tag' );
		$this->assertResult( 'warn', 'duplicate', $result );
		$this->assertSame( 'Your homepage has more than one robots tag. Another plugin may be adding its own.', $result['message'] );
	}

	/**
	 * Failed request: unknown. Setting off: skipped.
	 */
	public function test_noai_unknown_and_skipped() {
		foreach ( array( $this->timeout(), array( 500, '', array() ) ) as $response ) {
			$this->responses['/'] = $response;
			$this->assertResult( 'unknown', 'unreachable', $this->check( 'noai_tag' ) );
		}
		$this->set_settings( array( 'noai_signals' => false ) );
		$this->assertNull( $this->check( 'noai_tag' ) );
	}

	/*
	 * 4. tdmrep.
	 */

	/**
	 * Valid JSON matching ours, slashes escaped or not.
	 */
	public function test_tdmrep_ok() {
		$this->assertResult( 'ok', 'ok', $this->check( 'tdmrep' ) );
		$this->responses['/.well-known/tdmrep.json'] = $this->ok( '[{"location":"\/*","tdm-reservation":1}]' );
		$this->assertResult( 'ok', 'ok', $this->check( 'tdmrep' ) );
		$this->assertSame( 'Your rights-reserved notice is published.', $this->check( 'tdmrep' )['message'] );
	}

	/**
	 * 404, HTML, other JSON: problem, with the download action.
	 */
	public function test_tdmrep_problem() {
		$cases = array(
			array( 404, 'Not found', array() ),
			array( 403, 'Forbidden', array() ),
			$this->ok( '<html>Home page</html>' ),
			$this->ok( '[{"location":"/*","tdm-reservation":0}]' ),
		);
		foreach ( $cases as $response ) {
			$this->responses['/.well-known/tdmrep.json'] = $response;
			$result                                      = $this->check( 'tdmrep' );
			$this->assertResult( 'problem', 'unreachable_file', $result );
			$this->assertSame( 'download_tdmrep', $result['action'] );
		}
	}

	/**
	 * Failed request: unknown. Subdirectory: info, no request. Off: skipped.
	 */
	public function test_tdmrep_unknown_subdirectory_skipped() {
		$this->responses['/.well-known/tdmrep.json'] = $this->timeout();
		$this->assertResult( 'unknown', 'unreachable', $this->check( 'tdmrep' ) );

		$home = static function () {
			return 'http://example.org/blog';
		};
		add_filter( 'home_url', $home );
		$this->requests = array();
		$result         = $this->check( 'tdmrep' );
		remove_filter( 'home_url', $home );
		$this->assertResult( 'info', 'subdirectory', $result );
		$this->assertSame( array(), $this->requests );

		$this->set_settings( array( 'tdm_reservation' => false ) );
		$this->assertNull( $this->check( 'tdmrep' ) );
	}

	/*
	 * 5. image_editor.
	 */

	/**
	 * Imagick: ok. GD: warn. No editor: unknown.
	 *
	 * @return void
	 */
	public function test_image_editor() {
		$editors = static function () {
			return array( 'NotForTraining_Fake_Imagick_Editor' );
		};
		add_filter( 'wp_image_editors', $editors );
		$this->assertResult( 'ok', 'imagick', $this->check( 'image_editor' ) );
		remove_filter( 'wp_image_editors', $editors );

		$gd = static function () {
			return array( 'WP_Image_Editor_GD' );
		};
		add_filter( 'wp_image_editors', $gd );
		$result = $this->check( 'image_editor' );
		remove_filter( 'wp_image_editors', $gd );
		$this->assertResult( 'warn', 'gd', $result );
		$this->assertSame( 'Your server makes smaller copies of images in a way that drops copyright info. Your original upload keeps it. Ask your host to turn on Imagick.', $result['message'] );

		add_filter( 'wp_image_editors', '__return_empty_array' );
		$result = $this->check( 'image_editor' );
		remove_filter( 'wp_image_editors', '__return_empty_array' );
		$this->assertResult( 'unknown', 'unreachable', $result );
	}

	/*
	 * 6. page_cache, 8. other_ai_plugins.
	 */

	/**
	 * Pretend these plugins are active.
	 *
	 * @param string[] $plugins Basenames.
	 */
	private function activate( array $plugins ) {
		update_option( 'active_plugins', $plugins );
	}

	/**
	 * A known cache plugin: info. None: nothing.
	 */
	public function test_page_cache() {
		$this->activate( array() );
		$this->assertNull( $this->check( 'page_cache' ) );

		foreach ( array( 'wp-super-cache/wp-cache.php', 'litespeed-cache/litespeed-cache.php', 'wp-rocket/wp-rocket.php', 'cache-enabler/cache-enabler.php', 'w3-total-cache/w3-total-cache.php', 'wp-fastest-cache/wpFastestCache.php' ) as $plugin ) {
			$this->activate( array( 'akismet/akismet.php', $plugin ) );
			$result = $this->check( 'page_cache' );
			$this->assertResult( 'info', 'cache', $result );
			$this->assertSame( 'You use a caching plugin. After changing these settings, clear its cache.', $result['message'] );
		}
	}

	/**
	 * WP_CACHE alone counts. The constant is already defined (false) by
	 * the time tests run, so the helper's filter stands in for it.
	 */
	public function test_page_cache_wp_cache_constant() {
		$this->activate( array() );
		$this->assertFalse( notfortraining_wp_cache_enabled() );

		add_filter( 'notfortraining_wp_cache_enabled', '__return_true' );
		$result = $this->check( 'page_cache' );
		remove_filter( 'notfortraining_wp_cache_enabled', '__return_true' );
		$this->assertResult( 'info', 'cache', $result );
	}

	/**
	 * Another AI-blocking plugin: warn, naming it. None: nothing.
	 */
	public function test_other_ai_plugins() {
		$this->activate( array( 'akismet/akismet.php' ) );
		$this->assertNull( $this->check( 'other_ai_plugins' ) );

		$this->activate( array( 'ai-blackout/ai-blackout.php' ) );
		$result = $this->check( 'other_ai_plugins' );
		$this->assertResult( 'warn', 'other_plugin', $result );
		$this->assertSame( "Another AI-blocking plugin is on: AI Blackout. Use one or the other so the rules don't double up.", $result['message'] );

		$this->activate( array( 'block-ai-crawlers/block-ai-crawlers.php', 'vetobot/vetobot.php', 'not-for-training/not-for-training.php' ) );
		$this->assertStringContainsString( 'Block AI Crawlers, VetoBot.', $this->check( 'other_ai_plugins' )['message'] );

		foreach ( array_keys( notfortraining_known_ai_plugins() ) as $slug ) {
			$this->activate( array( $slug . '/any-file.php' ) );
			$this->assertResult( 'warn', 'other_plugin', $this->check( 'other_ai_plugins' ) );
		}
	}

	/*
	 * 7. cloudflare.
	 */

	/**
	 * A cf-ray header on any loopback response: info. None, or no
	 * responses at all: nothing.
	 */
	public function test_cloudflare() {
		$this->assertNull( $this->find( notfortraining_run_status_checks(), 'cloudflare' ) );

		$this->responses['/robots.txt'] = $this->ok( $this->served_robots(), array( 'cf-ray' => '8f1c2a3b4c5d6e7f-SEA' ) );
		$result                         = $this->find( notfortraining_run_status_checks(), 'cloudflare' );
		$this->assertResult( 'info', 'cloudflare', $result );
		$this->assertSame( "Your site uses Cloudflare. It has its own AI bot settings. If you use them, make sure they don't block Google.", $result['message'] );

		$this->responses['/robots.txt'] = $this->timeout();
		$this->responses['/']           = $this->ok( '<meta name="robots" content="noai, noimageai">', array( 'CF-RAY' => 'x' ) );
		$this->assertResult( 'info', 'cloudflare', $this->find( notfortraining_run_status_checks(), 'cloudflare' ) );

		$this->responses = array(
			'/robots.txt'              => $this->timeout(),
			'/'                        => $this->timeout(),
			'/.well-known/tdmrep.json' => $this->timeout(),
		);
		$this->assertNull( $this->find( notfortraining_run_status_checks(), 'cloudflare' ) );
	}

	/**
	 * The result for an ID in a list.
	 *
	 * @param array  $results Results.
	 * @param string $id      Check ID.
	 * @return array|null
	 */
	private function find( array $results, $id ) {
		foreach ( $results as $result ) {
			if ( $id === $result['id'] ) {
				return $result;
			}
		}
		return null;
	}

	/*
	 * Engine.
	 */

	/**
	 * A healthy site: every check in order, no problems, each URL fetched
	 * once, with the right request args.
	 */
	public function test_full_run() {
		add_filter( 'wp_image_editors', array( $this, 'fake_imagick' ) );
		$this->activate( array() );
		$results = notfortraining_run_status_checks();
		remove_filter( 'wp_image_editors', array( $this, 'fake_imagick' ) );

		$this->assertSame( array( 'robots_served', 'search_open', 'noai_tag', 'tdmrep', 'image_editor' ), wp_list_pluck( $results, 'id' ) );
		$this->assertSame( array( 'ok' ), array_values( array_unique( wp_list_pluck( $results, 'status' ) ) ) );

		$this->assertCount( 3, $this->requests );
		$paths = array();
		foreach ( $this->requests as $request ) {
			list( $url, $args ) = $request;
			$paths[]            = wp_parse_url( $url, PHP_URL_PATH );
			$this->assertStringContainsString( 'notfortraining-check=', $url );
			$this->assertSame( 10, $args['timeout'] );
			$this->assertFalse( $args['sslverify'] );
		}
		$this->assertSame( array( '/robots.txt', '/', '/.well-known/tdmrep.json' ), $paths );
	}

	/**
	 * Editors filter for the fake Imagick.
	 *
	 * @return string[]
	 */
	public function fake_imagick() {
		return array( 'NotForTraining_Fake_Imagick_Editor' );
	}

	/**
	 * Everything times out: unknowns, never a problem.
	 */
	public function test_all_timeouts_are_unknown() {
		$this->responses = array(
			'/robots.txt'              => $this->timeout(),
			'/'                        => $this->timeout(),
			'/.well-known/tdmrep.json' => $this->timeout(),
		);
		$results         = notfortraining_run_status_checks();
		foreach ( array( 'robots_served', 'search_open', 'noai_tag', 'tdmrep' ) as $id ) {
			$this->assertSame( 'unknown', $this->find( $results, $id )['status'], $id );
		}
		$this->assertNotContains( 'problem', wp_list_pluck( $results, 'status' ) );
	}

	/**
	 * Loopbacks follow https_local_ssl_verify.
	 */
	public function test_sslverify_follows_core_filter() {
		add_filter( 'https_local_ssl_verify', '__return_true' );
		$this->check( 'noai_tag' );
		remove_filter( 'https_local_ssl_verify', '__return_true' );
		$this->assertTrue( end( $this->requests )[1]['sslverify'] );
	}

	/**
	 * Results are cached for 10 minutes; refresh and settings changes
	 * clear them.
	 */
	public function test_cache() {
		$first = notfortraining_get_status();
		$count = count( $this->requests );
		$this->assertGreaterThan( 0, $count );

		$this->assertSame( $first, notfortraining_get_status() );
		$this->assertCount( $count, $this->requests, 'Second call should come from the cache.' );

		$timeout = (int) get_option( '_transient_timeout_notfortraining_status' );
		$this->assertEqualsWithDelta( time() + 600, $timeout, 5 );

		notfortraining_get_status( true );
		$this->assertCount( 2 * $count, $this->requests );

		$this->set_settings( array( 'robots_mode' => 'all' ) );
		$this->assertFalse( get_transient( 'notfortraining_status' ) );
	}
}
