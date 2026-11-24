<?php
/**
 * Remove EXIF GPS location from a JPEG in pure PHP, byte by byte, with no
 * image library involved: the image data is never decoded or re-encoded.
 *
 * The GPS IFD's entries, their out-of-line values and its next-IFD pointer
 * are zeroed and its entry count set to 0. That leaves an empty but valid
 * GPS IFD at the same offset, and the file the same length, so no other
 * offset in the file moves. Every other tag (Copyright, Artist, camera
 * data), IPTC, XMP and ICC profiles stay byte for byte.
 *
 * @package NotForTraining
 */

defined( 'ABSPATH' ) || exit;

/**
 * Strip GPS from a JPEG file, in place.
 *
 * @param string $file Absolute path.
 * @return string "stripped", "none" (not a JPEG, or no GPS) or "error".
 */
function notfortraining_strip_gps_jpeg_file( $file ) {
	$fs = notfortraining_filesystem();
	if ( ! $fs || ! $fs->is_file( $file ) || ! $fs->is_writable( $file ) ) {
		return 'error';
	}

	$data = $fs->get_contents( $file );
	if ( ! is_string( $data ) ) {
		return 'error';
	}

	$result = notfortraining_strip_gps_jpeg_data( $data );
	if ( 'stripped' !== $result['status'] ) {
		return $result['status'];
	}

	// Keep the file's own permissions. Passing them also avoids FS_CHMOD_FILE,
	// which isn't defined outside wp-admin.
	$mode = octdec( $fs->getchmod( $file ) );

	return $fs->put_contents( $file, $result['data'], $mode ? $mode : 0644 ) ? 'stripped' : 'error';
}

/**
 * A direct filesystem handle. Uploads were just written by PHP itself, so
 * direct access is the right method whatever FS_METHOD says for updates.
 *
 * @return WP_Filesystem_Direct|null
 */
function notfortraining_filesystem() {
	static $fs = null;
	if ( null === $fs ) {
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
		$fs = new WP_Filesystem_Direct( null );
	}
	return $fs;
}

/**
 * Strip GPS from JPEG bytes.
 *
 * @param string $data JPEG file contents.
 * @return array{status: string, data: string} Status "stripped", "none" or
 *               "error"; data is the new bytes (unchanged unless stripped).
 */
function notfortraining_strip_gps_jpeg_data( $data ) {
	$data     = (string) $data;
	$original = $data;
	$len      = strlen( $data );
	if ( $len < 4 || "\xFF\xD8" !== substr( $data, 0, 2 ) ) {
		return array(
			'status' => 'none',
			'data'   => $data,
		);
	}

	$status = 'none';
	$pos    = 2;
	while ( $pos + 4 <= $len && "\xFF" === $data[ $pos ] ) {
		$marker = ord( $data[ $pos + 1 ] );
		if ( 0xFF === $marker ) {
			++$pos; // Fill byte before a marker.
			continue;
		}
		if ( 0xDA === $marker || 0xD9 === $marker ) {
			break; // Start of scan or end of image: no more metadata segments.
		}
		if ( 0x01 === $marker || ( $marker >= 0xD0 && $marker <= 0xD7 ) ) {
			$pos += 2; // Standalone marker, no length.
			continue;
		}

		$seg = notfortraining_read_uint( $data, $pos + 2, 2, false );
		if ( $seg < 2 || $pos + 2 + $seg > $len ) {
			$status = 'error';
			break;
		}

		if ( 0xE1 === $marker && $seg >= 16 && "Exif\0\0" === substr( $data, $pos + 4, 6 ) ) {
			$result = notfortraining_clear_gps_ifd( $data, $pos + 10, $pos + 2 + $seg );
			if ( 'error' === $result ) {
				$status = 'error';
				break;
			}
			if ( 'stripped' === $result ) {
				$status = 'stripped';
			}
		}

		$pos += 2 + $seg;
	}

	// Never write a half-processed file.
	if ( 'error' === $status ) {
		return array(
			'status' => 'error',
			'data'   => $original,
		);
	}

	return array(
		'status' => $status,
		'data'   => $data,
	);
}

/**
 * Empty the GPS IFD of one TIFF structure (the body of an Exif APP1
 * segment), in place.
 *
 * @param string $data JPEG bytes, modified in place.
 * @param int    $tiff Offset of the TIFF header.
 * @param int    $end  Offset just past the APP1 segment.
 * @return string "stripped", "none" or "error".
 */
function notfortraining_clear_gps_ifd( &$data, $tiff, $end ) {
	if ( $tiff + 8 > $end ) {
		return 'error';
	}

	$order = substr( $data, $tiff, 2 );
	if ( 'II' === $order ) {
		$le = true;
	} elseif ( 'MM' === $order ) {
		$le = false;
	} else {
		return 'error';
	}
	if ( 42 !== notfortraining_read_uint( $data, $tiff + 2, 2, $le ) ) {
		return 'error';
	}

	// IFD0: find the GPSInfo pointer (tag 0x8825).
	$ifd0 = $tiff + notfortraining_read_uint( $data, $tiff + 4, 4, $le );
	if ( $ifd0 < $tiff + 8 || $ifd0 + 2 > $end ) {
		return 'error';
	}
	$count = notfortraining_read_uint( $data, $ifd0, 2, $le );
	if ( $ifd0 + 2 + 12 * $count > $end ) {
		return 'error';
	}
	$gps = 0;
	for ( $i = 0; $i < $count; $i++ ) {
		$entry = $ifd0 + 2 + 12 * $i;
		if ( 0x8825 === notfortraining_read_uint( $data, $entry, 2, $le ) ) {
			$gps = $tiff + notfortraining_read_uint( $data, $entry + 8, 4, $le );
			break;
		}
	}
	if ( ! $gps ) {
		return 'none';
	}
	if ( $gps < $tiff + 8 || $gps + 2 > $end ) {
		return 'error';
	}

	$count = notfortraining_read_uint( $data, $gps, 2, $le );
	if ( 0 === $count ) {
		return 'none'; // Already empty.
	}
	$ifd_bytes = 2 + 12 * $count + 4;
	if ( $gps + $ifd_bytes > $end ) {
		return 'error';
	}

	// Bytes per component, by TIFF field type.
	$sizes = array(
		1  => 1, // BYTE.
		2  => 1, // ASCII.
		3  => 2, // SHORT.
		4  => 4, // LONG.
		5  => 8, // RATIONAL.
		6  => 1, // SBYTE.
		7  => 1, // UNDEFINED.
		8  => 2, // SSHORT.
		9  => 4, // SLONG.
		10 => 8, // SRATIONAL.
		11 => 4, // FLOAT.
		12 => 8, // DOUBLE.
	);

	// Values over 4 bytes live outside the entry: zero them first.
	for ( $i = 0; $i < $count; $i++ ) {
		$entry      = $gps + 2 + 12 * $i;
		$type       = notfortraining_read_uint( $data, $entry + 2, 2, $le );
		$components = notfortraining_read_uint( $data, $entry + 4, 4, $le );
		$bytes      = ( $sizes[ $type ] ?? 1 ) * $components;
		if ( $bytes > 4 ) {
			$offset = $tiff + notfortraining_read_uint( $data, $entry + 8, 4, $le );
			if ( $offset >= $tiff + 8 && $offset + $bytes <= $end ) {
				$data = substr_replace( $data, str_repeat( "\0", $bytes ), $offset, $bytes );
			}
		}
	}

	// Entry count 0, entries and next-IFD pointer zeroed: an empty, valid GPS IFD.
	$data = substr_replace( $data, str_repeat( "\0", $ifd_bytes ), $gps, $ifd_bytes );

	return 'stripped';
}

/**
 * Read an unsigned 2- or 4-byte integer. Reads past the end return 0.
 *
 * @param string $data   Bytes.
 * @param int    $offset Offset.
 * @param int    $size   2 or 4.
 * @param bool   $le     Little-endian.
 * @return int
 */
function notfortraining_read_uint( $data, $offset, $size, $le ) {
	if ( $offset < 0 || $offset + $size > strlen( $data ) ) {
		return 0;
	}
	if ( 2 === $size ) {
		$format = $le ? 'v' : 'n';
	} else {
		$format = $le ? 'V' : 'N';
	}
	$value = unpack( $format, substr( $data, $offset, $size ) );
	return (int) $value[1];
}
