<?php
/**
 * Test double: the GD editor, but every JPEG it saves gets the source
 * file's EXIF APP1 block back. That's what WordPress's Imagick editor does
 * on real hosts (it keeps EXIF, IPTC and XMP in every size), without
 * depending on an Imagick build with JPEG support.
 *
 * @package NotForTraining
 */

defined( 'ABSPATH' ) || exit;

require_once ABSPATH . WPINC . '/class-wp-image-editor.php';
require_once ABSPATH . WPINC . '/class-wp-image-editor-gd.php';

/**
 * GD editor that keeps EXIF.
 */
class NotForTraining_Exif_Keeping_Editor extends WP_Image_Editor_GD {

	/**
	 * Save, then splice the source's EXIF APP1 in after SOI.
	 *
	 * @param resource|GdImage $image     Image.
	 * @param string|null      $filename  Target file.
	 * @param string|null      $mime_type Mime type.
	 * @return array|WP_Error
	 */
	protected function _save( $image, $filename = null, $mime_type = null ) { // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore -- Overrides core's method.
		$saved = parent::_save( $image, $filename, $mime_type );
		if ( is_wp_error( $saved ) || 'image/jpeg' !== $saved['mime-type'] ) {
			return $saved;
		}

		$source = file_get_contents( $this->file );
		$start  = strpos( $source, "\xFF\xE1" );
		if ( false === $start || "Exif\0\0" !== substr( $source, $start + 4, 6 ) ) {
			return $saved;
		}
		$app1 = substr( $source, $start, 2 + unpack( 'n', substr( $source, $start + 2, 2 ) )[1] );

		$jpeg = file_get_contents( $saved['path'] );
		file_put_contents( $saved['path'], substr( $jpeg, 0, 2 ) . $app1 . substr( $jpeg, 2 ) );
		return $saved;
	}
}
