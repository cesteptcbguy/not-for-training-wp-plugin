<?php
/**
 * Settings page, sanitizing, activation defaults and the setup notice.
 *
 * @package NotForTraining
 */

/**
 * Tests for includes/admin/.
 */
class NotForTraining_Test_Admin extends NotForTraining_TestCase {

	/**
	 * Administrator ID.
	 *
	 * @var int
	 */
	private static $admin;

	/**
	 * Second administrator ID.
	 *
	 * @var int
	 */
	private static $admin2;

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
		self::$admin2 = $factory->user->create( array( 'role' => 'administrator' ) );
		self::$editor = $factory->user->create( array( 'role' => 'editor' ) );
	}

	/**
	 * Set up: a fresh activation.
	 */
	public function set_up() {
		parent::set_up();
		delete_option( 'notfortraining_settings' );
		delete_option( 'notfortraining_setup_notice' );
		notfortraining_activate();
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		unset( $_REQUEST['_wpnonce'], $_REQUEST['_wp_http_referer'] );
		set_current_screen( 'front' );
		parent::tear_down();
	}

	/**
	 * The notice HTML on a given screen.
	 *
	 * @param string $screen Screen ID.
	 * @return string
	 */
	private function notice_on( $screen ) {
		set_current_screen( $screen );
		return get_echo( 'notfortraining_setup_notice' );
	}

	/**
	 * Run the dismiss handler as the current user with the given nonce.
	 *
	 * @param string $nonce Nonce.
	 * @return string Redirect location.
	 */
	private function dismiss( $nonce ) {
		$_REQUEST['_wpnonce'] = $nonce;
		$throw                = static function ( $location ) {
			throw new NotForTraining_Redirect_Exception( $location ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		};
		add_filter( 'wp_redirect', $throw );
		try {
			notfortraining_handle_dismiss_notice();
		} catch ( NotForTraining_Redirect_Exception $redirect ) {
			return $redirect->getMessage();
		} finally {
			remove_filter( 'wp_redirect', $throw );
		}
		$this->fail( 'The handler should redirect.' );
	}

	/**
	 * Fresh activation stores nothing and every setting is its default.
	 */
	public function test_defaults_on_fresh_activation() {
		$this->assertFalse( get_option( 'notfortraining_settings' ) );
		foreach ( notfortraining_defaults() as $key => $default ) {
			$this->assertSame( $default, notfortraining_get_option( $key ), $key );
		}
		$this->assertSame( 'training', notfortraining_get_option( 'robots_mode' ) );
		$this->assertSame( 1600, notfortraining_get_option( 'max_image_size' ) );
		$this->assertFalse( notfortraining_get_option( 'delete_originals' ) );
		$this->assertSame( 'pending', get_option( 'notfortraining_setup_notice' ) );
	}

	/**
	 * Valid input passes through.
	 */
	public function test_sanitize_accepts_valid_values() {
		$this->assertSame(
			array(
				'robots_mode'             => 'all',
				'noai_signals'            => false,
				'tdm_reservation'         => true,
				'max_image_size'          => 0,
				'strip_gps'               => false,
				'delete_originals'        => true,
				'save_friction'           => true,
				'save_friction_selectors' => '',
			),
			notfortraining_sanitize_settings(
				array(
					'robots_mode'      => 'all',
					'max_image_size'   => '0',
					'noai_signals'     => '0',
					'tdm_reservation'  => '1',
					'strip_gps'        => '0',
					'delete_originals' => '1',
				)
			)
		);
	}

	/**
	 * Bad values fall back to defaults, one key at a time; unknown keys go.
	 */
	public function test_sanitize_rejects_bad_values() {
		$clean = notfortraining_sanitize_settings(
			array(
				'robots_mode'      => 'everything',
				'max_image_size'   => '999',
				'noai_signals'     => 'yes',
				'tdm_reservation'  => array( 1 ),
				'strip_gps'        => '<script>',
				'delete_originals' => '2',
				'evil'             => 'x',
			)
		);
		$this->assertSame( notfortraining_defaults(), $clean );

		foreach ( array( '1600px', '-1', '1600.5', ' 1200', 1600.0, null ) as $bad ) {
			$this->assertSame( 1600, notfortraining_sanitize_settings( array( 'max_image_size' => $bad ) )['max_image_size'], var_export( $bad, true ) );
		}
		$this->assertSame( 'training', notfortraining_sanitize_settings( array( 'robots_mode' => array( 'all' ) ) )['robots_mode'] );
	}

	/**
	 * Missing keys and non-array input give the defaults.
	 */
	public function test_sanitize_missing_and_non_array() {
		$this->assertSame( notfortraining_defaults(), notfortraining_sanitize_settings( array() ) );
		$this->assertSame( notfortraining_defaults(), notfortraining_sanitize_settings( 'robots_mode=off' ) );
		$this->assertSame( notfortraining_defaults(), notfortraining_sanitize_settings( null ) );
	}

	/**
	 * Saving through the Settings API runs the sanitize callback.
	 */
	public function test_register_setting_sanitizes_on_save() {
		notfortraining_register_settings();
		update_option( 'notfortraining_settings', array( 'robots_mode' => 'nope' ) );
		$this->assertSame( notfortraining_defaults(), get_option( 'notfortraining_settings' ) );
		unregister_setting( 'notfortraining', 'notfortraining_settings' );
	}

	/**
	 * The notice shows for admins on the Plugins screen and the settings
	 * page only, with both buttons.
	 */
	public function test_notice_screens() {
		wp_set_current_user( self::$admin );

		$html = $this->notice_on( 'plugins' );
		$this->assertStringContainsString( 'Not For Training is on. Your site now blocks AI training and keeps AI search. Want to only keep smaller copies of new uploads?', $html );
		$this->assertStringContainsString( 'Review settings', $html );
		$this->assertStringContainsString( esc_url( notfortraining_settings_url() ), $html );
		$this->assertStringContainsString( 'Dismiss', $html );
		$this->assertStringContainsString( 'action=notfortraining_dismiss_notice', $html );

		$this->assertNotSame( '', $this->notice_on( 'settings_page_not-for-training' ) );

		foreach ( array( 'dashboard', 'edit-post', 'upload', 'options-general' ) as $screen ) {
			$this->assertSame( '', $this->notice_on( $screen ), $screen );
		}

		wp_set_current_user( self::$editor );
		$this->assertSame( '', $this->notice_on( 'plugins' ) );
	}

	/**
	 * Dismissing hides it for that user for good, even after reactivation,
	 * and leaves other admins alone.
	 */
	public function test_notice_stays_dismissed() {
		wp_set_current_user( self::$admin );
		$this->assertNotSame( '', $this->notice_on( 'plugins' ) );

		$this->assertSame( admin_url( 'plugins.php' ), $this->dismiss( wp_create_nonce( 'notfortraining_dismiss_notice' ) ) );
		$this->assertSame( '', $this->notice_on( 'plugins' ) );
		$this->assertSame( '', $this->notice_on( 'settings_page_not-for-training' ) );

		notfortraining_deactivate();
		notfortraining_activate();
		$this->assertSame( '', $this->notice_on( 'plugins' ) );

		wp_set_current_user( self::$admin2 );
		$this->assertNotSame( '', $this->notice_on( 'plugins' ) );
	}

	/**
	 * A bad nonce, or a user without manage_options, can't dismiss.
	 */
	public function test_dismiss_needs_nonce_and_capability() {
		wp_set_current_user( self::$admin );
		$_REQUEST['_wpnonce'] = 'bad';
		try {
			notfortraining_handle_dismiss_notice();
			$this->fail( 'Expected wp_die.' );
		} catch ( WPDieException $e ) {
			$this->assertEmpty( get_user_meta( self::$admin, notfortraining_notice_meta_key(), true ) );
		}

		wp_set_current_user( self::$editor );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'notfortraining_dismiss_notice' );
		try {
			notfortraining_handle_dismiss_notice();
			$this->fail( 'Expected wp_die.' );
		} catch ( WPDieException $e ) {
			$this->assertEmpty( get_user_meta( self::$editor, notfortraining_notice_meta_key(), true ) );
		}
	}

	/**
	 * Saving the settings retires the notice for everyone, and activating
	 * again doesn't bring it back.
	 */
	public function test_notice_gone_after_save() {
		notfortraining_register_settings();
		update_option( 'notfortraining_settings', notfortraining_defaults() );
		unregister_setting( 'notfortraining', 'notfortraining_settings' );
		$this->assertSame( 'done', get_option( 'notfortraining_setup_notice' ) );

		notfortraining_activate();
		foreach ( array( self::$admin, self::$admin2 ) as $user ) {
			wp_set_current_user( $user );
			$this->assertSame( '', $this->notice_on( 'plugins' ) );
		}
	}

	/**
	 * Admins get the page with every field, the copy and the tip link.
	 */
	public function test_settings_page_renders_for_admin() {
		wp_set_current_user( self::$admin );
		set_current_screen( 'settings_page_not-for-training' );
		notfortraining_register_settings();

		$html = get_echo( 'notfortraining_render_settings_page' );
		unregister_setting( 'notfortraining', 'notfortraining_settings' );

		$this->assertStringContainsString( 'Not For Training tells AI companies not to train on your work and makes your images harder to take, without hiding you from Google.', $html );
		foreach ( array( 'AI bots', 'Signals', 'Images' ) as $section ) {
			$this->assertMatchesRegularExpression( '#<h2[^>]*>' . preg_quote( $section, '#' ) . '</h2>#', $html );
		}
		$this->assertStringContainsString( "name='option_page' value='notfortraining'", $html );
		$this->assertMatchesRegularExpression( '/value="training"[^>]*checked/', $html );
		$this->assertMatchesRegularExpression( '/value="1600"[^>]*selected/', $html );
		foreach ( array( 'noai_signals', 'tdm_reservation', 'strip_gps', 'delete_originals' ) as $key ) {
			$this->assertStringContainsString( 'name="notfortraining_settings[' . $key . ']" value="0"', $html );
		}
		$this->assertDoesNotMatchRegularExpression( '/id="notfortraining-delete_originals"[^>]*checked/', $html );
		$this->assertStringContainsString( 'href="https://buymeacoffee.com/cesteptcbguy" target="_blank" rel="noopener"', $html );
		$this->assertStringContainsString( 'Google&#039;s AI Overviews use the same crawler', $html );
	}

	/**
	 * Editors can't open the page or get the menu entry.
	 */
	public function test_settings_page_denied_for_editor() {
		wp_set_current_user( self::$editor );
		$this->assertFalse( notfortraining_add_settings_page() );

		$this->expectException( WPDieException::class );
		notfortraining_render_settings_page();
	}

	/**
	 * The Settings link on the Plugins screen, for admins only.
	 */
	public function test_plugin_action_link() {
		wp_set_current_user( self::$admin );
		$links = apply_filters( 'plugin_action_links_' . plugin_basename( NOTFORTRAINING_FILE ), array( 'deactivate' => 'x' ) );
		$this->assertStringContainsString( 'options-general.php?page=not-for-training', $links[0] );
		$this->assertStringContainsString( '>Settings<', $links[0] );

		wp_set_current_user( self::$editor );
		$this->assertSame( array( 'deactivate' => 'x' ), apply_filters( 'plugin_action_links_' . plugin_basename( NOTFORTRAINING_FILE ), array( 'deactivate' => 'x' ) ) );
	}
}
