<?php
/**
 * Save friction.
 *
 * @package NotForTraining
 */

/**
 * Tests for includes/save-friction.php.
 */
class NotForTraining_Test_Save_Friction extends NotForTraining_TestCase {

	/**
	 * Set up: a front-end request by a visitor, fresh script and style
	 * registries.
	 */
	public function set_up() {
		parent::set_up();
		wp_set_current_user( 0 );
		$this->reset_assets();
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		$this->reset_assets();
		parent::tear_down();
	}

	/**
	 * Start with empty script and style registries.
	 */
	private function reset_assets() {
		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- Fresh registries per test, as core's own tests do.
		$GLOBALS['wp_scripts'] = null;
		$GLOBALS['wp_styles']  = null;
		// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	/**
	 * Run wp_enqueue_scripts and report what save friction added.
	 *
	 * @return array{enqueued: bool, css: string, js: string}
	 */
	private function enqueue() {
		$this->reset_assets();
		do_action( 'wp_enqueue_scripts' );
		return array(
			'enqueued' => wp_style_is( 'notfortraining-save-friction', 'enqueued' ) && wp_script_is( 'notfortraining-save-friction', 'enqueued' ),
			'css'      => implode( '', (array) wp_styles()->get_data( 'notfortraining-save-friction', 'after' ) ),
			'js'       => implode( '', (array) wp_scripts()->get_data( 'notfortraining-save-friction', 'after' ) ),
		);
	}

	/**
	 * Visitors and users who can't upload files get the style and script.
	 */
	public function test_present_for_visitors() {
		$this->go_to( home_url( '/' ) );
		$out = $this->enqueue();
		$this->assertTrue( $out['enqueued'] );
		$this->assertStringContainsString( '-webkit-touch-callout:none', $out['css'] );
		$this->assertStringContainsString( 'user-select:none', $out['css'] );
		$this->assertStringContainsString( 'addEventListener("contextmenu",f)', $out['js'] );
		$this->assertStringContainsString( 'addEventListener("dragstart",f)', $out['js'] );
		$this->assertStringNotContainsString( 'click', $out['js'], 'Clicks must be left alone.' );
		$this->assertStringNotContainsString( 'jQuery', $out['js'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertTrue( $this->enqueue()['enqueued'] );
	}

	/**
	 * Inline only: no file is requested.
	 */
	public function test_no_files() {
		$this->go_to( home_url( '/' ) );
		$this->enqueue();
		$this->assertFalse( wp_styles()->registered['notfortraining-save-friction']->src );
		$this->assertFalse( wp_scripts()->registered['notfortraining-save-friction']->src );
	}

	/**
	 * Anyone who can upload files can still save their images.
	 */
	public function test_absent_for_upload_files_users() {
		$this->go_to( home_url( '/' ) );
		foreach ( array( 'author', 'editor', 'administrator' ) as $role ) {
			wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
			$this->assertFalse( $this->enqueue()['enqueued'], $role );
		}
	}

	/**
	 * Off: nothing.
	 */
	public function test_absent_when_off() {
		$this->set_settings( array( 'save_friction' => false ) );
		$this->go_to( home_url( '/' ) );
		$this->assertFalse( $this->enqueue()['enqueued'] );
	}

	/**
	 * Not in wp-admin (which includes the block editor), feeds or embeds.
	 */
	public function test_absent_in_admin_feeds_and_embeds() {
		set_current_screen( 'post' );
		$this->assertFalse( notfortraining_save_friction_applies(), 'Block editor / admin.' );
		set_current_screen( 'front' );

		$this->go_to( home_url( '/?feed=rss2' ) );
		$this->assertFalse( $this->enqueue()['enqueued'], 'Feed.' );

		$post = self::factory()->post->create();
		$this->go_to( get_post_embed_url( $post ) );
		$this->assertTrue( is_embed() );
		$this->assertFalse( $this->enqueue()['enqueued'], 'Embed.' );

		add_filter( 'wp_doing_ajax', '__return_true' );
		$this->assertFalse( notfortraining_save_friction_applies(), 'admin-ajax.' );
		remove_filter( 'wp_doing_ajax', '__return_true' );
	}

	/**
	 * Not in REST requests. Separate process: the constant can't be undone.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_absent_in_rest() {
		define( 'REST_REQUEST', true );
		wp_set_current_user( 0 );
		$this->assertFalse( notfortraining_save_friction_applies() );
	}

	/**
	 * The default selector list, in CSS and script.
	 */
	public function test_default_selectors() {
		$defaults = '.entry-content img,.wp-block-image img,.wp-block-gallery img,.gallery img,.wp-block-post-featured-image img,.wp-post-image,.wp-lightbox-overlay img,.woocommerce-product-gallery img,.pswp img,.elementor-widget-image img,.elementor-gallery-item img,.elementor-lightbox img';
		$this->assertSame( $defaults, notfortraining_save_friction_selectors()['defaults'] );

		$this->go_to( home_url( '/' ) );
		$out = $this->enqueue();
		$this->assertSame( $defaults . '{-webkit-touch-callout:none;-webkit-user-select:none;user-select:none;-webkit-user-drag:none}', $out['css'] );
		$this->assertStringContainsString( '(document,' . wp_json_encode( $defaults ) . ',"");', $out['js'] );
	}

	/**
	 * Custom selectors get their own CSS rule and are appended in the
	 * script only if the browser accepts them.
	 */
	public function test_custom_selectors_appended() {
		$this->set_settings( array( 'save_friction_selectors' => '.portfolio img, .hero > img' ) );
		$this->assertSame( '.portfolio img, .hero img', notfortraining_get_option( 'save_friction_selectors' ) );

		$this->go_to( home_url( '/' ) );
		$out     = $this->enqueue();
		$declare = '{-webkit-touch-callout:none;-webkit-user-select:none;user-select:none;-webkit-user-drag:none}';
		$this->assertStringEndsWith( '.wp-lightbox-overlay img,.woocommerce-product-gallery img,.pswp img,.elementor-widget-image img,.elementor-gallery-item img,.elementor-lightbox img' . $declare . '.portfolio img, .hero img' . $declare, $out['css'] );
		$this->assertStringContainsString( ',".portfolio img, .hero img");', $out['js'] );
		$this->assertStringContainsString( 'try{d.querySelector(c);s+=","+c}catch(e){}', $out['js'] );
	}

	/**
	 * The size stays tiny.
	 */
	public function test_size() {
		$this->go_to( home_url( '/' ) );
		$out = $this->enqueue();
		$this->assertLessThan( 1024, strlen( $out['css'] ) + strlen( $out['js'] ) );
	}

	/**
	 * Sanitizer: input => expected output.
	 *
	 * @return array
	 */
	public function selector_cases() {
		return array(
			'valid list'                   => array( '.portfolio img, #hero img, figure.big img', '.portfolio img, #hero img, figure.big img' ),
			'attributes, :not, :nth-child' => array( 'img[data-full="1"], .grid :not(.icon) img, li:nth-child(2n+1) img', 'img[data-full="1"], .grid :not(.icon) img, li:nth-child(2n+1) img' ),
			'sibling combinators, :has'    => array( '.a ~ img + img, figure:has(+ .caption) img', '.a ~ img + img, figure:has(+ .caption) img' ),
			'whitespace and stray commas'  => array( "  .a   img ,\n\n.b img, ", '.a img, .b img' ),
			'pasted rule: braces dropped'  => array( '.gallery img { display: none } .x img {}', '.gallery img, .x img' ),
			'child combinator relaxed'     => array( '.hero > img', '.hero img' ),
			'comment removed'              => array( 'img/* note */.big', 'img.big' ),
			'script tag removed'           => array( '<script>alert(1)</script>.ok img', '.ok img' ),
			'tags only'                    => array( '<script>alert(document.cookie)</script>', '' ),
			'onerror attribute garbage'    => array( '<img src=x onerror=alert(1)>', '' ),
			'url() and declarations'       => array( '.x img; background:url(https://example.com/x.png)', '' ),
			'url() alone'                  => array( 'url(javascript:alert(1))', '' ),
			'pseudo-element'               => array( '.a img::before', '' ),
			'unbalanced bracket'           => array( 'img[data-x', '' ),
			'unbalanced paren'             => array( '.a:not(.b img', '' ),
			'starts with a digit'          => array( '1column img', '' ),
			'garbage'                      => array( '$$$ %%% @@@', '' ),
			'ends with a combinator'       => array( '.a +', '' ),
			'unknown function'             => array( '.a:contains(x) img', '' ),
			'one bad selector drops all'   => array( '.ok img, .a::after', '' ),
			'over 500 characters'          => array( implode( ', ', array_fill( 0, 40, '.abcdefghij img' ) ), '' ),
			'just under 500 characters'    => array( implode( ', ', array_fill( 0, 29, '.abcdefghij img' ) ), implode( ', ', array_fill( 0, 29, '.abcdefghij img' ) ) ),
			'not a string'                 => array( array( '.a img' ), '' ),
		);
	}

	/**
	 * Each sanitizer case.
	 *
	 * @dataProvider selector_cases
	 *
	 * @param mixed  $input    Input.
	 * @param string $expected Expected output.
	 */
	public function test_sanitize_selectors( $input, $expected ) {
		$this->assertSame( $expected, notfortraining_sanitize_selectors( $input ) );
		$this->assertLessThanOrEqual( 500, strlen( notfortraining_sanitize_selectors( $input ) ) );
	}

	/**
	 * The settings sanitizer handles both new keys.
	 */
	public function test_settings_sanitizer() {
		$clean = notfortraining_sanitize_settings(
			array(
				'save_friction'           => '0',
				'save_friction_selectors' => '.a img { color: red }',
			)
		);
		$this->assertFalse( $clean['save_friction'] );
		$this->assertSame( '.a img', $clean['save_friction_selectors'] );

		$defaults = notfortraining_sanitize_settings( array() );
		$this->assertTrue( $defaults['save_friction'] );
		$this->assertSame( '', $defaults['save_friction_selectors'] );
	}

	/**
	 * The settings page shows the new section with its copy.
	 */
	public function test_settings_section() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		set_transient( 'notfortraining_status', array(), 600 );
		notfortraining_register_settings();
		$html = get_echo( 'notfortraining_render_settings_page' );
		unregister_setting( 'notfortraining', 'notfortraining_settings' );
		delete_transient( 'notfortraining_status' );

		$this->assertMatchesRegularExpression( '#<h2[^>]*>Saving images</h2>#', $html );
		$this->assertStringContainsString( 'Make images harder to save', $html );
		$this->assertStringContainsString( 'Turns off dragging, right-click saving and the long-press save menu on phones. Anyone determined can still take a screenshot. You can still save your own images while you&#039;re logged in.', $html );
		$this->assertStringContainsString( '<label for="notfortraining-save_friction_selectors">Extra images to cover</label>', $html );
		$this->assertMatchesRegularExpression( '#<textarea id="notfortraining-save_friction_selectors" name="notfortraining_settings\[save_friction_selectors\]"[^>]*maxlength="500"#', $html );
		$this->assertStringContainsString( 'For web people. Leave this blank unless some images on your site aren&#039;t covered. Use CSS selectors, separated by commas.', $html );
		$this->assertMatchesRegularExpression( '/id="notfortraining-save_friction"[^>]*checked/', $html );
		$this->assertLessThan( strpos( $html, 'notfortraining-save_friction_selectors' ), strpos( $html, 'id="notfortraining-save_friction"' ), 'Textarea goes under the checkbox.' );
	}
}
