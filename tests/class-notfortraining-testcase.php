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
	 * Store settings, merged over the defaults.
	 *
	 * @param array $settings Settings to change.
	 */
	protected function set_settings( array $settings ) {
		update_option( 'notfortraining_settings', array_merge( notfortraining_defaults(), $settings ) );
	}
}
