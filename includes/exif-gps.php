<?php
/**
 * Remove GPS location from a JPEG in pure PHP, byte by byte, with no image
 * library involved: the image data is never decoded or re-encoded.
 *
 * EXIF: the GPS IFD's entries, their out-of-line values and its next-IFD
 * pointer are zeroed and its entry count set to 0. That leaves an empty but
 * valid GPS IFD at the same offset, so no offset inside the EXIF block
 * moves.
 *
 * XMP: every exif:GPS* property is cut out of the packet, as an attribute
 * or as an element (including rdf:Seq / rdf:Alt values), and the segment
 * length is rewritten. JPEG segments are length-prefixed with no offsets
 * between them, so a shorter XMP segment moves nothing else.
 *
 * Every other EXIF tag (Copyright, Artist, camera data), all other XMP
 * (dc:rights, photoshop:Credit, ...), IPTC and ICC profiles stay byte for
 * byte.
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
	$mode = $mode ? $mode : 0644;

	// Write a sibling temp file, then rename it over the original, so a
	// failed or interrupted write never leaves a half-written image.
	$temp = $file . '.notfortraining-tmp';
	if ( ! $fs->put_contents( $temp, $result['data'], $mode ) ) {
		$fs->delete( $temp );
		return 'error';
	}
	if ( ! $fs->move( $temp, $file, true ) ) {
		$fs->delete( $temp );
		return 'error';
	}
	$fs->chmod( $file, $mode );
	return 'stripped';
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

		$result = 'none';
		if ( 0xE1 === $marker && $seg >= 16 && "Exif\0\0" === substr( $data, $pos + 4, 6 ) ) {
			$result = notfortraining_clear_gps_ifd( $data, $pos + 10, $pos + 2 + $seg );
		} elseif ( 0xE1 === $marker && notfortraining_xmp_header() === substr( $data, $pos + 4, strlen( notfortraining_xmp_header() ) ) ) {
			$result = notfortraining_strip_gps_xmp_segment( $data, $pos, $seg );
			$len    = strlen( $data );
		}

		/*
		 * Extended XMP (APP1 "http://ns.adobe.com/xmp/extension/") is left
		 * alone. It's one slice of a larger packet, identified by an MD5 of
		 * the whole packet and carrying offsets into it, so it can't be
		 * edited slice by slice without rebuilding every slice and the GUID
		 * the main packet points to. Few files have it; cameras and phones
		 * don't write GPS there.
		 */

		if ( 'error' === $result ) {
			$status = 'error';
			break;
		}
		if ( 'stripped' === $result ) {
			$status = 'stripped';
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
 * The identifier at the start of a standard XMP APP1 segment.
 *
 * @return string
 */
function notfortraining_xmp_header() {
	return "http://ns.adobe.com/xap/1.0/\0";
}

/**
 * Strip GPS from the XMP APP1 segment at $pos, in place, rewriting its
 * length.
 *
 * @param string $data JPEG bytes, modified in place.
 * @param int    $pos  Offset of the segment's 0xFF marker byte.
 * @param int    $seg  Segment length (as stored: includes the 2 length
 *                     bytes); updated to the new length.
 * @return string "stripped", "none" or "error".
 */
function notfortraining_strip_gps_xmp_segment( &$data, $pos, &$seg ) {
	$header = notfortraining_xmp_header();
	$start  = $pos + 4 + strlen( $header );
	$packet = substr( $data, $start, $seg - 2 - strlen( $header ) );

	$result = notfortraining_strip_gps_xmp( $packet );
	if ( 'stripped' !== $result['status'] ) {
		return $result['status'];
	}

	$body = $header . $result['packet'];
	if ( strlen( $body ) + 2 > 0xFFFF ) {
		return 'error';
	}

	$data = substr( $data, 0, $pos + 2 ) . pack( 'n', strlen( $body ) + 2 ) . $body . substr( $data, $pos + 2 + $seg );
	$seg  = strlen( $body ) + 2;
	return 'stripped';
}

/**
 * Remove every exif:GPS* property from an XMP packet.
 *
 * Element form, with or without content, including rdf:Seq / rdf:Alt
 * values, and any whitespace before it:
 *     <exif:GPSLatitude>45,31.2N</exif:GPSLatitude>
 *     <exif:GPSAreaInformation><rdf:Alt>...</rdf:Alt></exif:GPSAreaInformation>
 * Attribute form, with the whitespace before it, inside start tags only:
 *     exif:GPSLatitude="45,31.2N"
 *
 * Everything else in the packet stays byte for byte.
 *
 * @param string $packet XMP packet.
 * @return array{status: string, packet: string} Status "stripped", "none"
 *               or "error"; packet unchanged unless stripped.
 */
function notfortraining_strip_gps_xmp( $packet ) {
	$packet = (string) $packet;
	if ( false === strpos( $packet, 'exif:GPS' ) ) {
		return array(
			'status' => 'none',
			'packet' => $packet,
		);
	}

	// One attribute: name="value" or name='value'. Values may hold ">".
	$attribute = '\s+[^\s=\/>]+\s*=\s*(?:"[^"]*"|\'[^\']*\')';

	// Elements first: their start tags can hold GPS attributes too.
	$new = preg_replace(
		'/\s*<(exif:GPS[A-Za-z0-9]*)(?:' . $attribute . ')*\s*(?:\/>|>.*?<\/\1\s*>)/s',
		'',
		$packet
	);

	// Then attributes, matched only inside start tags.
	if ( null !== $new ) {
		$new = preg_replace_callback(
			'/<[A-Za-z_][^\s\/>]*(?:' . $attribute . ')*\s*\/?>/',
			static function ( $tag ) {
				return preg_replace( '/\s+exif:GPS[A-Za-z0-9]*\s*=\s*(?:"[^"]*"|\'[^\']*\')/', '', $tag[0] );
			},
			$new
		);
	}

	if ( null === $new ) {
		return array(
			'status' => 'error',
			'packet' => $packet,
		);
	}

	return array(
		'status' => $new === $packet ? 'none' : 'stripped',
		'packet' => $new,
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
