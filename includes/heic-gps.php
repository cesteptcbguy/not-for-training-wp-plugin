<?php
/**
 * Neutralize GPS location in HEIC/HEIF files in place, without changing the
 * file's length or any box size.
 *
 * WordPress keeps an uploaded .heic as original_image after converting it to
 * JPEG, at a public URL. This finds the file's metadata the way a HEIF reader
 * does: the top-level meta box, its item info (iinf) for the Exif item and
 * any XMP item (mime, application/rdf+xml), and its item locations (iloc)
 * for where their bytes are. Nothing is found by scanning the file for
 * "Exif".
 *
 * Exif item: the GPS IFD is emptied exactly as in a JPEG (see
 * notfortraining_clear_gps_ifd()): every GPS value, inline or at an offset,
 * is zeroed, and so are the entries and the entry count, leaving a valid,
 * empty GPS IFD. Zeroing only the values would leave the tags in place,
 * reading as latitude 0, longitude 0: readers would still say the photo has
 * a location (in the Gulf of Guinea) and Preview would show a map pin. An
 * empty IFD reads as no location at all, and IFD0's GPSInfo pointer still
 * points at a valid structure.
 *
 * XMP item: each exif:GPS* property is overwritten with spaces, which is
 * still well-formed XML and keeps the item's length.
 *
 * Anything unexpected (truncated boxes, sizes that don't add up, more than
 * one extent, external data references, protected items) leaves the file
 * untouched.
 *
 * @package NotForTraining
 */

defined( 'ABSPATH' ) || exit;

/**
 * Neutralize GPS in a HEIC/HEIF file, in place.
 *
 * @param string $file Absolute path.
 * @return string "stripped", "none" (not HEIF, or no GPS) or "error".
 */
function notfortraining_strip_gps_heic_file( $file ) {
	$fs = notfortraining_filesystem();
	if ( ! $fs->is_file( $file ) || ! $fs->is_writable( $file ) ) {
		return 'error';
	}
	$data = $fs->get_contents( $file );
	if ( ! is_string( $data ) ) {
		return 'error';
	}

	$result = notfortraining_strip_gps_heic_data( $data );
	if ( 'stripped' !== $result['status'] ) {
		return $result['status'];
	}
	return notfortraining_write_file_atomic( $file, $result['data'] ) ? 'stripped' : 'error';
}

/**
 * Neutralize GPS in HEIC/HEIF bytes.
 *
 * @param string $data File contents.
 * @return array{status: string, data: string} Status "stripped", "none" or
 *               "error"; data is the new bytes (unchanged unless stripped,
 *               and always the same length).
 */
function notfortraining_strip_gps_heic_data( $data ) {
	$data     = (string) $data;
	$original = $data;
	$fail     = array(
		'status' => 'error',
		'data'   => $original,
	);

	$items = notfortraining_heic_metadata_items( $data );
	if ( null === $items ) {
		return $fail;
	}

	$status = 'none';
	foreach ( $items as $item ) {
		if ( 'Exif' === $item['type'] ) {
			if ( $item['end'] - $item['start'] < 4 ) {
				return $fail;
			}
			$tiff   = $item['start'] + 4 + notfortraining_read_uint( $data, $item['start'], 4, false );
			$result = ( $tiff + 8 <= $item['end'] ) ? notfortraining_clear_gps_ifd( $data, $tiff, $item['end'] ) : 'error';
		} else {
			$length = $item['end'] - $item['start'];
			$xmp    = notfortraining_blank_gps_xmp( substr( $data, $item['start'], $length ) );
			$result = $xmp['status'];
			if ( 'stripped' === $result ) {
				$data = substr_replace( $data, $xmp['packet'], $item['start'], $length );
			}
		}

		if ( 'error' === $result ) {
			return $fail;
		}
		if ( 'stripped' === $result ) {
			$status = 'stripped';
		}
	}

	if ( strlen( $data ) !== strlen( $original ) ) {
		return $fail;
	}

	return array(
		'status' => $status,
		'data'   => $data,
	);
}

/**
 * Overwrite every exif:GPS* property in an XMP packet with spaces, keeping
 * its length. Same matching rules as notfortraining_strip_gps_xmp().
 *
 * @param string $packet XMP packet.
 * @return array{status: string, packet: string}
 */
function notfortraining_blank_gps_xmp( $packet ) {
	$result = notfortraining_strip_gps_xmp( $packet, true );
	if ( strlen( $result['packet'] ) !== strlen( (string) $packet ) ) {
		return array(
			'status' => 'error',
			'packet' => (string) $packet,
		);
	}
	return $result;
}

/**
 * Find the Exif and XMP items of a HEIF file and where their bytes are.
 *
 * @param string $data File contents.
 * @return array<int, array{type: string, start: int, end: int}>|null Items
 *         ("Exif" or "xmp"), empty when the file isn't HEIF or has neither,
 *         null when the structure is malformed.
 */
function notfortraining_heic_metadata_items( $data ) {
	$len = strlen( $data );
	$top = notfortraining_isobmff_boxes( $data, 0, $len );
	if ( null === $top ) {
		return notfortraining_is_heif_ftyp( $data ) ? null : array();
	}
	if ( ! notfortraining_is_heif_ftyp( $data ) ) {
		return array();
	}

	$meta = notfortraining_isobmff_find( $top, 'meta' );
	if ( null === $meta ) {
		return array();
	}
	if ( $meta['start'] + 4 > $meta['end'] ) {
		return null;
	}
	// meta is a FullBox: version and flags come before its children.
	$children = notfortraining_isobmff_boxes( $data, $meta['start'] + 4, $meta['end'] );
	if ( null === $children ) {
		return null;
	}

	$iinf = notfortraining_isobmff_find( $children, 'iinf' );
	$iloc = notfortraining_isobmff_find( $children, 'iloc' );
	if ( null === $iinf || null === $iloc ) {
		return array();
	}

	$wanted = notfortraining_heic_parse_iinf( $data, $iinf );
	if ( null === $wanted ) {
		return null;
	}
	if ( ! $wanted ) {
		return array();
	}

	$locations = notfortraining_heic_parse_iloc( $data, $iloc );
	if ( null === $locations ) {
		return null;
	}
	$idat = notfortraining_isobmff_find( $children, 'idat' );

	$items = array();
	foreach ( $wanted as $id => $type ) {
		if ( ! isset( $locations[ $id ] ) ) {
			return null;
		}
		$loc = $locations[ $id ];
		if ( 0 === $loc['method'] ) {
			$start = $loc['offset'];
		} elseif ( 1 === $loc['method'] && null !== $idat ) {
			$start = $idat['start'] + $loc['offset'];
		} else {
			return null;
		}
		$end   = $start + $loc['length'];
		$limit = 1 === $loc['method'] ? $idat['end'] : $len;
		if ( $loc['length'] <= 0 || $start < 0 || $end > $limit ) {
			return null;
		}
		$items[] = array(
			'type'  => $type,
			'start' => $start,
			'end'   => $end,
		);
	}
	return $items;
}

/**
 * Whether the file starts with an ftyp box naming a HEIF brand.
 *
 * @param string $data File contents.
 * @return bool
 */
function notfortraining_is_heif_ftyp( $data ) {
	if ( strlen( $data ) < 16 || 'ftyp' !== substr( $data, 4, 4 ) ) {
		return false;
	}
	$size = notfortraining_read_uint( $data, 0, 4, false );
	if ( $size < 16 || $size > strlen( $data ) || 0 !== $size % 4 ) {
		return false;
	}
	$heif   = array( 'heic', 'heix', 'heim', 'heis', 'hevc', 'hevx', 'mif1', 'msf1' );
	$brands = array( substr( $data, 8, 4 ) );
	for ( $i = 16; $i + 4 <= $size; $i += 4 ) {
		$brands[] = substr( $data, $i, 4 );
	}
	return (bool) array_intersect( $brands, $heif );
}

/**
 * List the boxes between $start and $end.
 *
 * @param string $data  File contents.
 * @param int    $start First box offset.
 * @param int    $end   End of the enclosing range.
 * @return array<int, array{type: string, start: int, end: int}>|null Each
 *         box's type and content range (after its header), or null if a box
 *         doesn't fit exactly.
 */
function notfortraining_isobmff_boxes( $data, $start, $end ) {
	$boxes = array();
	$pos   = $start;
	while ( $pos < $end ) {
		if ( $pos + 8 > $end ) {
			return null;
		}
		$size   = notfortraining_read_uint( $data, $pos, 4, false );
		$type   = substr( $data, $pos + 4, 4 );
		$header = 8;
		if ( 1 === $size ) {
			$size   = notfortraining_read_be( $data, $pos + 8, 8 );
			$header = 16;
		} elseif ( 0 === $size ) {
			$size = $end - $pos; // Extends to the end of the enclosing range.
		}
		if ( $size < $header || $pos + $size > $end ) {
			return null;
		}
		if ( 'uuid' === $type ) {
			$header += 16;
			if ( $size < $header ) {
				return null;
			}
		}
		$boxes[] = array(
			'type'  => $type,
			'start' => $pos + $header,
			'end'   => $pos + $size,
		);
		$pos    += $size;
	}
	return $boxes;
}

/**
 * The only box of a type in a list, or null if there's none or several.
 *
 * @param array  $boxes Boxes from notfortraining_isobmff_boxes().
 * @param string $type  Box type.
 * @return array{type: string, start: int, end: int}|null
 */
function notfortraining_isobmff_find( array $boxes, $type ) {
	$found = array_values(
		array_filter(
			$boxes,
			static function ( $box ) use ( $type ) {
				return $type === $box['type'];
			}
		)
	);
	return 1 === count( $found ) ? $found[0] : null;
}

/**
 * Item IDs of the Exif item and any uncompressed XMP item, from iinf.
 *
 * @param string $data File contents.
 * @param array  $iinf The iinf box.
 * @return array<int, string>|null Item ID => "Exif" or "xmp"; null if malformed.
 */
function notfortraining_heic_parse_iinf( $data, array $iinf ) {
	$pos = $iinf['start'];
	if ( $pos + 4 > $iinf['end'] ) {
		return null;
	}
	$count_size = 0 === ord( $data[ $pos ] ) ? 2 : 4;
	$pos       += 4;
	if ( $pos + $count_size > $iinf['end'] ) {
		return null;
	}
	$count   = notfortraining_read_uint( $data, $pos, $count_size, false );
	$entries = notfortraining_isobmff_boxes( $data, $pos + $count_size, $iinf['end'] );
	if ( null === $entries || count( $entries ) !== $count ) {
		return null;
	}

	$wanted = array();
	foreach ( $entries as $infe ) {
		if ( 'infe' !== $infe['type'] || $infe['start'] + 4 > $infe['end'] ) {
			return null;
		}
		$version = ord( $data[ $infe['start'] ] );
		if ( $version < 2 ) {
			continue; // Versions 0 and 1 have no item type, so can't be Exif.
		}
		$pos     = $infe['start'] + 4;
		$id_size = 2 === $version ? 2 : 4;
		if ( $pos + $id_size + 6 > $infe['end'] ) {
			return null;
		}
		$id         = notfortraining_read_uint( $data, $pos, $id_size, false );
		$protection = notfortraining_read_uint( $data, $pos + $id_size, 2, false );
		$type       = substr( $data, $pos + $id_size + 2, 4 );

		if ( 'Exif' === $type ) {
			if ( 0 !== $protection || isset( $wanted[ $id ] ) ) {
				return null;
			}
			$wanted[ $id ] = 'Exif';
		} elseif ( 'mime' === $type && 0 === $protection ) {
			// item_name, content_type, then an optional content_encoding.
			$strings = explode( "\0", substr( $data, $pos + $id_size + 6, $infe['end'] - ( $pos + $id_size + 6 ) ) );
			$content = $strings[1] ?? '';
			$encoded = $strings[2] ?? '';
			if ( 'application/rdf+xml' === strtolower( $content ) && '' === $encoded ) {
				$wanted[ $id ] = 'xmp';
			}
		}
	}
	return $wanted;
}

/**
 * Item locations from iloc.
 *
 * @param string $data File contents.
 * @param array  $iloc The iloc box.
 * @return array<int, array{method: int, offset: int, length: int}>|null Item
 *         ID => where its single extent is; null if malformed. Items with
 *         several extents or external data come back with method -1, so
 *         they're refused if they're the ones we need.
 */
function notfortraining_heic_parse_iloc( $data, array $iloc ) {
	$pos     = $iloc['start'];
	$end     = $iloc['end'];
	$version = $pos < $end ? ord( $data[ $pos ] ) : -1;
	if ( $version < 0 || $version > 2 || $pos + 6 > $end ) {
		return null;
	}
	$sizes       = ord( $data[ $pos + 4 ] );
	$sizes2      = ord( $data[ $pos + 5 ] );
	$offset_size = $sizes >> 4;
	$length_size = $sizes & 0x0F;
	$base_size   = $sizes2 >> 4;
	$index_size  = $version > 0 ? $sizes2 & 0x0F : 0;
	foreach ( array( $offset_size, $length_size, $base_size, $index_size ) as $field_size ) {
		if ( ! in_array( $field_size, array( 0, 4, 8 ), true ) ) {
			return null;
		}
	}

	$pos       += 6;
	$count_size = $version < 2 ? 2 : 4;
	if ( $pos + $count_size > $end ) {
		return null;
	}
	$count = notfortraining_read_uint( $data, $pos, $count_size, false );
	$pos  += $count_size;

	$locations = array();
	for ( $i = 0; $i < $count; $i++ ) {
		$id_size = $version < 2 ? 2 : 4;
		$fixed   = $id_size + ( $version > 0 ? 2 : 0 ) + 2 + $base_size + 2;
		if ( $pos + $fixed > $end ) {
			return null;
		}
		$id   = notfortraining_read_uint( $data, $pos, $id_size, false );
		$pos += $id_size;

		$method = 0;
		if ( $version > 0 ) {
			$method = notfortraining_read_uint( $data, $pos, 2, false ) & 0x0F;
			$pos   += 2;
		}
		$reference = notfortraining_read_uint( $data, $pos, 2, false );
		$base      = notfortraining_read_be( $data, $pos + 2, $base_size );
		$extents   = notfortraining_read_uint( $data, $pos + 2 + $base_size, 2, false );
		$pos      += 2 + $base_size + 2;

		$extent_bytes = $index_size + $offset_size + $length_size;
		if ( $pos + $extents * $extent_bytes > $end ) {
			return null;
		}
		$offset = 0;
		$length = 0;
		if ( 1 === $extents ) {
			$offset = notfortraining_read_be( $data, $pos + $index_size, $offset_size );
			$length = notfortraining_read_be( $data, $pos + $index_size + $offset_size, $length_size );
		}
		$pos += $extents * $extent_bytes;

		if ( $base < 0 || $offset < 0 || $length < 0 || isset( $locations[ $id ] ) ) {
			return null;
		}
		$locations[ $id ] = array(
			'method' => ( 1 === $extents && 0 === $reference ) ? $method : -1,
			'offset' => $base + $offset,
			'length' => $length,
		);
	}
	return $locations;
}

/**
 * Read a big-endian unsigned integer of 0, 2, 4 or 8 bytes. 8-byte values
 * over 4 GB (beyond any upload) come back as -1.
 *
 * @param string $data   Bytes.
 * @param int    $offset Offset.
 * @param int    $size   0, 2, 4 or 8.
 * @return int
 */
function notfortraining_read_be( $data, $offset, $size ) {
	if ( 0 === $size ) {
		return 0;
	}
	if ( 8 === $size ) {
		if ( $offset + 8 > strlen( $data ) || 0 !== notfortraining_read_uint( $data, $offset, 4, false ) ) {
			return -1;
		}
		return notfortraining_read_uint( $data, $offset + 4, 4, false );
	}
	return notfortraining_read_uint( $data, $offset, $size, false );
}
