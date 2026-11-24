<?php
/**
 * GPS stripping on the fixture JPEGs.
 *
 * @package NotForTraining
 */

/**
 * Tests for the byte-level GPS stripper and the upload hook.
 */
class NotForTraining_Test_Gps extends NotForTraining_TestCase {

	/**
	 * Temp copies to delete.
	 *
	 * @var string[]
	 */
	private $temp_files = array();

	/**
	 * Tear down.
	 */
	public function tear_down() {
		foreach ( $this->temp_files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
		parent::tear_down();
	}

	/**
	 * Copy a fixture to a temp file.
	 *
	 * @param string $name Fixture name.
	 * @return string Temp path.
	 */
	private function copy_fixture( $name ) {
		$file = wp_tempnam( $name );
		copy( __DIR__ . '/fixtures/' . $name, $file );
		$this->temp_files[] = $file;
		return $file;
	}

	/**
	 * EXIF sections of a file, warnings included as failures.
	 *
	 * @param string $file Path.
	 * @return array
	 */
	private function exif( $file ) {
		$exif = exif_read_data( $file, null, true );
		$this->assertIsArray( $exif );
		return $exif;
	}

	/**
	 * IPTC datasets of a file.
	 *
	 * @param string $file Path.
	 * @return array
	 */
	private function iptc( $file ) {
		getimagesize( $file, $info );
		$this->assertArrayHasKey( 'APP13', $info );
		return iptcparse( $info['APP13'] );
	}

	/**
	 * Byte order data provider.
	 *
	 * @return array
	 */
	public function byte_orders() {
		return array(
			'little-endian (II)' => array( 'le', true ),
			'big-endian (MM)'    => array( 'be', false ),
		);
	}

	/**
	 * GPS goes; Copyright, Artist, Make and IPTC stay; the file is the same
	 * length and still a valid JPEG.
	 *
	 * @dataProvider byte_orders
	 *
	 * @param string $order Fixture suffix.
	 * @param bool   $le    Little-endian.
	 */
	public function test_gps_removed_copyright_kept( $order, $le ) {
		$this->assertTrue( function_exists( 'exif_read_data' ), 'The exif extension is needed for this test.' );

		$file   = $this->copy_fixture( "gps-$order.jpg" );
		$before = $this->exif( $file );
		$size   = filesize( $file );
		$latlon = pack( $le ? 'VVVVVV' : 'NNNNNN', 45, 1, 31, 1, 1234, 100 );

		$this->assertSame( array( '45/1', '31/1', '1234/100' ), $before['GPS']['GPSLatitude'] );
		$this->assertStringContainsString( $latlon, file_get_contents( $file ) );

		$this->assertSame( 'stripped', notfortraining_strip_gps_jpeg_file( $file ) );

		clearstatcache();
		$after = $this->exif( $file );
		$this->assertSame( $size, filesize( $file ) );
		$this->assertStringNotContainsString( $latlon, file_get_contents( $file ) );
		foreach ( array( 'GPSLatitude', 'GPSLatitudeRef', 'GPSLongitude', 'GPSLongitudeRef', 'GPSAltitude', 'GPSVersion' ) as $tag ) {
			$this->assertArrayNotHasKey( $tag, $after['GPS'] ?? array(), $tag );
		}

		$this->assertSame( '(c) 2026 Jane Doe. All rights reserved.', $after['IFD0']['Copyright'] );
		$this->assertSame( 'Jane Doe', $after['IFD0']['Artist'] );
		$this->assertSame( 'TestCam', $after['IFD0']['Make'] );

		$iptc = $this->iptc( $file );
		$this->assertSame( array( 'Copyright 2026 Jane Doe' ), $iptc['2#116'] );
		$this->assertSame( array( 'Jane Doe' ), $iptc['2#080'] );

		$dimensions = getimagesize( $file );
		$this->assertSame( array( 8, 8 ), array( $dimensions[0], $dimensions[1] ) );
		if ( function_exists( 'imagecreatefromjpeg' ) ) {
			$this->assertNotFalse( imagecreatefromjpeg( $file ) );
		}

		// Running again finds nothing to do.
		$this->assertSame( 'none', notfortraining_strip_gps_jpeg_file( $file ) );
	}

	/**
	 * Files without GPS are left byte for byte as they were.
	 *
	 * @dataProvider byte_orders
	 *
	 * @param string $order Fixture suffix.
	 */
	public function test_no_gps_untouched( $order ) {
		$file     = $this->copy_fixture( "nogps-$order.jpg" );
		$original = file_get_contents( $file );

		$this->assertSame( 'none', notfortraining_strip_gps_jpeg_file( $file ) );
		$this->assertSame( $original, file_get_contents( $file ) );

		$iptc = $this->iptc( $file );
		$this->assertSame( array( 'Copyright 2026 Jane Doe' ), $iptc['2#116'] );
	}

	/**
	 * A JPEG with no metadata at all.
	 */
	public function test_plain_jpeg_untouched() {
		$file     = $this->copy_fixture( 'plain.jpg' );
		$original = file_get_contents( $file );
		$this->assertSame( 'none', notfortraining_strip_gps_jpeg_file( $file ) );
		$this->assertSame( $original, file_get_contents( $file ) );
	}

	/**
	 * Not a JPEG, or truncated: never modified.
	 */
	public function test_bad_input() {
		$this->assertSame( 'none', notfortraining_strip_gps_jpeg_data( "\x89PNG\r\n\x1a\n" )['status'] );

		$jpeg      = file_get_contents( __DIR__ . '/fixtures/gps-le.jpg' );
		$truncated = substr( $jpeg, 0, 120 );
		$result    = notfortraining_strip_gps_jpeg_data( $truncated );
		$this->assertSame( 'error', $result['status'] );
		$this->assertSame( $truncated, $result['data'] );

		$this->assertSame( 'error', notfortraining_strip_gps_jpeg_file( '/nonexistent/file.jpg' ) );
	}

	/**
	 * The wp_handle_upload hook strips JPEGs, honours the setting, and leaves
	 * other types alone.
	 */
	public function test_upload_hook() {
		$file   = $this->copy_fixture( 'gps-be.jpg' );
		$upload = array(
			'file' => $file,
			'url'  => 'https://example.org/wp-content/uploads/gps-be.jpg',
			'type' => 'image/jpeg',
		);

		$this->set_settings( array( 'strip_gps' => false ) );
		$original = file_get_contents( $file );
		$this->assertSame( $upload, apply_filters( 'wp_handle_upload', $upload, 'upload' ) );
		$this->assertSame( $original, file_get_contents( $file ) );

		$upload['type'] = 'image/png';
		$this->set_settings( array( 'strip_gps' => true ) );
		apply_filters( 'wp_handle_upload', $upload, 'upload' );
		$this->assertSame( $original, file_get_contents( $file ) );

		$upload['type'] = 'image/jpeg';
		$this->assertSame( $upload, apply_filters( 'wp_handle_upload', $upload, 'sideload' ) );
		$this->assertNotSame( $original, file_get_contents( $file ) );
		$this->assertArrayNotHasKey( 'GPSLatitude', $this->exif( $file )['GPS'] ?? array() );
	}
}
