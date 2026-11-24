<?php
/**
 * The noai robots meta, tdm-reservation meta, response headers, tdmrep.json.
 *
 * @package NotForTraining
 */

/**
 * Tests for the opt-out signals.
 */
class NotForTraining_Test_Signals extends NotForTraining_TestCase {

	/**
	 * Values an SEO plugin would add.
	 *
	 * @param array $robots Directives.
	 * @return array
	 */
	public static function add_seo_values( $robots ) {
		$robots['index']  = true;
		$robots['follow'] = true;
		return $robots;
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		remove_filter( 'wp_robots', array( __CLASS__, 'add_seo_values' ) );
		parent::tear_down();
	}

	/**
	 * One robots meta tag, with other plugins' values and noai, noimageai.
	 */
	public function test_noai_merged_into_one_robots_meta() {
		update_option( 'blog_public', '1' );
		add_filter( 'wp_robots', array( __CLASS__, 'add_seo_values' ) );

		$html = get_echo( 'wp_robots' );

		$this->assertSame( 1, preg_match_all( '/<meta name=[\'"]robots[\'"]/', $html ), $html );
		$this->assertMatchesRegularExpression( "/<meta name='robots' content='([^']*)'/", $html );
		preg_match( "/<meta name='robots' content='([^']*)'/", $html, $match );
		$values = array_map( 'trim', explode( ',', $match[1] ) );

		foreach ( array( 'index', 'follow', 'max-image-preview:large', 'noai', 'noimageai' ) as $value ) {
			$this->assertContains( $value, $values, $html );
		}
	}

	/**
	 * Through wp_head: still one robots meta, plus one tdm-reservation meta.
	 */
	public function test_wp_head_has_one_robots_and_one_tdm_meta() {
		$this->go_to( home_url( '/' ) );
		$html = get_echo( 'wp_head' );

		$this->assertSame( 1, preg_match_all( '/<meta name=[\'"]robots[\'"]/', $html ) );
		$this->assertSame( 1, substr_count( $html, '<meta name="tdm-reservation" content="1">' ) );
	}

	/**
	 * Settings turn each signal off.
	 */
	public function test_signals_can_be_turned_off() {
		$this->set_settings(
			array(
				'noai_signals'    => false,
				'tdm_reservation' => false,
			)
		);

		$this->assertArrayNotHasKey( 'noai', notfortraining_wp_robots( array() ) );
		$this->assertSame( '', get_echo( 'notfortraining_tdm_meta' ) );
		$this->assertSame( array(), notfortraining_response_headers() );
	}

	/**
	 * Header values.
	 */
	public function test_response_headers() {
		$this->assertSame(
			array(
				'X-Robots-Tag'    => 'noai, noimageai',
				'tdm-reservation' => '1',
			),
			notfortraining_response_headers()
		);
		$this->assertSame( 10, has_action( 'send_headers', 'notfortraining_send_headers' ) );
	}

	/**
	 * Headers for a request, after routing it like WordPress does.
	 *
	 * @param string $url URL to route.
	 * @return array
	 */
	private function headers_for( $url ) {
		$this->go_to( $url );
		return notfortraining_headers_for_request( $GLOBALS['wp'] );
	}

	/**
	 * Front-end pages get both headers.
	 */
	public function test_headers_on_front_end_pages() {
		$expected = notfortraining_response_headers();
		$post_id  = self::factory()->post->create();

		$this->assertNotEmpty( $expected );
		$this->assertSame( $expected, $this->headers_for( home_url( '/' ) ) );
		$this->assertSame( $expected, $this->headers_for( get_permalink( $post_id ) ) );
	}

	/**
	 * No headers in wp-admin.
	 */
	public function test_no_headers_in_admin() {
		set_current_screen( 'dashboard' );
		$this->assertSame( array(), notfortraining_headers_for_request() );
		set_current_screen( 'front' );
	}

	/**
	 * No headers on admin-ajax.php.
	 */
	public function test_no_headers_on_ajax() {
		add_filter( 'wp_doing_ajax', '__return_true' );
		$headers = notfortraining_headers_for_request();
		remove_filter( 'wp_doing_ajax', '__return_true' );

		$this->assertSame( array(), $headers );
	}

	/**
	 * No headers on wp-login.php.
	 */
	public function test_no_headers_on_login() {
		$script = isset( $_SERVER['SCRIPT_NAME'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SCRIPT_NAME'] ) ) : null;

		$_SERVER['SCRIPT_NAME'] = '/wp-login.php';
		$headers                = notfortraining_headers_for_request();

		if ( null === $script ) {
			unset( $_SERVER['SCRIPT_NAME'] );
		} else {
			$_SERVER['SCRIPT_NAME'] = $script;
		}

		$this->assertSame( array(), $headers );
	}

	/**
	 * No headers on REST requests routed through ?rest_route=.
	 */
	public function test_no_headers_on_rest_route_query_var() {
		$wp             = new WP();
		$wp->query_vars = array( 'rest_route' => '/wp/v2/posts' );
		$this->assertSame( array(), notfortraining_headers_for_request( $wp ) );
	}

	/**
	 * No headers while REST_REQUEST is defined. Separate process: the
	 * constant can't be undefined afterwards.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_no_headers_on_rest_request() {
		define( 'REST_REQUEST', true );
		$this->assertSame( array(), notfortraining_headers_for_request() );
	}

	/**
	 * Feed URLs, by permalink structure.
	 *
	 * @return array
	 */
	public function feed_urls() {
		return array(
			'plain /?feed=rss2'           => array( '', '/?feed=rss2' ),
			'plain /?feed=comments-rss2'  => array( '', '/?feed=comments-rss2' ),
			'pretty /feed/'               => array( '/%postname%/', '/feed/' ),
			'pretty /comments/feed/'      => array( '/%postname%/', '/comments/feed/' ),
			'pretty /?feed=rss2'          => array( '/%postname%/', '/?feed=rss2' ),
			'pretty /?feed=comments-rss2' => array( '/%postname%/', '/?feed=comments-rss2' ),
		);
	}

	/**
	 * Feeds get both headers, detected from the parsed request.
	 *
	 * @dataProvider feed_urls
	 *
	 * @param string $structure Permalink structure.
	 * @param string $path      Feed path.
	 */
	public function test_headers_on_feeds( $structure, $path ) {
		$this->set_permalink_structure( $structure );
		$headers = $this->headers_for( home_url( $path ) );

		$this->assertNotEmpty( $GLOBALS['wp']->query_vars['feed'] ?? '', 'Request should parse as a feed.' );
		$this->assertSame(
			array(
				'X-Robots-Tag'    => 'noai, noimageai',
				'tdm-reservation' => '1',
			),
			$headers
		);
	}

	/**
	 * No headers on robots.txt or sitemaps: they aren't pages.
	 */
	public function test_no_headers_on_robots_and_sitemaps() {
		$this->assertSame( array(), $this->headers_for( home_url( '/?robots=1' ) ) );

		// go_to() starts from a fresh WP object without the sitemap query
		// vars core registers on the live one, so build the parsed request.
		foreach ( array( 'sitemap', 'sitemap-stylesheet' ) as $var ) {
			$wp             = new WP();
			$wp->query_vars = array( $var => 'index' );
			$this->assertSame( array(), notfortraining_headers_for_request( $wp ), $var );
		}
	}

	/**
	 * Same document as the static tdmrep.json for a root install.
	 */
	public function test_tdmrep_json_root() {
		$this->assertSame( '[{"location":"/*","tdm-reservation":1}]', wp_json_encode( notfortraining_tdmrep_data(), JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * A site in a subdirectory covers only its own path.
	 */
	public function test_tdmrep_json_subdirectory() {
		$home = static function () {
			return 'https://example.org/blog';
		};
		add_filter( 'home_url', $home );
		$data = notfortraining_tdmrep_data();
		remove_filter( 'home_url', $home );

		$this->assertSame( '/blog/*', $data[0]['location'] );
	}

	/**
	 * With pretty permalinks the URL routes to the plugin's query var.
	 */
	public function test_tdmrep_rewrite_rule() {
		$this->set_permalink_structure( '/%postname%/' );
		$this->assertArrayHasKey( notfortraining_tdmrep_regex(), get_option( 'rewrite_rules' ) );

		remove_action( 'parse_request', 'notfortraining_serve_tdmrep' );
		$this->go_to( home_url( '/.well-known/tdmrep.json' ) );
		add_action( 'parse_request', 'notfortraining_serve_tdmrep' );

		$this->assertSame( '1', get_query_var( 'notfortraining_tdmrep' ) );
	}

	/**
	 * Turned off, the URL is a 404 rather than the home page.
	 */
	public function test_tdmrep_disabled_is_404() {
		$this->set_settings( array( 'tdm_reservation' => false ) );
		$this->set_permalink_structure( '/%postname%/' );
		$this->go_to( home_url( '/.well-known/tdmrep.json' ) );

		$this->assertTrue( is_404() );
	}

	/**
	 * Deactivation takes the rule out of the stored rules.
	 */
	public function test_deactivation_removes_rule() {
		$this->set_permalink_structure( '/%postname%/' );
		notfortraining_deactivate();
		$this->assertArrayNotHasKey( notfortraining_tdmrep_regex(), get_option( 'rewrite_rules' ) );
		$this->assertFalse( get_option( 'notfortraining_rewrite_version' ) );

		notfortraining_activate();
		$this->assertArrayHasKey( notfortraining_tdmrep_regex(), get_option( 'rewrite_rules' ) );
		$this->assertSame( NOTFORTRAINING_REWRITE_VERSION, get_option( 'notfortraining_rewrite_version' ) );
	}
}
