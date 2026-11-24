<?php
/**
 * Output of robots.txt in each mode.
 *
 * @package NotForTraining
 */

/**
 * Tests for notfortraining_robots_txt().
 */
class NotForTraining_Test_Robots_Txt extends NotForTraining_TestCase {

	/**
	 * Rules a WooCommerce-style plugin adds to the "*" group, plus a Sitemap
	 * line, to prove other plugins' output survives untouched.
	 *
	 * @param string $output robots.txt so far.
	 * @return string
	 */
	public static function add_other_plugin_rules( $output ) {
		return $output . "Disallow: /*?add-to-cart=\nDisallow: /*?*add-to-cart=\n\nSitemap: https://example.org/sitemap_index.xml\n";
	}

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		add_filter( 'robots_txt', array( __CLASS__, 'add_other_plugin_rules' ), 10 );
	}

	/**
	 * What core's do_robots() builds before the filter runs.
	 *
	 * @return string
	 */
	private function core_output() {
		return "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n";
	}

	/**
	 * The robots.txt as served, with or without this plugin's filter.
	 *
	 * @param bool   $with_plugin Run the plugin's filter.
	 * @param string $is_public   blog_public value.
	 * @return string
	 */
	private function robots( $with_plugin = true, $is_public = '1' ) {
		if ( ! $with_plugin ) {
			remove_filter( 'robots_txt', 'notfortraining_robots_txt', 999 );
		}
		$output = apply_filters( 'robots_txt', $this->core_output(), $is_public );
		if ( ! $with_plugin ) {
			add_filter( 'robots_txt', 'notfortraining_robots_txt', 999, 2 );
		}
		return $output;
	}

	/**
	 * Parse robots.txt into groups: each is a list of user agents and the
	 * rule lines that follow them, in order. Comments, blank lines and
	 * Sitemap lines are skipped.
	 *
	 * @param string $robots robots.txt.
	 * @return array<int, array{agents: string[], rules: string[]}>
	 */
	private function groups( $robots ) {
		$groups  = array();
		$current = null;
		foreach ( explode( "\n", $robots ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || '#' === $line[0] || 0 === stripos( $line, 'Sitemap:' ) ) {
				continue;
			}
			if ( 0 === stripos( $line, 'User-agent:' ) ) {
				if ( null === $current || $current['rules'] ) {
					$groups[] = array(
						'agents' => array(),
						'rules'  => array(),
					);
					$current  = &$groups[ count( $groups ) - 1 ];
				}
				$current['agents'][] = trim( substr( $line, strlen( 'User-agent:' ) ) );
				continue;
			}
			$current['rules'][] = $line;
		}
		unset( $current );
		return $groups;
	}

	/**
	 * The group whose agents include $agent.
	 *
	 * @param array  $groups Parsed groups.
	 * @param string $agent  User agent.
	 * @return array|null
	 */
	private function group_for( array $groups, $agent ) {
		foreach ( $groups as $group ) {
			if ( in_array( $agent, $group['agents'], true ) ) {
				return $group;
			}
		}
		return null;
	}

	/**
	 * The plugin only appends: everything before its block is byte for byte
	 * what core and other plugins produced.
	 */
	public function test_training_mode_only_appends() {
		$before = $this->robots( false );
		$after  = $this->robots();

		$this->assertStringStartsWith( rtrim( $before ) . "\n", $after );
		$this->assertSame( $this->group_for( $this->groups( $before ), '*' ), $this->group_for( $this->groups( $after ), '*' ) );
	}

	/**
	 * Default mode: training crawlers get Disallow: /, AI search crawlers get
	 * the "*" group's exceptions then Allow: / last.
	 */
	public function test_training_mode_groups() {
		$bots   = notfortraining_get_bots();
		$groups = $this->groups( $this->robots() );

		$this->assertCount( 3, $groups );

		$training = $this->group_for( $groups, 'GPTBot' );
		$this->assertSame( $bots['training'], $training['agents'] );
		$this->assertSame( array( 'Disallow: /' ), $training['rules'] );

		$search = $this->group_for( $groups, 'OAI-SearchBot' );
		$this->assertSame( $bots['search'], $search['agents'] );
		$this->assertSame(
			array(
				'Allow: /wp-admin/admin-ajax.php',
				'Disallow: /wp-admin/',
				'Disallow: /*?add-to-cart=',
				'Disallow: /*?*add-to-cart=',
				'Allow: /',
			),
			$search['rules']
		);
		$this->assertSame( 'Allow: /', end( $search['rules'] ), 'Allow: / must be the last rule in the search group.' );
	}

	/**
	 * Search engines aren't named anywhere: they keep the "*" group.
	 */
	public function test_search_engines_keep_the_star_group() {
		$robots = $this->robots();
		foreach ( array( 'Googlebot', 'Bingbot', 'Applebot', 'Slurp', 'DuckDuckBot' ) as $agent ) {
			$this->assertDoesNotMatchRegularExpression( '/^User-agent:\s*' . preg_quote( $agent, '/' ) . '\s*$/mi', $robots );
		}
		$this->assertSame( 1, preg_match_all( '/^User-agent: \*$/m', $robots ) );
	}

	/**
	 * Every listed agent appears exactly once.
	 */
	public function test_each_agent_listed_once() {
		$robots = $this->robots();
		$bots   = notfortraining_get_bots();
		foreach ( array_merge( $bots['training'], $bots['search'] ) as $agent ) {
			$this->assertSame( 1, preg_match_all( '/^User-agent: ' . preg_quote( $agent, '/' ) . '$/m', $robots ), $agent );
		}
	}

	/**
	 * "all" mode: both groups Disallow: /, "*" group unchanged.
	 */
	public function test_all_mode() {
		$this->set_settings( array( 'robots_mode' => 'all' ) );

		$before = $this->robots( false );
		$after  = $this->robots();
		$groups = $this->groups( $after );

		$this->assertStringStartsWith( rtrim( $before ) . "\n", $after );
		$this->assertSame( array( 'Disallow: /' ), $this->group_for( $groups, 'GPTBot' )['rules'] );
		$this->assertSame( array( 'Disallow: /' ), $this->group_for( $groups, 'OAI-SearchBot' )['rules'] );
		$this->assertSame( $this->group_for( $this->groups( $before ), '*' ), $this->group_for( $groups, '*' ) );
	}

	/**
	 * "off" mode: output unchanged.
	 */
	public function test_off_mode() {
		$this->set_settings( array( 'robots_mode' => 'off' ) );
		$this->assertSame( $this->robots( false ), $this->robots() );
	}

	/**
	 * Discourage search engines (blog_public 0): output unchanged.
	 */
	public function test_not_public_is_unchanged() {
		$this->assertSame( $this->robots( false, '0' ), $this->robots( true, '0' ) );
	}

	/**
	 * An unknown mode falls back to the default.
	 */
	public function test_unknown_mode_falls_back_to_training() {
		$this->set_settings( array( 'robots_mode' => 'everything' ) );
		$this->assertSame( 'training', notfortraining_get_option( 'robots_mode' ) );
	}

	/**
	 * Filtered agent tokens that could inject rules are dropped.
	 */
	public function test_invalid_agents_are_dropped() {
		$inject = static function ( $bots ) {
			$bots['search'][] = "Evil\nUser-agent: *\nDisallow: /";
			return $bots;
		};
		add_filter( 'notfortraining_bots', $inject );
		$robots = $this->robots();
		remove_filter( 'notfortraining_bots', $inject );

		$this->assertStringNotContainsString( 'Evil', $robots );
		$this->assertSame( 1, preg_match_all( '/^User-agent: \*$/m', $robots ) );
	}

	/**
	 * The lists carry a version and date.
	 */
	public function test_bot_list_metadata() {
		$bots = notfortraining_get_bots();
		$this->assertNotEmpty( $bots['list_version'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}$/', $bots['list_date'] );
		$this->assertContains( 'GPTBot', $bots['training'] );
		$this->assertContains( 'Claude-User', $bots['search'] );
		$this->assertEmpty( array_intersect( $bots['training'], $bots['search'] ) );
	}
}
