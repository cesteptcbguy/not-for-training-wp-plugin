<?php
/**
 * Site Health tests.
 *
 * @package NotForTraining
 */

/**
 * Tests for includes/site-health.php.
 */
class NotForTraining_Test_Site_Health extends NotForTraining_TestCase {

	/**
	 * Administrator ID.
	 *
	 * @var int
	 */
	private static $admin;

	/**
	 * Editor ID.
	 *
	 * @var int
	 */
	private static $editor;

	/**
	 * Create users once.
	 *
	 * @param WP_UnitTest_Factory $factory Factory.
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		self::$admin  = $factory->user->create( array( 'role' => 'administrator' ) );
		self::$editor = $factory->user->create( array( 'role' => 'editor' ) );
	}

	/**
	 * Set up: a public site, REST routes registered, nothing cached.
	 */
	public function set_up() {
		parent::set_up();
		update_option( 'blog_public', '1' );
		notfortraining_clear_status();
		do_action( 'rest_api_init', rest_get_server() );
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		notfortraining_clear_status();
		parent::tear_down();
	}

	/**
	 * Cache a set of results, one per check.
	 *
	 * @param array<string, string> $statuses Check ID => status.
	 */
	private function cache( array $statuses ) {
		$messages = array(
			'robots_served' => array(
				'ok'      => 'AI rules are in your robots.txt.',
				'problem' => "Your site has its own robots.txt file, so the plugin can't add the AI rules. Remove that file, or ask your host or web person to paste these rules into it.",
				'info'    => 'Your site is in a subfolder, so robots.txt is controlled by the main site at the top of your domain. Ask whoever runs it to add these rules.',
			),
			'search_open'   => array(
				'ok'      => 'Google and other search engines can still find your site.',
				'problem' => "Search engines are blocked from your whole site. This plugin didn't do that. Check Settings > Reading and your SEO plugin.",
			),
			'noai_tag'      => array(
				'ok'   => 'No-AI tags are on your pages.',
				'warn' => "No-AI tags aren't showing on your homepage yet. If you use a caching plugin, clear its cache, then check again.",
			),
		);
		$results  = array();
		foreach ( $statuses as $id => $status ) {
			$results[] = 'unknown' === $status
				? notfortraining_status_unknown( $id )
				: notfortraining_status_result( $id, $status, $status, $messages[ $id ][ $status ] );
		}
		set_transient( 'notfortraining_status', $results, 600 );
	}

	/**
	 * The three tests are registered as async REST tests with a direct
	 * fallback.
	 */
	public function test_registration() {
		$tests = apply_filters( 'site_status_tests', array( 'async' => array() ) );
		foreach ( array( 'robots_served', 'search_open', 'noai_tag' ) as $id ) {
			$test = $tests['async'][ 'notfortraining_' . $id ];
			$this->assertTrue( $test['has_rest'] );
			$this->assertSame( rest_url( 'not-for-training/v1/site-health/' . $id ), $test['test'] );
			$this->assertIsCallable( $test['async_direct_test'] );
			$this->assertNotEmpty( $test['label'] );
		}
		$this->assertArrayNotHasKey( 'notfortraining_tdmrep', $tests['async'] );
	}

	/**
	 * Tests whose setting is off aren't listed.
	 */
	public function test_registration_follows_settings() {
		$this->set_settings(
			array(
				'robots_mode'  => 'off',
				'noai_signals' => false,
			)
		);
		$tests = apply_filters( 'site_status_tests', array( 'async' => array() ) );
		$this->assertSame( array( 'notfortraining_search_open' ), array_keys( $tests['async'] ) );

		update_option( 'blog_public', '0' );
		$this->set_settings( array() );
		$tests = apply_filters( 'site_status_tests', array( 'async' => array() ) );
		$this->assertArrayNotHasKey( 'notfortraining_robots_served', $tests['async'] );
		$this->assertArrayHasKey( 'notfortraining_search_open', $tests['async'] );
	}

	/**
	 * Same copy, the plugin's badge, and the severity mapping: problem,
	 * warn and unknown are "recommended"; ok and info are "good"; never
	 * "critical".
	 */
	public function test_results() {
		$this->cache(
			array(
				'robots_served' => 'problem',
				'search_open'   => 'ok',
				'noai_tag'      => 'warn',
			)
		);

		$robots = notfortraining_site_health_result( 'robots_served' );
		$this->assertSame( 'recommended', $robots['status'] );
		$this->assertSame( "Your site has its own robots.txt file, so the plugin can't add the AI rules. Remove that file, or ask your host or web person to paste these rules into it.", $robots['label'] );
		$this->assertSame(
			array(
				'label' => 'Not For Training',
				'color' => 'blue',
			),
			$robots['badge']
		);
		$this->assertSame( 'notfortraining_robots_served', $robots['test'] );
		$this->assertStringContainsString( 'options-general.php?page=not-for-training#notfortraining-status', $robots['actions'] );

		$search = notfortraining_site_health_result( 'search_open' );
		$this->assertSame( 'good', $search['status'] );
		$this->assertSame( 'Google and other search engines can still find your site.', $search['label'] );
		$this->assertSame( '', $search['actions'] );

		$this->assertSame( 'recommended', notfortraining_site_health_result( 'noai_tag' )['status'] );

		$this->cache(
			array(
				'robots_served' => 'info',
				'search_open'   => 'problem',
				'noai_tag'      => 'unknown',
			)
		);
		$this->assertSame( 'good', notfortraining_site_health_result( 'robots_served' )['status'] );
		$this->assertSame( 'recommended', notfortraining_site_health_result( 'search_open' )['status'] );
		$unknown = notfortraining_site_health_result( 'noai_tag' );
		$this->assertSame( 'recommended', $unknown['status'] );
		$this->assertSame( "Couldn't check this from your site. That's often a host setting, not a problem with the plugin.", $unknown['label'] );
		$this->assertSame(
			'<p>Couldn&#039;t check this from your site. That&#039;s often a host setting, not a problem with the plugin.</p><p>WordPress&#039;s own loopback test, further down this page, can show why.</p>',
			$unknown['description']
		);
		$this->assertStringContainsString( '#notfortraining-status', $unknown['actions'] );

		// The loopback pointer is only for "couldn't check".
		$this->assertStringNotContainsString( 'loopback', notfortraining_site_health_result( 'search_open' )['description'] );
		$this->assertStringNotContainsString( 'loopback', notfortraining_site_health_result( 'robots_served' )['description'] );
	}

	/**
	 * The full mapping, status by status.
	 */
	public function test_severity_mapping() {
		$expected = array(
			'ok'      => 'good',
			'info'    => 'good',
			'warn'    => 'recommended',
			'problem' => 'recommended',
			'unknown' => 'recommended',
		);
		foreach ( $expected as $status => $severity ) {
			set_transient( 'notfortraining_status', array( notfortraining_status_result( 'search_open', $status, $status, 'x' ) ), 600 );
			$this->assertSame( $severity, notfortraining_site_health_result( 'search_open' )['status'], $status );
		}
	}

	/**
	 * Never critical, whatever the engine says.
	 */
	public function test_never_critical() {
		foreach ( array( 'ok', 'warn', 'problem', 'info', 'unknown' ) as $status ) {
			set_transient( 'notfortraining_status', array( notfortraining_status_result( 'search_open', $status, $status, 'x' ) ), 600 );
			$this->assertNotSame( 'critical', notfortraining_site_health_result( 'search_open' )['status'], $status );
		}
	}

	/**
	 * Reuses the engine: cached results mean no requests; uncached runs
	 * the checks once for all three tests.
	 */
	public function test_reuses_engine() {
		$count = 0;
		$spy   = static function ( $pre ) use ( &$count ) {
			++$count;
			return $pre;
		};
		add_filter( 'pre_http_request', $spy, 1 );

		$this->cache(
			array(
				'robots_served' => 'ok',
				'search_open'   => 'ok',
				'noai_tag'      => 'ok',
			)
		);
		notfortraining_site_health_result( 'robots_served' );
		$this->assertSame( 0, $count );

		notfortraining_clear_status();
		add_filter( 'notfortraining_physical_robots_paths', '__return_empty_array' );
		foreach ( array( 'robots_served', 'search_open', 'noai_tag' ) as $id ) {
			notfortraining_site_health_result( $id );
		}
		remove_filter( 'notfortraining_physical_robots_paths', '__return_empty_array' );
		remove_filter( 'pre_http_request', $spy, 1 );

		$this->assertSame( 3, $count, 'One run of the engine: robots.txt, homepage, tdmrep.json.' );
	}

	/**
	 * The async_direct_test callback gives the same result as the route.
	 */
	public function test_direct_test_matches_route() {
		wp_set_current_user( self::$admin );
		$this->cache(
			array(
				'robots_served' => 'problem',
				'search_open'   => 'ok',
				'noai_tag'      => 'warn',
			)
		);
		$tests = apply_filters( 'site_status_tests', array( 'async' => array() ) );
		foreach ( array( 'robots_served', 'search_open', 'noai_tag' ) as $id ) {
			$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/not-for-training/v1/site-health/' . $id ) );
			$this->assertSame( 200, $response->get_status() );
			$this->assertSame( call_user_func( $tests['async'][ 'notfortraining_' . $id ]['async_direct_test'] ), $response->get_data() );
		}
	}

	/**
	 * The route needs view_site_health_checks; unknown checks don't route.
	 */
	public function test_route_permissions() {
		$this->cache( array( 'search_open' => 'ok' ) );
		$request = new WP_REST_Request( 'GET', '/not-for-training/v1/site-health/search_open' );

		wp_set_current_user( 0 );
		$this->assertSame( 401, rest_get_server()->dispatch( $request )->get_status() );

		wp_set_current_user( self::$editor );
		$this->assertSame( 403, rest_get_server()->dispatch( $request )->get_status() );

		wp_set_current_user( self::$admin );
		$this->assertSame( 200, rest_get_server()->dispatch( $request )->get_status() );
		$this->assertSame( 404, rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/not-for-training/v1/site-health/tdmrep' ) )->get_status() );
	}
}
