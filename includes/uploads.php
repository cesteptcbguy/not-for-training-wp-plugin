<?php
/**
 * Uploads: a ceiling on the largest image the site generates and lists,
 * GPS stripping, and (opt-in) deleting the uploaded original once a scaled
 * copy exists.
 *
 * @package NotForTraining
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'big_image_size_threshold', 'notfortraining_big_image_size_threshold', 999 );
add_filter( 'intermediate_image_sizes_advanced', 'notfortraining_intermediate_image_sizes', 999 );
add_filter( 'max_srcset_image_width', 'notfortraining_max_srcset_image_width', 999 );
add_filter( 'wp_handle_upload', 'notfortraining_handle_upload_strip_gps' );
add_filter( 'wp_generate_attachment_metadata', 'notfortraining_delete_original_image', 10, 3 );

/**
 * The image cap in px, or 0 when it's off.
 *
 * @return int
 */
function notfortraining_image_cap() {
	return (int) notfortraining_get_option( 'max_image_size' );
}

/**
 * Uploads larger than the cap are scaled down to it (WordPress's "-scaled"
 * file), which becomes the "full" size. With the cap off, WordPress's own
 * threshold (2560 by default) applies.
 *
 * @param int|false $threshold Current threshold.
 * @return int|false
 */
function notfortraining_big_image_size_threshold( $threshold ) {
	$cap = notfortraining_image_cap();
	return $cap > 0 ? $cap : $threshold;
}

/**
 * Don't generate core's large sizes above the cap. WordPress makes
 * sub-sizes from the uploaded original, not the scaled file, so without
 * this 2048x2048 would be the largest file on the server. (1536x1536 goes
 * too when the cap is set below 1536.) Sizes registered by themes and
 * plugins are left alone.
 *
 * @param array<string, array{width: int, height: int, crop: bool|array}> $sizes Sizes to generate.
 * @return array
 */
function notfortraining_intermediate_image_sizes( $sizes ) {
	$cap = notfortraining_image_cap();
	if ( $cap <= 0 || ! is_array( $sizes ) ) {
		return $sizes;
	}
	unset( $sizes['2048x2048'] );
	if ( $cap < 1536 ) {
		unset( $sizes['1536x1536'] );
	}
	return $sizes;
}

/**
 * Srcset stops at the cap, so larger files made before the plugin was
 * active are never offered to browsers.
 *
 * @param int $max_width Current maximum.
 * @return int
 */
function notfortraining_max_srcset_image_width( $max_width ) {
	$cap = notfortraining_image_cap();
	return $cap > 0 ? min( (int) $max_width, $cap ) : $max_width;
}

/**
 * Strip GPS from new JPEG uploads before any size is made from them, so no
 * published file says where a photo was taken. wp_handle_upload runs for
 * uploads and sideloads (media library, block editor, importers).
 *
 * @param array{file?: string, url?: string, type?: string, error?: string} $upload Upload result.
 * @return array
 */
function notfortraining_handle_upload_strip_gps( $upload ) {
	if ( ! is_array( $upload ) || ! empty( $upload['error'] ) || empty( $upload['file'] ) ) {
		return $upload;
	}
	if ( 'image/jpeg' !== ( $upload['type'] ?? '' ) || ! notfortraining_get_option( 'strip_gps' ) ) {
		return $upload;
	}
	notfortraining_strip_gps_jpeg_file( $upload['file'] );
	return $upload;
}

/**
 * Opt-in: don't keep the original of a new upload WordPress scaled or
 * rotated, so the "-scaled" file is the largest copy the server holds. New
 * uploads only ("create"), never edits or regeneration. The image editor's
 * "restore original" can't go back past the scaled file afterwards.
 *
 * @param array  $metadata      Attachment metadata.
 * @param int    $attachment_id Attachment ID.
 * @param string $context       "create" or "update".
 * @return array
 */
function notfortraining_delete_original_image( $metadata, $attachment_id, $context = 'create' ) {
	if ( 'create' !== $context || empty( $metadata['original_image'] ) || ! notfortraining_get_option( 'delete_originals' ) ) {
		return $metadata;
	}

	$attached = get_attached_file( $attachment_id );
	if ( ! $attached ) {
		return $metadata;
	}

	$original = path_join( dirname( $attached ), wp_basename( $metadata['original_image'] ) );
	if ( $original !== $attached && is_file( $original ) ) {
		wp_delete_file( $original );
	}
	if ( ! file_exists( $original ) ) {
		unset( $metadata['original_image'] );
	}
	return $metadata;
}
