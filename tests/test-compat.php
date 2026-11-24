<?php
/**
 * SEO plugin compatibility shims, tested through each plugin's real filter
 * names and data shapes (the plugins themselves aren't loaded).
 *
 * @package NotForTraining
 */

/**
 * Tests for includes/compat/.
 */
class NotForTraining_Test_Compat extends NotForTraining_TestCase {

	/**
	 * Load every shim's functions once (registration is per test).
	 */
	public static function wpSetUpBeforeClass() {
		foreach ( array( 'rank-math', 'aioseo', 'seopress' ) as $shim ) {
			require_once NOTFORTRAINING_DIR . 'includes/compat/' . $shim . '.php';
		}
	}

	/**
	 * Tear down: no shim stays hooked.
	 */
	public function tear_down() {
		notfortraining_compat_rank_math_unregister();
		notfortraining_compat_aioseo_unregister();
		notfortraining_compat_seopress_unregister();
		remove_all_actions( 'wp_head' );
		parent::tear_down();
	}

	/**
	 * Nothing is hooked unless its plugin is active.
	 */
	public function test_loader_detects_plugins() {
		$this->assertSame(
			array(
				'rank-math' => false,
				'aioseo'    => false,
				'seopress'  => false,
			),
			notfortraining_compat_active()
		);
		$this->assertFalse( has_filter( 'rank_math/frontend/robots', 'notfortraining_compat_rank_math_robots' ) );
		$this->assertFalse( has_filter( 'aioseo_robots_meta', 'notfortraining_compat_aioseo_robots_meta' ) );
		$this->assertFalse( has_filter( 'seopress_titles_robots_attrs', 'notfortraining_compat_seopress_attrs' ) );
		$this->assertSame( 999, has_filter( 'robots_txt', 'notfortraining_robots_txt' ) );

		notfortraining_load_compat( array( 'rank-math' => true ) );
		$this->assertSame( 10, has_filter( 'rank_math/frontend/robots', 'notfortraining_compat_rank_math_robots' ) );
		$this->assertFalse( has_filter( 'aioseo_robots_meta', 'notfortraining_compat_aioseo_robots_meta' ) );

		notfortraining_load_compat( array( '../../evil' => true ) );
		$this->assertFalse( has_filter( 'aioseo_robots_meta', 'notfortraining_compat_aioseo_robots_meta' ) );
	}

	/**
	 * Rank Math: noai and noimageai go after its values; noindex and the
	 * rest are kept; the setting turns it off.
	 */
	public function test_rank_math() {
		notfortraining_compat_rank_math_register();

		$robots = apply_filters(
			'rank_math/frontend/robots', // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Rank Math's own hook name.
			array(
				'index'  => 'noindex',
				'follow' => 'follow',
			)
		);
		$this->assertSame( 'noindex, follow, noai, noimageai', implode( ', ', $robots ) );

		$this->set_settings( array( 'noai_signals' => false ) );
		$this->assertSame(
			array( 'index' => 'index' ),
			apply_filters( 'rank_math/frontend/robots', array( 'index' => 'index' ) ) // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Rank Math's own hook name.
		);
	}

	/**
	 * AIOSEO: same for its keyed attributes.
	 */
	public function test_aioseo_robots_meta() {
		notfortraining_compat_aioseo_register();

		$attributes = apply_filters(
			'aioseo_robots_meta',
			array(
				'noindex'           => 'noindex',
				'max-image-preview' => 'max-image-preview:large',
			)
		);
		$this->assertSame( 'noindex, max-image-preview:large, noai, noimageai', implode( ', ', array_filter( $attributes ) ) );

		$this->set_settings( array( 'noai_signals' => false ) );
		$this->assertArrayNotHasKey( 'noai', apply_filters( 'aioseo_robots_meta', array( 'noindex' => 'noindex' ) ) );
	}

	/**
	 * A stand-in for AIOSEO's robots.txt builder at priority 10000: it
	 * re-parses the output, gives sibling user agents only their group's
	 * first rule, and drops comments.
	 *
	 * @param string $output robots.txt.
	 * @return string
	 */
	public static function aioseo_like_builder( $output ) {
		$out      = '';
		$siblings = array();
		$agent    = null;
		foreach ( explode( "\n", $output ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || '#' === $line[0] ) {
				continue;
			}
			if ( 0 === stripos( $line, 'user-agent:' ) ) {
				if ( null !== $agent && empty( $rules[ $agent ] ) ) {
					$siblings[] = $agent;
				}
				$agent           = trim( substr( $line, 11 ) );
				$rules[ $agent ] = $rules[ $agent ] ?? array();
				continue;
			}
			$rules[ $agent ][] = $line;
			foreach ( $siblings as $sibling ) {
				$rules[ $sibling ] = $rules[ $agent ];
			}
			$siblings = array();
		}
		foreach ( $rules as $ua => $lines ) {
			$out .= "User-agent: $ua\n" . implode( "\n", $lines ) . "\n\n";
		}
		return $out;
	}

	/**
	 * AIOSEO: our block goes in after its builder, intact, so the AI search
	 * bots keep every rule and the marker survives.
	 */
	public function test_aioseo_robots_txt_after_builder() {
		$core = "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n";
		add_filter( 'robots_txt', array( __CLASS__, 'aioseo_like_builder' ), 10000 );

		// Without the shim: the builder mangles our block.
		$mangled = apply_filters( 'robots_txt', $core, '1' );
		$this->assertStringNotContainsString( '# Not For Training', $mangled );
		$groups = notfortraining_parse_robots_txt( $mangled );
		$this->assertCount( 1, notfortraining_robots_group_for( $groups, 'OAI-SearchBot' )['rules'], 'The bug this shim works around.' );

		// With the shim: our block comes last, exactly as written.
		notfortraining_compat_aioseo_register();
		$this->assertSame( 10001, has_filter( 'robots_txt', 'notfortraining_robots_txt' ) );
		$this->assertFalse( has_filter( 'robots_txt', 'notfortraining_robots_txt' ) === 999 );
		$fixed = apply_filters( 'robots_txt', $core, '1' );
		remove_filter( 'robots_txt', array( __CLASS__, 'aioseo_like_builder' ), 10000 );

		$this->assertStringEndsWith( notfortraining_robots_block(), $fixed );
		$this->assertTrue( notfortraining_robots_has_our_rules( $fixed ) );
		$search = notfortraining_robots_group_for( notfortraining_parse_robots_txt( $fixed ), 'OAI-SearchBot' );
		$this->assertSame( array( 'allow', '/' ), end( $search['rules'] ) );
		$this->assertCount( 5, $search['rules'] );

		notfortraining_compat_aioseo_unregister();
		$this->assertSame( 999, has_filter( 'robots_txt', 'notfortraining_robots_txt' ) );
	}

	/**
	 * SEOPress: values go into its list once; the setting turns it off.
	 */
	public function test_seopress_attrs() {
		notfortraining_compat_seopress_register();
		$this->assertSame(
			array( 'follow', 'noindex', 'noai', 'noimageai' ),
			apply_filters( 'seopress_titles_robots_attrs', array( 'follow', 'noindex' ) )
		);
		$this->assertSame(
			array( 'index, follow', 'noai', 'noimageai' ),
			apply_filters( 'seopress_titles_robots_attrs', array( 'index, follow', 'noai' ) )
		);

		$this->set_settings( array( 'noai_signals' => false ) );
		$this->assertSame( array( 'follow' ), apply_filters( 'seopress_titles_robots_attrs', array( 'follow' ) ) );
	}

	/**
	 * SEOPress: while its tag is going out, core's tag doesn't get our
	 * values (so core prints none, and the page has one robots tag). When
	 * SEOPress isn't printing a tag, core's keeps them.
	 */
	public function test_seopress_single_robots_tag() {
		update_option( 'blog_public', '1' );
		notfortraining_compat_seopress_register();
		remove_filter( 'wp_robots', 'wp_robots_max_image_preview_large' ); // As SEOPress does.

		add_action( 'wp_head', 'seopress_titles_advanced_robots_hook', 1 );
		$this->assertSame( '', get_echo( 'wp_robots' ), 'Core prints no robots tag.' );

		remove_action( 'wp_head', 'seopress_titles_advanced_robots_hook', 1 );
		$this->assertStringContainsString( "content='noai, noimageai'", get_echo( 'wp_robots' ) );

		add_filter( 'wp_robots', 'wp_robots_max_image_preview_large' );
	}
}
