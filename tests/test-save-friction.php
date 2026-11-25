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
	 * The CSS declarations every covered rule gets.
	 *
	 * @return string
	 */
	private function declarations() {
		return '{-webkit-touch-callout:none;-webkit-user-select:none;user-select:none;-webkit-user-drag:none}';
	}

	/**
	 * The exclusion list, as printed.
	 *
	 * @return string
	 */
	private function exclusions() {
		return '.custom-logo,.wp-block-site-logo img,.site-logo img,[class*="logo"] > img,[class*="logo"] > a > img,img.avatar,img.emoji,img.wp-smiley,#wpadminbar img,button img';
	}

	/**
	 * The "common" list, as printed.
	 *
	 * @return string
	 */
	private function common_list() {
		return '.entry-content img,.wp-block-image img,.wp-block-gallery img,.gallery img,.wp-block-post-featured-image img,.wp-post-image,.wp-lightbox-overlay img,.woocommerce-product-gallery img,.pswp img,.elementor-widget-image img,.elementor-gallery-item img,.elementor-lightbox img';
	}

	/**
	 * The default scope is "all", including for sites saved before the
	 * scope setting existed.
	 */
	public function test_default_scope_is_all() {
		$this->assertSame( 'all', notfortraining_defaults()['save_friction_scope'] );
		$this->assertSame( 'all', notfortraining_get_option( 'save_friction_scope' ) );

		// An older stored array with no scope key.
		update_option(
			'notfortraining_settings',
			array(
				'robots_mode'             => 'training',
				'save_friction'           => true,
				'save_friction_selectors' => '',
			)
		);
		$this->assertSame( 'all', notfortraining_get_option( 'save_friction_scope' ) );
	}

	/**
	 * "all": every image minus the exclusion list, in CSS and script, with
	 * the size check in the script.
	 */
	public function test_all_scope_output() {
		$this->assertSame(
			array(
				'covered' => 'img',
				'skip'    => $this->exclusions(),
				'custom'  => '',
			),
			notfortraining_save_friction_selectors()
		);

		$this->go_to( home_url( '/' ) );
		$out = $this->enqueue();
		$this->assertSame( 'img:not(' . $this->exclusions() . ')' . $this->declarations(), $out['css'] );
		$this->assertStringContainsString( '(document,"img",' . wp_json_encode( $this->exclusions() ) . ',"");', $out['js'] );
		foreach ( array( '.custom-logo', '.wp-block-site-logo img', '.site-logo img', '[class*=\\"logo\\"] > img', '[class*=\\"logo\\"] > a > img', 'img.avatar', 'img.emoji', 'img.wp-smiley', '#wpadminbar img', 'button img' ) as $exclusion ) {
			$this->assertStringContainsString( $exclusion, $out['js'], $exclusion );
		}
		$this->assertStringNotContainsString( 'logo"] img', $out['css'], 'A loose logo match would cover <body class="wp-custom-logo"> and so every image.' );
		$this->assertStringContainsString( 'r=t.getBoundingClientRect();if(r.width<64||r.height<64)return;', $out['js'] );
		$this->assertStringContainsString( '!(c&&t.matches(c)||t.matches(s)&&!(x&&t.matches(x)))', $out['js'] );
	}

	/**
	 * "common": the old default list, with no exclusions.
	 */
	public function test_common_scope_output() {
		$this->set_settings( array( 'save_friction_scope' => 'common' ) );
		$this->assertSame(
			array(
				'covered' => $this->common_list(),
				'skip'    => '',
				'custom'  => '',
			),
			notfortraining_save_friction_selectors()
		);

		$this->go_to( home_url( '/' ) );
		$out = $this->enqueue();
		$this->assertSame( $this->common_list() . $this->declarations(), $out['css'] );
		$this->assertStringContainsString( '(document,' . wp_json_encode( $this->common_list() ) . ',"","");', $out['js'] );
		$this->assertStringNotContainsString( 'img.avatar', $out['css'] . $out['js'] );
		$this->assertStringContainsString( 'if(r.width<64||r.height<64)return;', $out['js'], 'The size check applies to both scopes.' );
	}

	/**
	 * Custom selectors get their own CSS rule and are added in the script,
	 * only if the browser accepts them, in both scopes.
	 */
	public function test_custom_selectors_appended() {
		foreach ( array(
			'all'    => 'img:not(' . $this->exclusions() . ')',
			'common' => $this->common_list(),
		) as $scope => $covered ) {
			$this->set_settings(
				array(
					'save_friction_scope'     => $scope,
					'save_friction_selectors' => '.portfolio img, .hero > img',
				)
			);
			$this->assertSame( '.portfolio img, .hero img', notfortraining_get_option( 'save_friction_selectors' ), $scope );

			$this->go_to( home_url( '/' ) );
			$out = $this->enqueue();
			$this->assertSame( $covered . $this->declarations() . '.portfolio img, .hero img' . $this->declarations(), $out['css'], $scope );
			$this->assertStringEndsWith( ',".portfolio img, .hero img");', $out['js'], $scope );
			$this->assertStringContainsString( 'if(c){try{d.querySelector(c)}catch(e){c=""}}', $out['js'], $scope );
		}
	}

	/**
	 * The option and the settings sanitizer only accept the two scopes.
	 */
	public function test_scope_sanitizer() {
		$this->assertSame( array( 'all', 'common' ), notfortraining_save_friction_scopes() );
		foreach ( array( 'all', 'common' ) as $scope ) {
			$this->assertSame( $scope, notfortraining_sanitize_option( 'save_friction_scope', $scope ) );
			$this->assertSame( $scope, notfortraining_sanitize_settings( array( 'save_friction_scope' => $scope ) )['save_friction_scope'] );
		}
		foreach ( array( 'ALL', 'none', '', 'common ', 1, null, array( 'common' ) ) as $bad ) {
			$this->assertSame( 'all', notfortraining_sanitize_option( 'save_friction_scope', $bad ), wp_json_encode( $bad ) );
			$this->assertSame( 'all', notfortraining_sanitize_settings( array( 'save_friction_scope' => $bad ) )['save_friction_scope'], wp_json_encode( $bad ) );
		}
		$this->assertSame( 'all', notfortraining_sanitize_settings( array() )['save_friction_scope'] );
	}

	/**
	 * The size stays tiny.
	 */
	public function test_size() {
		$this->go_to( home_url( '/' ) );
		foreach ( array( 'all', 'common' ) as $scope ) {
			$this->set_settings( array( 'save_friction_scope' => $scope ) );
			$out = $this->enqueue();
			$this->assertLessThan( 1024, strlen( $out['css'] ) + strlen( $out['js'] ), $scope );
		}
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
		$this->assertMatchesRegularExpression( '#<input type="radio" name="notfortraining_settings\[save_friction_scope\]" value="all"[^>]*checked#', $html );
		$this->assertStringContainsString( 'Every image on my pages (recommended)', $html );
		$this->assertStringContainsString( 'Covers all your images, so it works with any theme. Logos and small icons are left alone.', $html );
		$this->assertStringContainsString( 'Only standard WordPress image blocks', $html );
		$this->assertStringContainsString( 'Use this if covering every image causes a problem on your site.', $html );
		$this->assertLessThan( strpos( $html, 'notfortraining-save_friction_selectors' ), strpos( $html, 'save_friction_scope' ), 'Radios go above the textarea.' );
		$this->assertLessThan( strpos( $html, 'save_friction_scope' ), strpos( $html, 'id="notfortraining-save_friction"' ), 'Radios go under the checkbox.' );
		$this->assertStringContainsString( '<label for="notfortraining-save_friction_selectors">Extra images to cover</label>', $html );
		$this->assertMatchesRegularExpression( '#<textarea id="notfortraining-save_friction_selectors" name="notfortraining_settings\[save_friction_selectors\]"[^>]*maxlength="500"#', $html );
		$this->assertStringContainsString( 'For web people. Leave this blank unless some images on your site aren&#039;t covered. Use CSS selectors, separated by commas.', $html );
		$this->assertMatchesRegularExpression( '/id="notfortraining-save_friction"[^>]*checked/', $html );
		$this->assertLessThan( strpos( $html, 'notfortraining-save_friction_selectors' ), strpos( $html, 'id="notfortraining-save_friction"' ), 'Textarea goes under the checkbox.' );
	}
}
