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
		return in_array( $value, array( 'training', 'all', 'off' ), true ) ? $value : $defaults['robots_mode'];
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
