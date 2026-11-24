<?php
/**
 * Site Health tests: robots_served, search_open and noai_tag from the
 * status engine, as async tests with the same copy as the settings page.
 *
 * Severity: a problem, a warning or a check that couldn't run is
 * "recommended", never "critical". OK and FYI are "good". A "couldn't
 * check" result points to core's loopback test, which usually explains it.
 *
 * @package NotForTraining
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'site_status_tests', 'notfortraining_site_status_tests' );
add_action( 'rest_api_init', 'notfortraining_register_site_health_routes' );

/**
 * The checks Site Health runs.
 *
 * @return string[]
 */
function notfortraining_site_health_check_ids() {
	return array( 'robots_served', 'search_open', 'noai_tag' );
}

/**
 * REST namespace for the tests.
 *
 * @return string
 */
function notfortraining_site_health_namespace() {
	return 'not-for-training/v1';
}

/**
 * Whether a check has anything to report with the current settings, so
 * Site Health doesn't list tests that would come back empty.
 *
 * @param string $id Check ID.
 * @return bool
 */
function notfortraining_site_health_applies( $id ) {
	$public = '0' !== (string) get_option( 'blog_public' );
	switch ( $id ) {
		case 'robots_served':
			return $public && 'off' !== notfortraining_get_option( 'robots_mode' );
		case 'search_open':
			return ! $public || ! notfortraining_is_subdirectory_install();
		case 'noai_tag':
			return (bool) notfortraining_get_option( 'noai_signals' );
	}
	return false;
}

/**
 * Add the async tests.
 *
 * @param array $tests Site Health tests.
 * @return array
 */
function notfortraining_site_status_tests( $tests ) {
	$labels = array(
		'robots_served' => __( 'AI rules in robots.txt', 'not-for-training' ),
		'search_open'   => __( 'Search engines can reach the site', 'not-for-training' ),
		'noai_tag'      => __( 'No-AI tags on pages', 'not-for-training' ),
	);
	foreach ( notfortraining_site_health_check_ids() as $id ) {
		if ( ! notfortraining_site_health_applies( $id ) ) {
			continue;
		}
		$tests['async'][ 'notfortraining_' . $id ] = array(
			'label'             => $labels[ $id ],
			'test'              => rest_url( notfortraining_site_health_namespace() . '/site-health/' . $id ),
			'has_rest'          => true,
			'async_direct_test' => static function () use ( $id ) {
				return notfortraining_site_health_result( $id );
			},
		);
	}
	return $tests;
}

/**
 * REST routes, one per test.
 */
function notfortraining_register_site_health_routes() {
	register_rest_route(
		notfortraining_site_health_namespace(),
		'/site-health/(?P<check>' . implode( '|', notfortraining_site_health_check_ids() ) . ')',
		array(
			'methods'             => 'GET',
			'callback'            => static function ( WP_REST_Request $request ) {
				return rest_ensure_response( notfortraining_site_health_result( (string) $request['check'] ) );
			},
			'permission_callback' => static function () {
				return current_user_can( 'view_site_health_checks' );
			},
		)
	);
}

/**
 * A Site Health result for one check, from the engine's (cached) results.
 *
 * @param string $id Check ID.
 * @return array{label: string, status: string, badge: array, description: string, actions: string, test: string}
 */
function notfortraining_site_health_result( $id ) {
	$result = null;
	foreach ( notfortraining_get_status() as $candidate ) {
		if ( $id === $candidate['id'] ) {
			$result = $candidate;
			break;
		}
	}
	if ( null === $result ) {
		// Settings changed since Site Health listed the test: nothing to check now.
		$result = notfortraining_status_unknown( $id );
	}

	$severity    = in_array( $result['status'], array( 'problem', 'warn', 'unknown' ), true ) ? 'recommended' : 'good';
	$description = '<p>' . esc_html( $result['message'] ) . '</p>';
	if ( 'unknown' === $result['status'] ) {
		$description .= '<p>' . esc_html__( "WordPress's own loopback test, further down this page, can show why.", 'not-for-training' ) . '</p>';
	}

	return array(
		'label'       => $result['message'],
		'status'      => $severity,
		'badge'       => array(
			'label' => __( 'Not For Training', 'not-for-training' ),
			'color' => 'blue',
		),
		'description' => $description,
		'actions'     => 'recommended' === $severity
			? sprintf( '<p><a href="%s">%s</a></p>', esc_url( notfortraining_settings_url() . '#notfortraining-status' ), esc_html__( 'Open Not For Training settings', 'not-for-training' ) )
			: '',
		'test'        => 'notfortraining_' . $id,
	);
}
