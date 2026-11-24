<?php
/**
 * Test double: an Imagick editor that always loads. The wp-env Imagick
 * build has no JPEG support, so the real class can't be selected there.
 *
 * @package NotForTraining
 */

defined( 'ABSPATH' ) || exit;

require_once ABSPATH . WPINC . '/class-wp-image-editor.php';
require_once ABSPATH . WPINC . '/class-wp-image-editor-imagick.php';

/**
 * Imagick editor stand-in.
 */
class NotForTraining_Fake_Imagick_Editor extends WP_Image_Editor_Imagick {

	/**
	 * Always available.
	 *
	 * @param array $args Arguments.
	 * @return bool
	 */
	public static function test( $args = array() ) {
		return true;
	}

	/**
	 * Supports everything.
	 *
	 * @param string $mime_type Mime type.
	 * @return bool
	 */
	public static function supports_mime_type( $mime_type ) {
		return true;
	}

	/**
	 * Loads without touching Imagick.
	 *
	 * @return true
	 */
	public function load() {
		return true;
	}
}
