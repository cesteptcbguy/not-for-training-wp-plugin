<?php
/**
 * Writes the JPEG fixtures in tests/fixtures/. Run with plain PHP and GD:
 *
 *     Run: php tests/bin/make-fixtures.php
 *
 * Each fixture is an 8x8 GD JPEG with an Exif APP1 segment (Make, Artist,
 * Copyright, and in the gps-* files a GPS IFD with latitude, longitude and
 * altitude) and an IPTC APP13 segment (By-line, Copyright Notice), in
 * little-endian (II) and big-endian (MM) byte order. plain.jpg has no
 * metadata at all.
 *
 * @package NotForTraining
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$notfortraining_dir = dirname( __DIR__ ) . '/fixtures';

/**
 * Pack an unsigned integer.
 *
 * @param int  $value Value.
 * @param int  $size  2 or 4 bytes.
 * @param bool $le    Little-endian.
 * @return string
 */
function notfortraining_fx_pack( $value, $size, $le ) {
	if ( 2 === $size ) {
		return pack( $le ? 'v' : 'n', $value );
	}
	return pack( $le ? 'V' : 'N', $value );
}

/**
 * Build one IFD and its out-of-line value area.
 *
 * @param array $entries List of array( tag, type, count, raw value bytes ).
 * @param int   $offset  Offset of this IFD from the TIFF header.
 * @param bool  $le      Little-endian.
 * @return string IFD bytes followed by its value bytes.
 */
function notfortraining_fx_ifd( array $entries, $offset, $le ) {
	$ifd_size = 2 + 12 * count( $entries ) + 4;
	$values   = '';
	$ifd      = notfortraining_fx_pack( count( $entries ), 2, $le );
	foreach ( $entries as $entry ) {
		list( $tag, $type, $count, $raw ) = $entry;

		$ifd .= notfortraining_fx_pack( $tag, 2, $le ) . notfortraining_fx_pack( $type, 2, $le ) . notfortraining_fx_pack( $count, 4, $le );
		if ( strlen( $raw ) <= 4 ) {
			$ifd .= str_pad( $raw, 4, "\0" );
		} else {
			$ifd    .= notfortraining_fx_pack( $offset + $ifd_size + strlen( $values ), 4, $le );
			$values .= $raw;
			if ( strlen( $values ) % 2 ) {
				$values .= "\0";
			}
		}
	}
	$ifd .= notfortraining_fx_pack( 0, 4, $le ); // No next IFD.
	return $ifd . $values;
}

/**
 * RATIONAL values.
 *
 * @param array $pairs List of array( numerator, denominator ).
 * @param bool  $le    Little-endian.
 * @return string
 */
function notfortraining_fx_rationals( array $pairs, $le ) {
	$out = '';
	foreach ( $pairs as $pair ) {
		$out .= notfortraining_fx_pack( $pair[0], 4, $le ) . notfortraining_fx_pack( $pair[1], 4, $le );
	}
	return $out;
}

/**
 * The Exif APP1 segment.
 *
 * @param bool $le       Little-endian.
 * @param bool $with_gps Include a GPS IFD.
 * @return string
 */
function notfortraining_fx_exif_segment( $le, $with_gps ) {
	$make      = "TestCam\0";
	$artist    = "Jane Doe\0";
	$copyright = "(c) 2026 Jane Doe. All rights reserved.\0";

	$ifd0 = array(
		array( 0x010F, 2, strlen( $make ), $make ),
		array( 0x013B, 2, strlen( $artist ), $artist ),
		array( 0x8298, 2, strlen( $copyright ), $copyright ),
	);

	// IFD0 size is known before its GPS pointer value, so lay it out once
	// with a placeholder to find where the GPS IFD starts.
	if ( $with_gps ) {
		$ifd0[] = array( 0x8825, 4, 1, notfortraining_fx_pack( 0, 4, $le ) );
	}
	$gps_offset = 8 + strlen( notfortraining_fx_ifd( $ifd0, 8, $le ) );

	if ( $with_gps ) {
		$ifd0[ count( $ifd0 ) - 1 ][3] = notfortraining_fx_pack( $gps_offset, 4, $le );
	}
	$tiff  = ( $le ? 'II' : 'MM' ) . notfortraining_fx_pack( 42, 2, $le ) . notfortraining_fx_pack( 8, 4, $le );
	$tiff .= notfortraining_fx_ifd( $ifd0, 8, $le );

	if ( $with_gps ) {
		$gps   = array(
			array( 0x0000, 1, 4, "\x02\x03\x00\x00" ),
			array( 0x0001, 2, 2, "N\0" ),
			array( 0x0002, 5, 3, notfortraining_fx_rationals( array( array( 45, 1 ), array( 31, 1 ), array( 1234, 100 ) ), $le ) ),
			array( 0x0003, 2, 2, "W\0" ),
			array( 0x0004, 5, 3, notfortraining_fx_rationals( array( array( 122, 1 ), array( 40, 1 ), array( 5678, 100 ) ), $le ) ),
			array( 0x0006, 5, 1, notfortraining_fx_rationals( array( array( 15, 1 ) ), $le ) ),
		);
		$tiff .= notfortraining_fx_ifd( $gps, $gps_offset, $le );
	}

	$body = "Exif\0\0" . $tiff;
	return "\xFF\xE1" . pack( 'n', strlen( $body ) + 2 ) . $body;
}

/**
 * The IPTC APP13 segment (Photoshop 3.0 resource 0x0404).
 *
 * @return string
 */
function notfortraining_fx_iptc_segment() {
	$iptc = "\x1C\x02\x00\x00\x02\x00\x04";
	foreach ( array(
		80  => 'Jane Doe',
		116 => 'Copyright 2026 Jane Doe',
	) as $dataset => $value ) {
		$iptc .= "\x1C\x02" . chr( $dataset ) . pack( 'n', strlen( $value ) ) . $value;
	}
	if ( strlen( $iptc ) % 2 ) {
		$iptc .= "\0";
	}
	$body = "Photoshop 3.0\x008BIM\x04\x04\x00\x00" . pack( 'N', strlen( $iptc ) ) . $iptc;
	return "\xFF\xED" . pack( 'n', strlen( $body ) + 2 ) . $body;
}

$notfortraining_image = imagecreatetruecolor( 8, 8 );
imagefilledrectangle( $notfortraining_image, 0, 0, 7, 7, imagecolorallocate( $notfortraining_image, 200, 120, 40 ) );
ob_start();
imagejpeg( $notfortraining_image, null, 90 );
$notfortraining_plain = ob_get_clean();

// GD writes SOI then a JFIF APP0; the metadata goes after APP0.
$notfortraining_app0_end = 4 + unpack( 'n', substr( $notfortraining_plain, 4, 2 ) )[1];
$notfortraining_head     = substr( $notfortraining_plain, 0, $notfortraining_app0_end );
$notfortraining_tail     = substr( $notfortraining_plain, $notfortraining_app0_end );

file_put_contents( $notfortraining_dir . '/plain.jpg', $notfortraining_plain );
foreach ( array(
	'gps-le.jpg'   => array( true, true ),
	'gps-be.jpg'   => array( false, true ),
	'nogps-le.jpg' => array( true, false ),
	'nogps-be.jpg' => array( false, false ),
) as $notfortraining_name => $notfortraining_args ) {
	file_put_contents(
		$notfortraining_dir . '/' . $notfortraining_name,
		$notfortraining_head . notfortraining_fx_exif_segment( $notfortraining_args[0], $notfortraining_args[1] ) . notfortraining_fx_iptc_segment() . $notfortraining_tail
	);
	echo $notfortraining_name, "\n";
}
