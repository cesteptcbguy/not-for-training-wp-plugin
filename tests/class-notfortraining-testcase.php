<?php
/**
 * Shared helpers for the plugin's tests.
 *
 * @package NotForTraining
 */

/**
 * Base test case.
 */
abstract class NotForTraining_TestCase extends WP_UnitTestCase {

	/**
	 * Set up: no test reaches the network. A request no test has mocked
	 * fails like a timeout would.
	 */
	public function set_up() {
		parent::set_up();
		add_filter( 'pre_http_request', array( __CLASS__, 'block_unmocked_http' ), 99 );
	}

	/**
	 * Fail any request nothing else answered.
	 *
	 * @param false|array|WP_Error $pre Short-circuit value.
	 * @return array|WP_Error
	 */
	public static function block_unmocked_http( $pre ) {
		return false === $pre ? new WP_Error( 'http_request_failed', 'Unmocked HTTP request in a test.' ) : $pre;
	}

	/**
	 * Store settings, merged over the defaults.
	 *
	 * @param array $settings Settings to change.
	 */
	protected function set_settings( array $settings ) {
		update_option( 'notfortraining_settings', array_merge( notfortraining_defaults(), $settings ) );
	}
}
