<?php
/**
 * Clear page caches when a settings change alters what pages contain, so
 * visitors don't keep getting cached pages without (or with) the change.
 *
 * Only when the settings page is saved, and only if one of the settings
 * that change page output actually changed. Nothing is purged anywhere
 * else: not on activation, deactivation, "Check again" or cron.
 *
 * @package NotForTraining
 */

defined( 'ABSPATH' ) || exit;

// Before notfortraining_clear_status (priority 10): purge first, so the
// next status check reads the fresh page.
add_action( 'update_option_notfortraining_settings', 'notfortraining_purge_on_update', 5, 2 );
add_action( 'add_option_notfortraining_settings', 'notfortraining_purge_on_add', 5, 2 );

/**
 * Settings that change what pages contain.
 *
 * @return string[]
 */
function notfortraining_page_output_settings() {
	return array( 'noai_signals', 'tdm_reservation', 'save_friction', 'save_friction_scope', 'save_friction_selectors' );
}

/**
 * Page cache purges this plugin knows about. Each checks for the cache
 * plugin's own public API, confirmed in its code:
 *
 * - WP Super Cache 3.1.5: wp_cache_clear_cache( $blog_id = 0 ).
 * - W3 Total Cache 2.11.0: w3tc_flush_all( $extras = null ).
 * - WP Fastest Cache 1.5.5: wpfc_clear_all_cache( $minified = false ).
 * - Cache Enabler 1.8.17: the cache_enabler_clear_complete_cache action.
 * - LiteSpeed Cache 7.9.4: the litespeed_purge_all action, only where
 *   LiteSpeed can cache (it defines LITESPEED_ALLOWED); elsewhere there's
 *   no cache to clear.
 * - WP Rocket: rocket_clean_domain(). Not free, so not checked in its
 *   code; used only if the function exists.
 *
 * @return array<string, array{name: string, available: callable, purge: callable}>
 */
function notfortraining_cache_purgers() {
	return array(
		'wp-super-cache'   => array(
			'name'      => 'WP Super Cache',
			'available' => static function () {
				return function_exists( 'wp_cache_clear_cache' );
			},
			'purge'     => static function () {
				wp_cache_clear_cache();
			},
		),
		'w3-total-cache'   => array(
			'name'      => 'W3 Total Cache',
			'available' => static function () {
				return function_exists( 'w3tc_flush_all' );
			},
			'purge'     => static function () {
				w3tc_flush_all();
			},
		),
		'wp-fastest-cache' => array(
			'name'      => 'WP Fastest Cache',
			'available' => static function () {
				return function_exists( 'wpfc_clear_all_cache' );
			},
			'purge'     => static function () {
				wpfc_clear_all_cache();
			},
		),
		'cache-enabler'    => array(
			'name'      => 'Cache Enabler',
			'available' => static function () {
				return false !== has_action( 'cache_enabler_clear_complete_cache' );
			},
			'purge'     => static function () {
				do_action( 'cache_enabler_clear_complete_cache' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Cache Enabler's own action.
			},
		),
		'litespeed-cache'  => array(
			'name'      => 'LiteSpeed Cache',
			'available' => static function () {
				return defined( 'LITESPEED_ALLOWED' ) && false !== has_action( 'litespeed_purge_all' );
			},
			'purge'     => static function () {
				do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's own action.
			},
		),
		'wp-rocket'        => array(
			'name'      => 'WP Rocket',
			'available' => static function () {
				return function_exists( 'rocket_clean_domain' );
			},
			'purge'     => static function () {
				rocket_clean_domain();
			},
		),
	);
}

/**
 * Whether this request is the settings page being saved through
 * options.php (which has already checked its nonce and capability).
 *
 * @return bool
 */
function notfortraining_is_settings_page_save() {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- options.php verified the nonce for this option page before saving.
	$option_page = isset( $_POST['option_page'] ) ? sanitize_key( wp_unslash( $_POST['option_page'] ) ) : '';
	return is_admin() && 'notfortraining' === $option_page;
}

/**
 * Whether any page output setting differs between two settings arrays.
 *
 * @param mixed $old_value Old value.
 * @param mixed $new_value New value.
 * @return bool
 */
function notfortraining_page_output_changed( $old_value, $new_value ) {
	$defaults  = notfortraining_defaults();
	$old_value = array_merge( $defaults, is_array( $old_value ) ? $old_value : array() );
	$new_value = array_merge( $defaults, is_array( $new_value ) ? $new_value : array() );
	foreach ( notfortraining_page_output_settings() as $key ) {
		if ( notfortraining_sanitize_option( $key, $old_value[ $key ] ) !== notfortraining_sanitize_option( $key, $new_value[ $key ] ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Settings updated.
 *
 * @param mixed $old_value Old value.
 * @param mixed $new_value New value.
 */
function notfortraining_purge_on_update( $old_value, $new_value ) {
	notfortraining_maybe_purge( $old_value, $new_value );
}

/**
 * Settings saved for the first time (the old value was the defaults).
 *
 * @param string $option Option name.
 * @param mixed  $value  New value.
 */
function notfortraining_purge_on_add( $option, $value ) {
	unset( $option );
	notfortraining_maybe_purge( notfortraining_defaults(), $value );
}

/**
 * Purge every available page cache if this is a settings page save that
 * changed page output, and say so in the "Settings saved." notice.
 *
 * @param mixed $old_value Old value.
 * @param mixed $new_value New value.
 * @return string[] Names of the caches cleared.
 */
function notfortraining_maybe_purge( $old_value, $new_value ) {
	if ( ! notfortraining_is_settings_page_save() || ! notfortraining_page_output_changed( $old_value, $new_value ) ) {
		return array();
	}

	$cleared = array();
	foreach ( notfortraining_cache_purgers() as $purger ) {
		if ( call_user_func( $purger['available'] ) ) {
			call_user_func( $purger['purge'] );
			$cleared[] = $purger['name'];
		}
	}

	if ( $cleared ) {
		set_transient( notfortraining_purge_transient(), time(), MINUTE_IN_SECONDS );
		add_settings_error(
			'general',
			'settings_updated',
			esc_html__( 'Settings saved.', 'not-for-training' ) . '<br>' . esc_html( notfortraining_purge_message( $cleared ) ),
			'success'
		);
	}
	return $cleared;
}

/**
 * Transient holding the time of the last purge.
 *
 * @return string
 */
function notfortraining_purge_transient() {
	return 'notfortraining_cache_purged';
}

/**
 * Whether a page cache was purged in the last 60 seconds. The time is
 * compared directly, so it holds even where transients outlive their
 * expiry.
 *
 * @return bool
 */
function notfortraining_cache_recently_purged() {
	$purged = (int) get_transient( notfortraining_purge_transient() );
	return $purged > 0 && time() - $purged < MINUTE_IN_SECONDS;
}

/**
 * The notice line for the caches cleared.
 *
 * @param string[] $names Cache plugin names.
 * @return string
 */
function notfortraining_purge_message( array $names ) {
	$names = array_values( $names );
	if ( 1 === count( $names ) ) {
		return sprintf(
			/* translators: %s: caching plugin name. */
			__( "Cleared %s's page cache so visitors see the change.", 'not-for-training' ),
			$names[0]
		);
	}
	$last = array_pop( $names );
	$list = sprintf(
		/* translators: 1: caching plugin names separated by commas, 2: the last caching plugin name. */
		__( '%1$s and %2$s', 'not-for-training' ),
		implode( ', ', $names ),
		$last
	);
	return sprintf(
		/* translators: %s: caching plugin names, as "A and B" or "A, B and C". */
		__( 'Cleared the page caches from %s so visitors see the change.', 'not-for-training' ),
		$list
	);
}
