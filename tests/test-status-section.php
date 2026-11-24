<?php
/**
 * The "Is it working?" section on the settings page.
 *
 * @package NotForTraining
 */

/**
 * Tests for includes/admin/status-section.php.
 */
class NotForTraining_Test_Status_Section extends NotForTraining_TestCase {

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
	 * Tear down.
	 */
	public function tear_down() {
		unset( $_REQUEST['_wpnonce'] );
		notfortraining_clear_status();
		parent::tear_down();
	}

	/**
	 * One result of each status, with both actions.
	 *
	 * @return array
	 */
	private function sample_results() {
		return array(
			notfortraining_status_result( 'robots_served', 'problem', 'physical_file', 'Robots problem.', 'copy_rules' ),
			notfortraining_status_result( 'search_open', 'ok', 'ok', 'Search ok.' ),
			notfortraining_status_result( 'noai_tag', 'warn', 'missing', 'Tags missing.' ),
			notfortraining_status_result( 'tdmrep', 'problem', 'unreachable_file', 'Tdmrep problem.', 'download_tdmrep' ),
			notfortraining_status_result( 'page_cache', 'info', 'cache', 'Cache info.' ),
			notfortraining_status_result( 'image_editor', 'unknown', 'unreachable', 'Editor unknown.' ),
		);
	}

	/**
	 * The section HTML for given cached results.
	 *
	 * @param array $results Results to put in the cache.
	 * @return string
	 */
	private function render( array $results ) {
		set_transient( 'notfortraining_status', $results, 600 );
		return get_echo( 'notfortraining_render_status_section' );
	}

	/**
	 * Heading, one line per check, each with an icon hidden from screen
	 * readers and a visible word.
	 */
	public function test_lines_have_icon_and_word() {
		$html = $this->render( $this->sample_results() );

		$this->assertStringContainsString( '<h2>Is it working?</h2>', $html );
		$this->assertSame( 6, substr_count( $html, '<li class="notfortraining-status-item' ) );
		$this->assertSame( 6, substr_count( $html, 'aria-hidden="true"></span>' ) );

		$expected = array(
			'robots_served' => array( 'problem', 'dashicons-dismiss', 'Problem', 'Robots problem.' ),
			'search_open'   => array( 'ok', 'dashicons-yes-alt', 'OK', 'Search ok.' ),
			'noai_tag'      => array( 'warn', 'dashicons-warning', 'Check this', 'Tags missing.' ),
			'page_cache'    => array( 'info', 'dashicons-info', 'FYI', 'Cache info.' ),
			'image_editor'  => array( 'unknown', 'dashicons-editor-help', 'Couldn&#039;t check', 'Editor unknown.' ),
		);
		foreach ( $expected as $id => $want ) {
			list( $status, $icon, $word, $message ) = $want;
			$this->assertMatchesRegularExpression(
				'#<li class="notfortraining-status-item notfortraining-status-' . $status . '" data-check="' . $id . '">\s*<span class="dashicons ' . $icon . '" aria-hidden="true"></span>\s*<div class="notfortraining-status-text">\s*<strong class="notfortraining-status-word">' . preg_quote( $word, '#' ) . '</strong>\s*' . preg_quote( $message, '#' ) . '#',
				$html,
				$id
			);
		}
	}

	/**
	 * Robots problems get the rules in a read-only textarea behind a
	 * "Copy the rules" disclosure: exactly the block the plugin adds.
	 */
	public function test_copy_rules() {
		$html = $this->render( $this->sample_results() );

		$this->assertSame( 1, substr_count( $html, '<details class="notfortraining-copy-rules">' ) );
		$this->assertStringContainsString( '<summary class="button">Copy the rules</summary>', $html );
		$this->assertMatchesRegularExpression( '#<textarea id="notfortraining-rules-robots_served"[^>]*readonly>#', $html );
		$this->assertStringContainsString( '<label class="screen-reader-text" for="notfortraining-rules-robots_served">', $html );

		preg_match( '#<textarea[^>]*>(.*?)</textarea>#s', $html, $match );
		$this->assertSame( notfortraining_robots_block(), html_entity_decode( $match[1], ENT_QUOTES ) );
		$this->assertStringStartsWith( "# Not For Training\n", html_entity_decode( $match[1], ENT_QUOTES ) );
	}

	/**
	 * Tdmrep problems get a nonce'd download link and the upload line.
	 */
	public function test_download_tdmrep_link() {
		$html = $this->render( $this->sample_results() );

		$this->assertMatchesRegularExpression( '#<a class="button" href="[^"]*admin-post\.php\?action=notfortraining_download_tdmrep&(?:amp|\#038);_wpnonce=[0-9a-f]{10}">Download tdmrep\.json</a>#', $html );
		$this->assertStringContainsString( 'Upload it so it opens at <code>' . esc_html( notfortraining_site_root_url() ) . '/.well-known/tdmrep.json</code>', $html );
	}

	/**
	 * No actions on lines that don't ask for one.
	 */
	public function test_no_actions_when_not_needed() {
		$html = $this->render( array( notfortraining_status_result( 'search_open', 'ok', 'ok', 'Fine.' ) ) );
		$this->assertStringNotContainsString( '<details', $html );
		$this->assertStringNotContainsString( 'notfortraining_download_tdmrep', $html );
	}

	/**
	 * "Check again" is a nonce'd post to admin-post.
	 */
	public function test_check_again_form() {
		$html = $this->render( $this->sample_results() );
		$this->assertStringContainsString( '<input type="hidden" name="action" value="notfortraining_check_again">', $html );
		$this->assertMatchesRegularExpression( '#name="_wpnonce" value="[0-9a-f]{10}"#', $html );
		$this->assertMatchesRegularExpression( '#<input type="submit" name="notfortraining-check-again"[^>]*value="Check again"#', $html );
	}

	/**
	 * Throw instead of redirecting, so the handler can be tested.
	 *
	 * @param string $location Location.
	 * @throws NotForTraining_Redirect_Exception Always.
	 */
	public static function throw_redirect( $location ) {
		throw new NotForTraining_Redirect_Exception( $location ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
	}

	/**
	 * Check again clears the cache and returns to the section.
	 */
	public function test_check_again_clears_cache() {
		wp_set_current_user( self::$admin );
		set_transient( 'notfortraining_status', $this->sample_results(), 600 );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'notfortraining_check_again' );

		add_filter( 'wp_redirect', array( __CLASS__, 'throw_redirect' ) );
		try {
			notfortraining_handle_check_again();
			$this->fail( 'Expected a redirect.' );
		} catch ( NotForTraining_Redirect_Exception $redirect ) {
			$location = $redirect->getMessage();
			$this->assertSame( notfortraining_run_checks_url(), $location );
			$this->assertStringNotContainsString( '&amp;', $location, 'Redirect locations must not be HTML-escaped.' );
			wp_parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $query );
			$this->assertSame( '1', $query['notfortraining-run-checks'] );
			$this->assertSame( 1, wp_verify_nonce( $query['_wpnonce'], 'notfortraining_run_checks' ) );
		} finally {
			remove_filter( 'wp_redirect', array( __CLASS__, 'throw_redirect' ) );
		}
		$this->assertFalse( get_transient( 'notfortraining_status' ) );
	}

	/**
	 * Check again and the download need the nonce and manage_options.
	 */
	public function test_handlers_need_nonce_and_capability() {
		set_transient( 'notfortraining_status', $this->sample_results(), 600 );
		$cases = array(
			array( self::$admin, 'bad' ),
			array( self::$editor, 'good' ),
		);
		foreach ( array(
			'notfortraining_check_again'     => 'notfortraining_handle_check_again',
			'notfortraining_download_tdmrep' => 'notfortraining_handle_download_tdmrep',
		) as $nonce => $handler ) {
			foreach ( $cases as $case ) {
				wp_set_current_user( $case[0] );
				$_REQUEST['_wpnonce'] = 'good' === $case[1] ? wp_create_nonce( $nonce ) : 'bad';
				try {
					$handler();
					$this->fail( "$handler should stop." );
				} catch ( WPDieException $e ) {
					$this->assertNotFalse( get_transient( 'notfortraining_status' ), $handler );
				}
			}
		}
	}

	/**
	 * The download is the same JSON the site serves at tdmrep.json.
	 */
	public function test_download_body() {
		wp_set_current_user( self::$admin );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'notfortraining_download_tdmrep' );
		// wp_send_json() ends with wp_die() when doing AJAX; make that throw
		// like the test suite's normal wp_die() handler instead of exiting.
		add_filter( 'wp_doing_ajax', '__return_true' );
		$throw = static function () {
			return static function ( $message ) {
				throw new WPDieException( is_scalar( $message ) ? esc_html( (string) $message ) : '' );
			};
		};
		add_filter( 'wp_die_ajax_handler', $throw );

		ob_start();
		try {
			notfortraining_handle_download_tdmrep();
		} catch ( WPDieException $e ) {
			unset( $e );
		}
		$body = ob_get_clean();
		remove_filter( 'wp_die_ajax_handler', $throw );
		remove_filter( 'wp_doing_ajax', '__return_true' );

		$this->assertSame( '[{"location":"/*","tdm-reservation":1}]', $body );
	}

	/**
	 * The settings page shows the section under the intro and above the
	 * form.
	 */
	public function test_section_on_settings_page() {
		wp_set_current_user( self::$admin );
		notfortraining_register_settings();
		set_transient( 'notfortraining_status', $this->sample_results(), 600 );
		$html = get_echo( 'notfortraining_render_settings_page' );
		unregister_setting( 'notfortraining', 'notfortraining_settings' );

		$intro   = strpos( $html, 'class="notfortraining-intro"' );
		$section = strpos( $html, 'id="notfortraining-status"' );
		$form    = strpos( $html, 'action="' . esc_url( admin_url( 'options.php' ) ) . '"' );
		$this->assertNotFalse( $section );
		$this->assertTrue( $intro < $section && $section < $form );
	}

	/**
	 * Count HTTP requests; leave the answer to the offline guard.
	 *
	 * @param false|array $pre Short-circuit value.
	 * @return false|array
	 */
	public function count_request( $pre ) {
		++$this->http_requests;
		return $pre;
	}

	/**
	 * HTTP requests made since set_up.
	 *
	 * @var int
	 */
	private $http_requests = 0;

	/**
	 * Set up: count requests, empty cache, blog public.
	 */
	public function set_up() {
		parent::set_up();
		$this->http_requests = 0;
		add_filter( 'pre_http_request', array( $this, 'count_request' ), 1 );
		add_filter( 'notfortraining_physical_robots_paths', '__return_empty_array' );
		update_option( 'blog_public', '1' );
		notfortraining_clear_status();
	}

	/**
	 * With nothing cached, the page renders at once: a placeholder in a
	 * polite live region, a no-JS "Run checks" link, no HTTP requests, and
	 * nothing cached.
	 */
	public function test_uncached_render_makes_no_requests() {
		wp_set_current_user( self::$admin );
		notfortraining_register_settings();
		$html = get_echo( 'notfortraining_render_settings_page' );
		unregister_setting( 'notfortraining', 'notfortraining_settings' );

		$this->assertSame( 0, $this->http_requests );
		$this->assertFalse( get_transient( 'notfortraining_status' ) );
		$this->assertStringContainsString( '<div class="notfortraining-status-body" aria-live="polite" data-pending="1">', $html );
		$this->assertMatchesRegularExpression( '#<p class="notfortraining-status-loading hide-if-no-js">.*Checking your site\.\.\.#s', $html );
		$this->assertMatchesRegularExpression( '#<p class="hide-if-js">\s*<a class="button" href="[^"]*notfortraining-run-checks=1[^"]*_wpnonce=[0-9a-f]{10}\#notfortraining-status">Run checks</a>#', $html );
		$this->assertStringNotContainsString( 'notfortraining-status-item', $html );
	}

	/**
	 * Cached results render without requests and without the placeholder.
	 */
	public function test_cached_render() {
		$html = $this->render( $this->sample_results() );
		$this->assertSame( 0, $this->http_requests );
		$this->assertStringContainsString( 'data-pending="0"', $html );
		$this->assertStringNotContainsString( 'Checking your site', $html );
	}

	/**
	 * The no-JS "Run checks" link runs the checks during the page load, but
	 * only with a valid nonce and manage_options.
	 */
	public function test_run_checks_link() {
		wp_set_current_user( self::$admin );

		$_GET['notfortraining-run-checks'] = '1';
		$_GET['_wpnonce']                  = 'bad';
		$html                              = get_echo( 'notfortraining_render_status_section' );
		$this->assertSame( 0, $this->http_requests );
		$this->assertStringContainsString( 'Checking your site...', $html );

		wp_set_current_user( self::$editor );
		$_GET['_wpnonce'] = wp_create_nonce( 'notfortraining_run_checks' );
		get_echo( 'notfortraining_render_status_section' );
		$this->assertSame( 0, $this->http_requests );

		wp_set_current_user( self::$admin );
		$_GET['_wpnonce'] = wp_create_nonce( 'notfortraining_run_checks' );
		$html             = get_echo( 'notfortraining_render_status_section' );
		unset( $_GET['notfortraining-run-checks'], $_GET['_wpnonce'] );

		$this->assertGreaterThan( 0, $this->http_requests );
		$this->assertStringContainsString( 'notfortraining-status-item', $html );
		$this->assertStringContainsString( 'Couldn&#039;t check', $html, 'Loopbacks fail offline: unknown, never problem.' );
		$this->assertStringNotContainsString( 'notfortraining-status-problem', $html );
	}

	/**
	 * Call the AJAX handler and return what it printed.
	 *
	 * @param array $post POST fields.
	 * @return array{0: string, 1: WPDieException|null} Output and the die exception.
	 */
	private function ajax( array $post ) {
		$_POST    = $post;
		$_REQUEST = $post;
		add_filter( 'wp_doing_ajax', '__return_true' );
		$throw = static function () {
			return static function ( $message ) {
				throw new WPDieException( is_scalar( $message ) ? esc_html( (string) $message ) : '' );
			};
		};
		add_filter( 'wp_die_ajax_handler', $throw );

		$died = null;
		ob_start();
		try {
			notfortraining_ajax_status();
		} catch ( WPDieException $e ) {
			$died = $e;
		}
		$output = (string) ob_get_clean();

		remove_filter( 'wp_die_ajax_handler', $throw );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		$_POST    = array();
		$_REQUEST = array();
		return array( $output, $died );
	}

	/**
	 * The endpoint refuses a missing or bad nonce.
	 */
	public function test_ajax_needs_nonce() {
		wp_set_current_user( self::$admin );
		foreach ( array( array(), array( 'nonce' => 'bad' ) ) as $post ) {
			list( $output, $died ) = $this->ajax( $post );
			$this->assertNotNull( $died );
			$this->assertStringNotContainsString( 'success', $output );
		}
		$this->assertSame( 0, $this->http_requests );
	}

	/**
	 * The endpoint refuses users without manage_options.
	 */
	public function test_ajax_needs_capability() {
		wp_set_current_user( self::$editor );
		list( $output ) = $this->ajax( array( 'nonce' => wp_create_nonce( 'notfortraining_status' ) ) );
		$json           = json_decode( $output, true );
		$this->assertFalse( $json['success'] );
		$this->assertSame( 0, $this->http_requests );
	}

	/**
	 * The endpoint returns the results HTML: cached when there is a cache,
	 * fresh with refresh=1.
	 */
	public function test_ajax_returns_section() {
		wp_set_current_user( self::$admin );
		$nonce = wp_create_nonce( 'notfortraining_status' );

		set_transient( 'notfortraining_status', $this->sample_results(), 600 );
		list( $output ) = $this->ajax( array( 'nonce' => $nonce ) );
		$json           = json_decode( $output, true );
		$this->assertTrue( $json['success'] );
		$this->assertSame( notfortraining_status_results_html( $this->sample_results() ), $json['data']['html'] );
		$this->assertSame( 0, $this->http_requests );

		list( $output ) = $this->ajax(
			array(
				'nonce'   => $nonce,
				'refresh' => '1',
			)
		);
		$json           = json_decode( $output, true );
		$this->assertTrue( $json['success'] );
		$this->assertGreaterThan( 0, $this->http_requests );
		$this->assertStringContainsString( 'notfortraining-status-item', $json['data']['html'] );
		$this->assertStringContainsString( 'class="notfortraining-check-again"', $json['data']['html'] );
		$this->assertNotSame( $this->sample_results(), get_transient( 'notfortraining_status' ) );
	}

	/**
	 * The script loads on the settings page only, with the endpoint nonce,
	 * the 8-second slow message and the no-JS fallback URL.
	 */
	public function test_script() {
		wp_set_current_user( self::$admin );
		notfortraining_status_script( 'options-general.php' );
		$this->assertFalse( wp_script_is( 'notfortraining-status', 'enqueued' ) );

		notfortraining_status_script( 'settings_page_not-for-training' );
		$this->assertTrue( wp_script_is( 'notfortraining-status', 'enqueued' ) );
		$inline = implode( '', (array) wp_scripts()->get_data( 'notfortraining-status', 'after' ) );
		$this->assertStringContainsString( '"nonce":"' . wp_create_nonce( 'notfortraining_status' ) . '"', $inline );
		$this->assertStringContainsString( '"slowMs":8000', $inline );
		$this->assertStringContainsString( 'Still checking. Some hosts are slow to answer.', $inline );
		$this->assertStringContainsString( "'notfortraining_status'", $inline );
		wp_dequeue_script( 'notfortraining-status' );
	}
}
