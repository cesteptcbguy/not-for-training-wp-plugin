<?php
/**
 * Settings storage and defaults. All settings live in one array option,
 * notfortraining_settings. There's no settings screen yet: values come from
 * the defaults below unless the option or the notfortraining_option filter
 * says otherwise.
 *
 * @package NotForTraining
 */

defined( 'ABSPATH' ) || exit;

/**
 * Default value for every setting.
 *
 * - robots_mode:      "training" (block training crawlers, allow AI search),
 *                     "all" (block both lists) or "off" (robots.txt untouched).
 * - noai_signals:     noai, noimageai in the robots meta and X-Robots-Tag.
 * - tdm_reservation:  tdm-reservation meta, header and /.well-known/tdmrep.json.
 * - max_image_size:   longest side, in px, of anything generated from an
 *                     upload. 0 leaves WordPress's own limits alone.
 * - strip_gps:        remove EXIF GPS from uploaded JPEGs.
 * - delete_originals: delete the uploaded original once WordPress has made
 *                     a scaled copy. Off by default: it can't be undone.
 *
 * @return array<string, mixed>
 */
function notfortraining_defaults() {
	return array(
		'robots_mode'      => 'training',
		'noai_signals'     => true,
		'tdm_reservation'  => true,
		'max_image_size'   => 1600,
		'strip_gps'        => true,
		'delete_originals' => false,
	);
}

/**
 * Read one setting, falling back to its default. Stored values are coerced
 * to the default's type, and robots_mode to one of its allowed values, so
 * callers can trust what they get.
 *
 * @param string $key Setting name, a key of notfortraining_defaults().
 * @return mixed The value, or null for an unknown key.
 */
function notfortraining_get_option( $key ) {
	$defaults = notfortraining_defaults();
	if ( ! array_key_exists( $key, $defaults ) ) {
		return null;
	}

	$stored = get_option( 'notfortraining_settings', array() );
	$value  = ( is_array( $stored ) && array_key_exists( $key, $stored ) ) ? $stored[ $key ] : $defaults[ $key ];

	/**
	 * Filters one Not For Training setting after it's read.
	 *
	 * @param mixed  $value Setting value.
	 * @param string $key   Setting name.
	 */
	$value = apply_filters( 'notfortraining_option', $value, $key );

	return notfortraining_sanitize_option( $key, $value );
}

/**
 * Coerce a setting to its expected type.
 *
 * @param string $key   Setting name.
 * @param mixed  $value Raw value.
 * @return mixed
 */
function notfortraining_sanitize_option( $key, $value ) {
	$defaults = notfortraining_defaults();

	if ( 'robots_mode' === $key ) {
		$value = is_string( $value ) ? strtolower( $value ) : '';
		return in_array( $value, notfortraining_robots_modes(), true ) ? $value : $defaults['robots_mode'];
	}

	if ( 'max_image_size' === $key ) {
		return is_numeric( $value ) ? max( 0, (int) $value ) : $defaults['max_image_size'];
	}

	if ( is_bool( $defaults[ $key ] ) ) {
		return (bool) $value;
	}

	return $value;
}

/**
 * Allowed robots_mode values.
 *
 * @return string[]
 */
function notfortraining_robots_modes() {
	return array( 'training', 'all', 'off' );
}

/**
 * The image cap choices offered on the settings page, in px (0 = WordPress
 * default).
 *
 * @return int[]
 */
function notfortraining_image_size_choices() {
	return array( 1200, 1600, 2048, 0 );
}

/**
 * Sanitize callback for the notfortraining_settings option (Settings API).
 *
 * Every key is validated on its own; anything missing or not a valid value
 * falls back to its default. Unknown keys are dropped. Checkboxes on the
 * settings page post "0" from a hidden field when unchecked and "1" when
 * checked, so a missing key means "not submitted", not "off".
 *
 * @param mixed $input Submitted value.
 * @return array<string, mixed> A complete, valid settings array.
 */
function notfortraining_sanitize_settings( $input ) {
	$defaults = notfortraining_defaults();
	$input    = is_array( $input ) ? $input : array();
	$clean    = array();

	$mode                 = isset( $input['robots_mode'] ) && is_string( $input['robots_mode'] ) ? sanitize_key( $input['robots_mode'] ) : '';
	$clean['robots_mode'] = in_array( $mode, notfortraining_robots_modes(), true ) ? $mode : $defaults['robots_mode'];

	$size                    = $input['max_image_size'] ?? null;
	$size                    = ( is_int( $size ) || ( is_string( $size ) && ctype_digit( $size ) ) ) ? (int) $size : -1;
	$clean['max_image_size'] = in_array( $size, notfortraining_image_size_choices(), true ) ? $size : $defaults['max_image_size'];

	foreach ( array( 'noai_signals', 'tdm_reservation', 'strip_gps', 'delete_originals' ) as $key ) {
		$value = $input[ $key ] ?? null;
		if ( true === $value || 1 === $value || '1' === $value ) {
			$clean[ $key ] = true;
		} elseif ( false === $value || 0 === $value || '0' === $value ) {
			$clean[ $key ] = false;
		} else {
			$clean[ $key ] = $defaults[ $key ];
		}
	}

	// Same key order as the defaults.
	return array_merge( $defaults, $clean );
}

/**
 * The crawler lists from includes/bots.php.
 *
 * @return array{list_version: string, list_date: string, training: string[], search: string[]}
 */
function notfortraining_get_bots() {
	static $bots = null;
	if ( null === $bots ) {
		$bots = require NOTFORTRAINING_DIR . 'includes/bots.php';
	}

	/**
	 * Filters the crawler lists used in robots.txt.
	 *
	 * @param array $bots {
	 *     @type string   $list_version List version.
	 *     @type string   $list_date    Date the list was last reviewed (Y-m-d).
	 *     @type string[] $training     AI training and dataset user agents.
	 *     @type string[] $search       AI search and user-fetch user agents.
	 * }
	 */
	$filtered = apply_filters( 'notfortraining_bots', $bots );

	return array(
		'list_version' => (string) ( $filtered['list_version'] ?? $bots['list_version'] ),
		'list_date'    => (string) ( $filtered['list_date'] ?? $bots['list_date'] ),
		'training'     => array_values( array_filter( array_map( 'strval', (array) ( $filtered['training'] ?? array() ) ), 'notfortraining_is_valid_user_agent' ) ),
		'search'       => array_values( array_filter( array_map( 'strval', (array) ( $filtered['search'] ?? array() ) ), 'notfortraining_is_valid_user_agent' ) ),
	);
}

/**
 * Whether a string is safe to print as a robots.txt user-agent token: no
 * line breaks, comments or separators that would change the file's meaning.
 *
 * @param string $agent User agent token.
 * @return bool
 */
function notfortraining_is_valid_user_agent( $agent ) {
	return '' !== $agent && 1 === preg_match( '/^[A-Za-z0-9._\-]+$/', $agent );
}
