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
add_filter( 'wp_generate_attachment_metadata', 'notfortraining_strip_gps_generated_files', 20, 3 );

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
 * uploads, sideloads and wp_upload_bits() (media library, block editor,
 * importers).
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

/**
 * Second GPS pass, after WordPress has made every size: the main file, each
 * file in "sizes", and original_image if it's still on disk.
 *
 * The wp_handle_upload hook only sees the file as uploaded, so this catches what it
 * can't: HEIC (and other) uploads WordPress converts to JPEG while making
 * sizes, and sizes an image editor copied GPS into from an original that
 * reached the library some other way. Runs after the original-deletion
 * filter (priority 10), so a deleted original is skipped.
 *
 * Non-JPEGs are skipped by extension and mime type without being read.
 * Already-clean JPEGs are read once and never written, so a second pass is
 * cheap and changes nothing.
 *
 * @param array  $metadata      Attachment metadata.
 * @param int    $attachment_id Attachment ID.
 * @param string $context       "create" or "update".
 * @return array Metadata, unchanged.
 */
function notfortraining_strip_gps_generated_files( $metadata, $attachment_id, $context = 'create' ) {
	unset( $context );
	if ( is_array( $metadata ) && notfortraining_get_option( 'strip_gps' ) ) {
		notfortraining_strip_gps_attachment_files( $attachment_id, $metadata );
	}
	return $metadata;
}

/**
 * Strip GPS from every JPEG file of an attachment.
 *
 * @param int   $attachment_id Attachment ID.
 * @param array $metadata      Attachment metadata.
 * @return array<string, string> Absolute path => "stripped", "none" or "error".
 */
function notfortraining_strip_gps_attachment_files( $attachment_id, array $metadata ) {
	$main = get_attached_file( $attachment_id );
	if ( ! $main ) {
		return array();
	}
	$dir = dirname( $main );

	// path => mime type, when the metadata gives one.
	$files = array( $main => '' );
	if ( ! empty( $metadata['file'] ) && ! path_is_absolute( $metadata['file'] ) ) {
		$uploads = wp_get_upload_dir();
		$files[ path_join( $uploads['basedir'], $metadata['file'] ) ] = '';
	}
	foreach ( (array) ( $metadata['sizes'] ?? array() ) as $size ) {
		if ( ! empty( $size['file'] ) ) {
			$files[ path_join( $dir, wp_basename( $size['file'] ) ) ] = (string) ( $size['mime-type'] ?? '' );
		}
	}
	if ( ! empty( $metadata['original_image'] ) ) {
		$files[ path_join( $dir, wp_basename( $metadata['original_image'] ) ) ] = '';
	}

	$results = array();
	foreach ( $files as $file => $mime ) {
		if ( ! notfortraining_is_jpeg_path( $file, $mime ) || ! is_file( $file ) ) {
			continue;
		}
		$results[ $file ] = notfortraining_strip_gps_jpeg_file( $file );
	}
	return $results;
}

/**
 * Whether a path is a JPEG, by mime type when known, else by extension.
 *
 * @param string $file Path.
 * @param string $mime Mime type from the metadata, or "".
 * @return bool
 */
function notfortraining_is_jpeg_path( $file, $mime = '' ) {
	if ( '' !== $mime ) {
		return 'image/jpeg' === $mime;
	}
	return in_array( strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ), array( 'jpg', 'jpeg', 'jpe' ), true );
}
