<?php
/**
 * Page cache purge on settings save.
 *
 * @package NotForTraining
 */

/**
 * Tests for includes/cache-purge.php.
 */
class NotForTraining_Test_Cache_Purge extends NotForTraining_TestCase {

	/**
	 * Purge calls seen by the Cache Enabler spy.
	 *
	 * @var int
	 */
	public static $calls = 0;

	/**
	 * Whether our status cache still existed when the purge ran.
	 *
	 * @var bool|null
	 */
	public static $status_cached_at_purge = null;

	/**
	 * Set up: a public site, no notices yet, spy counters reset.
	 */
	public function set_up() {
		parent::set_up();
		self::$calls                   = 0;
		self::$status_cached_at_purge  = null;
		$GLOBALS['wp_settings_errors'] = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Reset core's notice list between tests.
		delete_option( 'notfortraining_settings' );
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		unset( $_POST['option_page'] );
		remove_all_actions( 'cache_enabler_clear_complete_cache' );
		remove_all_actions( 'litespeed_purge_all' );
		set_current_screen( 'front' );
		parent::tear_down();
	}

	/**
	 * Spy for Cache Enabler's purge action.
	 */
	public static function spy() {
		++self::$calls;
		self::$status_cached_at_purge = false !== get_transient( 'notfortraining_status' );
	}

	/**
	 * Save settings the way options.php does for our settings page.
	 *
	 * @param array $settings Settings to change, over the defaults.
	 */
	private function save( array $settings ) {
		set_current_screen( 'options' );
		$_POST['option_page'] = 'notfortraining';
		update_option( 'notfortraining_settings', array_merge( notfortraining_defaults(), $settings ) );
		unset( $_POST['option_page'] );
	}

	/**
	 * Our notice, if one was added.
	 *
	 * @return string|null
	 */
	private function notice() {
		foreach ( get_settings_errors() as $error ) {
			if ( 'settings_updated' === $error['code'] ) {
				return $error['message'];
			}
		}
		return null;
	}

	/**
	 * Changing an output setting purges, once per save, and says so.
	 */
	public function test_output_change_purges() {
		add_action( 'cache_enabler_clear_complete_cache', array( __CLASS__, 'spy' ) );
		$this->save( array() );
		self::$calls                   = 0;
		$GLOBALS['wp_settings_errors'] = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Reset core's notice list.

		foreach ( array(
			array( 'noai_signals' => false ),
			array( 'tdm_reservation' => false ),
			array( 'save_friction' => false ),
			array( 'save_friction_selectors' => '.portfolio img' ),
		) as $i => $change ) {
			$this->save( $change );
			$this->assertSame( $i + 1, self::$calls, wp_json_encode( $change ) );
			$this->save( array() ); // Back to defaults: that's a change too.
			$this->assertSame( $i + 2, self::$calls );
			self::$calls = $i + 1;
		}
		$this->assertSame( 'Settings saved.<br>Cleared Cache Enabler&#039;s page cache so visitors see the change.', $this->notice() );
	}

	/**
	 * Mode and image settings never purge.
	 */
	public function test_mode_and_image_changes_do_not_purge() {
		add_action( 'cache_enabler_clear_complete_cache', array( __CLASS__, 'spy' ) );
		$this->save( array() );
		self::$calls = 0;

		foreach ( array(
			array( 'robots_mode' => 'all' ),
			array( 'max_image_size' => 1200 ),
			array( 'strip_gps' => false ),
			array( 'delete_originals' => true ),
		) as $change ) {
			$this->save( $change );
		}
		$this->assertSame( 0, self::$calls );
		$this->assertNull( $this->notice() );
	}

	/**
	 * The first save (add_option) purges only if an output setting differs
	 * from its default.
	 */
	public function test_first_save() {
		add_action( 'cache_enabler_clear_complete_cache', array( __CLASS__, 'spy' ) );
		$this->save( array( 'robots_mode' => 'all' ) );
		$this->assertSame( 0, self::$calls );

		delete_option( 'notfortraining_settings' );
		$this->save( array( 'noai_signals' => false ) );
		$this->assertSame( 1, self::$calls );
	}

	/**
	 * No cache plugin: no purge and no extra notice, so core's own
	 * "Settings saved." shows as usual.
	 */
	public function test_no_cache_plugin() {
		$this->save( array( 'noai_signals' => false ) );
		$this->assertNull( $this->notice() );
		$this->assertSame( array(), notfortraining_maybe_purge( notfortraining_defaults(), array( 'noai_signals' => true ) ) );
	}

	/**
	 * Saves from anywhere but the settings page, and activation,
	 * deactivation and "Check again", never purge.
	 */
	public function test_never_purges_elsewhere() {
		add_action( 'cache_enabler_clear_complete_cache', array( __CLASS__, 'spy' ) );

		update_option( 'notfortraining_settings', array_merge( notfortraining_defaults(), array( 'noai_signals' => false ) ) );
		notfortraining_activate();
		notfortraining_deactivate();
		notfortraining_activate();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'notfortraining_check_again' );
		$throw                = static function ( $location ) {
			throw new NotForTraining_Redirect_Exception( $location ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		};
		add_filter( 'wp_redirect', $throw );
		try {
			notfortraining_handle_check_again();
		} catch ( NotForTraining_Redirect_Exception $e ) {
			unset( $e );
		}
		remove_filter( 'wp_redirect', $throw );
		unset( $_REQUEST['_wpnonce'] );

		$this->assertSame( 0, self::$calls );
	}

	/**
	 * Purge first, then our status cache is cleared.
	 */
	public function test_purge_before_status_cleared() {
		add_action( 'cache_enabler_clear_complete_cache', array( __CLASS__, 'spy' ) );
		$this->save( array() );
		set_transient( 'notfortraining_status', array( 'cached' ), 600 );

		$this->save( array( 'noai_signals' => false ) );
		$this->assertTrue( self::$status_cached_at_purge, 'The purge ran before the status cache was cleared.' );
		$this->assertFalse( get_transient( 'notfortraining_status' ) );
		$this->assertSame( 5, has_action( 'update_option_notfortraining_settings', 'notfortraining_purge_on_update' ) );
		$this->assertSame( 10, has_action( 'update_option_notfortraining_settings', 'notfortraining_clear_status' ) );
	}

	/**
	 * LiteSpeed's purge action alone isn't enough: on servers where it
	 * can't cache, there's nothing to clear.
	 */
	public function test_litespeed_needs_its_server() {
		$this->assertFalse( defined( 'LITESPEED_ALLOWED' ) );
		add_action( 'litespeed_purge_all', array( __CLASS__, 'spy' ) );
		$this->save( array( 'noai_signals' => false ) );
		$this->assertSame( 0, self::$calls );
		$this->assertNull( $this->notice() );
	}

	/**
	 * Run a save in a child process with stub purges and report what ran.
	 *
	 * @param string $name Expected cache name.
	 */
	private function assert_function_purge( $name ) {
		$this->save( array( 'noai_signals' => false ) );
		$this->assertSame( 1, $GLOBALS['notfortraining_stub_calls'] ?? 0, $name );
		$this->assertSame( 'Settings saved.<br>' . esc_html( "Cleared $name's page cache so visitors see the change." ), $this->notice() );
	}

	/**
	 * WP Super Cache.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_wp_super_cache() {
		require __DIR__ . '/stubs/wp-super-cache.php';
		$this->assert_function_purge( 'WP Super Cache' );
	}

	/**
	 * W3 Total Cache.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_w3_total_cache() {
		require __DIR__ . '/stubs/w3-total-cache.php';
		$this->assert_function_purge( 'W3 Total Cache' );
	}

	/**
	 * WP Fastest Cache.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_wp_fastest_cache() {
		require __DIR__ . '/stubs/wp-fastest-cache.php';
		$this->assert_function_purge( 'WP Fastest Cache' );
	}

	/**
	 * WP Rocket.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_wp_rocket() {
		require __DIR__ . '/stubs/wp-rocket.php';
		$this->assert_function_purge( 'WP Rocket' );
	}

	/**
	 * LiteSpeed Cache on a server where it caches.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_litespeed_on_its_server() {
		define( 'LITESPEED_ALLOWED', true );
		add_action( 'litespeed_purge_all', array( __CLASS__, 'spy' ) );
		$this->save( array( 'noai_signals' => false ) );
		$this->assertSame( 1, self::$calls );
		$this->assertSame( 'Settings saved.<br>Cleared LiteSpeed Cache&#039;s page cache so visitors see the change.', $this->notice() );
	}

	/**
	 * Two caches: both purged, names joined with a comma.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_two_caches() {
		require __DIR__ . '/stubs/wp-super-cache.php';
		add_action( 'cache_enabler_clear_complete_cache', array( __CLASS__, 'spy' ) );
		$this->save( array( 'noai_signals' => false ) );
		$this->assertSame( 1, $GLOBALS['notfortraining_stub_calls'] );
		$this->assertSame( 1, self::$calls );
		$this->assertSame( 'Settings saved.<br>Cleared the page caches from WP Super Cache and Cache Enabler so visitors see the change.', $this->notice() );
	}

	/**
	 * Three caches: "A, B and C", no Oxford comma.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_three_caches() {
		require __DIR__ . '/stubs/wp-super-cache.php';
		require __DIR__ . '/stubs/wp-fastest-cache.php';
		add_action( 'cache_enabler_clear_complete_cache', array( __CLASS__, 'spy' ) );
		$this->save( array( 'noai_signals' => false ) );
		$this->assertSame( 2, $GLOBALS['notfortraining_stub_calls'] );
		$this->assertSame( 1, self::$calls );
		$this->assertSame( 'Settings saved.<br>Cleared the page caches from WP Super Cache, WP Fastest Cache and Cache Enabler so visitors see the change.', $this->notice() );
	}

	/**
	 * The message for one, two, three and four names.
	 */
	public function test_purge_message() {
		$this->assertSame( "Cleared WP Super Cache's page cache so visitors see the change.", notfortraining_purge_message( array( 'WP Super Cache' ) ) );
		$this->assertSame( 'Cleared the page caches from A and B so visitors see the change.', notfortraining_purge_message( array( 'A', 'B' ) ) );
		$this->assertSame( 'Cleared the page caches from A, B and C so visitors see the change.', notfortraining_purge_message( array( 'A', 'B', 'C' ) ) );
		$this->assertSame( 'Cleared the page caches from A, B, C and D so visitors see the change.', notfortraining_purge_message( array( 'A', 'B', 'C', 'D' ) ) );
	}

	/**
	 * A purge records its time; a save without one doesn't.
	 */
	public function test_purge_time_recorded() {
		$this->save( array( 'noai_signals' => false ) );
		$this->assertFalse( get_transient( 'notfortraining_cache_purged' ), 'No cache plugin, no purge.' );

		add_action( 'cache_enabler_clear_complete_cache', array( __CLASS__, 'spy' ) );
		$this->save( array( 'noai_signals' => true ) );
		$this->assertEqualsWithDelta( time(), (int) get_transient( 'notfortraining_cache_purged' ), 2 );
		$this->assertTrue( notfortraining_cache_recently_purged() );
	}
}
