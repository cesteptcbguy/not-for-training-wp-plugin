<?php
/**
 * GPS neutralization in HEIC/HEIF files.
 *
 * @package NotForTraining
 */

/**
 * Tests for includes/heic-gps.php.
 */
class NotForTraining_Test_Heic extends NotForTraining_TestCase {

	/**
	 * Temp files to delete.
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
	 * @return string
	 */
	private function copy_fixture( $name ) {
		$file = wp_tempnam( $name );
		copy( __DIR__ . '/fixtures/' . $name, $file );
		$this->temp_files[] = $file;
		return $file;
	}

	/**
	 * Fixtures with GPS in the Exif item.
	 *
	 * @return array
	 */
	public function gps_fixtures() {
		return array(
			'synthetic, iloc v0, little-endian, XMP item' => array( 'heic-gps-le.heic' ),
			'synthetic, iloc v1 + idat, big-endian'       => array( 'heic-gps-be.heic' ),
			'real, Apple ImageIO encoder'                 => array( 'real-gps.heic' ),
		);
	}

	/**
	 * The TIFF structure in the Exif item: offset, end, byte order.
	 *
	 * @param string $data File contents.
	 * @return array{0: int, 1: int, 2: bool}
	 */
	private function tiff( $data ) {
		$exif = array_values(
			array_filter(
				(array) notfortraining_heic_metadata_items( $data ),
				static function ( $item ) {
					return 'Exif' === $item['type'];
				}
			)
		);
		$this->assertCount( 1, $exif );
		$start = $exif[0]['start'] + 4 + unpack( 'N', substr( $data, $exif[0]['start'], 4 ) )[1];
		$this->assertContains( substr( $data, $start, 2 ), array( 'II', 'MM' ) );
		$little_endian = ( 'II' === substr( $data, $start, 2 ) );
		return array( $start, $exif[0]['end'], $little_endian );
	}

	/**
	 * Read an IFD independently of the plugin: tag => raw entry bytes plus
	 * raw value bytes, and the byte ranges the IFD and its values use.
	 *
	 * @param string $data   File contents.
	 * @param int    $tiff   TIFF offset.
	 * @param int    $offset IFD offset from the TIFF header.
	 * @param bool   $le     Little-endian.
	 * @return array{tags: array<int, array{entry: string, value: string}>, ranges: array<int, array{0: int, 1: int}>}
	 */
	private function read_ifd( $data, $tiff, $offset, $le ) {
		$u16    = static function ( $at ) use ( $data, $le ) {
			return unpack( $le ? 'v' : 'n', substr( $data, $at, 2 ) )[1];
		};
		$u32    = static function ( $at ) use ( $data, $le ) {
			return unpack( $le ? 'V' : 'N', substr( $data, $at, 4 ) )[1];
		};
		$sizes  = array(
			1  => 1,
			2  => 1,
			3  => 2,
			4  => 4,
			5  => 8,
			7  => 1,
			9  => 4,
			10 => 8,
		);
		$start  = $tiff + $offset;
		$count  = $u16( $start );
		$tags   = array();
		$ranges = array( array( $start, $start + 2 + 12 * $count + 4 ) );
		for ( $i = 0; $i < $count; $i++ ) {
			$entry = $start + 2 + 12 * $i;
			$bytes = ( $sizes[ $u16( $entry + 2 ) ] ?? 1 ) * $u32( $entry + 4 );
			$value = '';
			if ( $bytes > 4 ) {
				$at       = $tiff + $u32( $entry + 8 );
				$value    = substr( $data, $at, $bytes );
				$ranges[] = array( $at, $at + $bytes );
			}
			$tags[ $u16( $entry ) ] = array(
				'entry' => substr( $data, $entry, 12 ),
				'value' => $value,
			);
		}
		return array(
			'tags'   => $tags,
			'ranges' => $ranges,
		);
	}

	/**
	 * IFD0, Exif IFD and GPS IFD of a file.
	 *
	 * @param string $data File contents.
	 * @return array{ifd0: array, exif: array, gps: array|null}
	 */
	private function ifds( $data ) {
		list( $tiff, , $le ) = $this->tiff( $data );
		$ifd0_offset         = unpack( $le ? 'V' : 'N', substr( $data, $tiff + 4, 4 ) )[1];
		$ifd0                = $this->read_ifd( $data, $tiff, $ifd0_offset, $le );
		$pointer             = static function ( $tag ) use ( $ifd0, $le ) {
			return unpack( $le ? 'V' : 'N', substr( $ifd0['tags'][ $tag ]['entry'], 8, 4 ) )[1];
		};
		return array(
			'ifd0' => $ifd0,
			'exif' => $this->read_ifd( $data, $tiff, $pointer( 0x8769 ), $le ),
			'gps'  => isset( $ifd0['tags'][0x8825] ) ? $this->read_ifd( $data, $tiff, $pointer( 0x8825 ), $le ) : null,
		);
	}

	/**
	 * GPS goes; Make, Model, DateTimeOriginal, Copyright, Artist and every
	 * other byte outside the GPS IFD stay; the length doesn't change.
	 *
	 * @dataProvider gps_fixtures
	 *
	 * @param string $name Fixture.
	 */
	public function test_gps_neutralized( $name ) {
		$file   = $this->copy_fixture( $name );
		$before = file_get_contents( $file );
		$old    = $this->ifds( $before );

		$this->assertNotEmpty( $old['gps']['tags'], 'Fixture should have GPS.' );
		$this->assertSame( 'TestCam', rtrim( $old['ifd0']['tags'][0x010F]['value'], "\0" ) );
		$this->assertSame( 'TC-1 Mark II', rtrim( $old['ifd0']['tags'][0x0110]['value'], "\0" ) );
		$this->assertSame( '2026:11:20 14:05:09', rtrim( $old['exif']['tags'][0x9003]['value'], "\0" ) );

		$this->assertSame( 'stripped', notfortraining_strip_gps_heic_file( $file ) );
		clearstatcache();
		$after = file_get_contents( $file );
		$new   = $this->ifds( $after );

		$this->assertSame( strlen( $before ), strlen( $after ) );
		$this->assertSame( array(), $new['gps']['tags'], 'GPS IFD should be empty.' );

		// Non-GPS EXIF: every IFD0 entry (GPSInfo pointer included) and every
		// Exif IFD entry, with its value bytes, is byte-identical.
		$this->assertSame( $old['ifd0']['tags'], $new['ifd0']['tags'] );
		$this->assertSame( $old['exif']['tags'], $new['exif']['tags'] );
		foreach ( array( 0x010F, 0x0110, 0x013B, 0x8298 ) as $tag ) {
			$this->assertNotSame( '', $new['ifd0']['tags'][ $tag ]['value'], sprintf( 'Tag 0x%04X', $tag ) );
		}

		// Every changed byte is inside the GPS IFD, its values, or (for the
		// XMP item) a GPS property overwritten with spaces.
		$allowed = $old['gps']['ranges'];
		foreach ( (array) notfortraining_heic_metadata_items( $before ) as $item ) {
			if ( 'xmp' === $item['type'] ) {
				$allowed[] = array( $item['start'], $item['end'] );
			}
		}
		$length = strlen( $before );
		for ( $i = 0; $i < $length; $i++ ) {
			if ( $before[ $i ] === $after[ $i ] ) {
				continue;
			}
			$inside = false;
			foreach ( $allowed as $range ) {
				$inside = $inside || ( $i >= $range[0] && $i < $range[1] );
			}
			$this->assertTrue( $inside, "Byte $i changed outside the GPS data." );
		}

		$this->assertSame( 'none', notfortraining_strip_gps_heic_file( $file ) );
	}

	/**
	 * The XMP item's GPS properties become spaces: same length, still
	 * well-formed, rights and credit untouched.
	 */
	public function test_xmp_item_gps_blanked() {
		$file  = $this->copy_fixture( 'heic-gps-le.heic' );
		$items = notfortraining_heic_metadata_items( file_get_contents( $file ) );
		$xmp   = array_values(
			array_filter(
				$items,
				static function ( $item ) {
					return 'xmp' === $item['type'];
				}
			)
		)[0];
		$this->assertStringContainsString( 'exif:GPS', substr( file_get_contents( $file ), $xmp['start'], $xmp['end'] - $xmp['start'] ) );

		$this->assertSame( 'stripped', notfortraining_strip_gps_heic_file( $file ) );
		$packet = substr( file_get_contents( $file ), $xmp['start'], $xmp['end'] - $xmp['start'] );

		$this->assertStringNotContainsString( 'exif:GPS', $packet );
		$this->assertStringContainsString( 'photoshop:Credit="Jane Doe Studio"', $packet );
		$this->assertStringContainsString( '(c) 2026 Jane Doe. All rights reserved.</rdf:li>', $packet );
		$this->assertNotFalse( simplexml_load_string( $packet ) );
	}

	/**
	 * Files without GPS come back byte for byte.
	 */
	public function test_no_gps_untouched() {
		foreach ( array( 'heic-nogps.heic', 'real-nogps.heic' ) as $name ) {
			$file     = $this->copy_fixture( $name );
			$original = file_get_contents( $file );
			$this->assertSame( 'none', notfortraining_strip_gps_heic_file( $file ), $name );
			$this->assertSame( $original, file_get_contents( $file ), $name );
		}
	}

	/**
	 * Malformed or unexpected structures: error, bytes unchanged.
	 */
	public function test_malformed_left_untouched() {
		$good = file_get_contents( __DIR__ . '/fixtures/heic-gps-le.heic' );
		$meta = strpos( $good, 'meta' ) - 4;

		// iloc v0: 8-byte box header, 4 bytes version/flags, 2 bytes of field
		// sizes, 2-byte item count, then 14-byte items (ID, data reference,
		// extent count, 4-byte offset, 4-byte length). Exif is item 2.
		$exif_loc = strpos( $good, 'iloc' ) - 4 + 16 + 14;

		// infe v2 for Exif: ID (2), protection index (2), then "Exif".
		$exif_type = strpos( $good, "Exif\0" );

		// The Exif item's payload in mdat: 4-byte TIFF offset, then "Exif\0\0".
		$exif_data = strpos( $good, "Exif\0\0", strpos( $good, 'mdat' ) ) - 4;

		$cases = array(
			'truncated'                 => substr( $good, 0, (int) ( strlen( $good ) / 2 ) ),
			'meta size too large'       => substr_replace( $good, pack( 'N', strlen( $good ) ), $meta, 4 ),
			'Exif extent past the end'  => substr_replace( $good, pack( 'N', strlen( $good ) - 4 ), $exif_loc + 6, 4 ),
			'two extents for Exif'      => substr_replace( $good, pack( 'n', 2 ), $exif_loc + 4, 2 ),
			'external data reference'   => substr_replace( $good, pack( 'n', 1 ), $exif_loc + 2, 2 ),
			'protected Exif item'       => substr_replace( $good, pack( 'n', 1 ), $exif_type - 2, 2 ),
			'TIFF offset past the item' => substr_replace( $good, pack( 'N', 0xFFFF ), $exif_data, 4 ),
		);
		foreach ( $cases as $label => $bytes ) {
			$result = notfortraining_strip_gps_heic_data( $bytes );
			$this->assertSame( 'error', $result['status'], $label );
			$this->assertSame( $bytes, $result['data'], $label );
		}

		$this->assertSame( 'none', notfortraining_strip_gps_heic_data( file_get_contents( __DIR__ . '/fixtures/gps-le.jpg' ) )['status'] );
		$this->assertSame( 'error', notfortraining_strip_gps_heic_file( '/nonexistent/a.heic' ) );
	}

	/**
	 * Permissions are kept and no temp file is left behind.
	 */
	public function test_permissions_kept() {
		$file = $this->copy_fixture( 'real-gps.heic' );
		chmod( $file, 0640 );
		$this->assertSame( 'stripped', notfortraining_strip_gps_heic_file( $file ) );
		clearstatcache();
		$this->assertSame( 0640, fileperms( $file ) & 0777 );
		$this->assertFileDoesNotExist( $file . '.notfortraining-tmp' );
	}

	/**
	 * The upload hook handles image/heic, and honours the setting.
	 */
	public function test_upload_hook() {
		$file   = $this->copy_fixture( 'heic-gps-be.heic' );
		$before = file_get_contents( $file );
		$upload = array(
			'file' => $file,
			'url'  => 'https://example.org/wp-content/uploads/photo.heic',
			'type' => 'image/heic',
		);

		$this->set_settings( array( 'strip_gps' => false ) );
		apply_filters( 'wp_handle_upload', $upload, 'upload' );
		$this->assertSame( $before, file_get_contents( $file ) );

		$this->set_settings( array( 'strip_gps' => true ) );
		$this->assertSame( $upload, apply_filters( 'wp_handle_upload', $upload, 'upload' ) );
		$this->assertNotSame( $before, file_get_contents( $file ) );
		$this->assertSame( array(), $this->ifds( file_get_contents( $file ) )['gps']['tags'] );
	}

	/**
	 * The post-generation pass neutralizes a HEIC original_image that
	 * WordPress kept next to its JPEG conversion.
	 */
	public function test_post_generation_pass_handles_heic_original() {
		remove_filter( 'wp_handle_upload', 'notfortraining_handle_upload_strip_gps' );
		$jpeg = wp_upload_bits( 'notfortraining-photo.jpg', null, file_get_contents( __DIR__ . '/fixtures/nogps-le.jpg' ) );
		add_filter( 'wp_handle_upload', 'notfortraining_handle_upload_strip_gps' );
		$this->assertEmpty( $jpeg['error'] );

		$heic = path_join( dirname( $jpeg['file'] ), 'notfortraining-photo.heic' );
		copy( __DIR__ . '/fixtures/real-gps.heic', $heic );
		$this->temp_files[] = $heic;

		$id      = self::factory()->attachment->create_object( $jpeg['file'], 0, array( 'post_mime_type' => 'image/jpeg' ) );
		$results = notfortraining_strip_gps_generated_files(
			array(
				'file'           => _wp_relative_upload_path( $jpeg['file'] ),
				'sizes'          => array(),
				'original_image' => 'notfortraining-photo.heic',
			),
			$id,
			'create'
		);

		$this->assertIsArray( $results );
		$this->assertSame( array(), $this->ifds( file_get_contents( $heic ) )['gps']['tags'] );
		$this->assertSame( filesize( __DIR__ . '/fixtures/real-gps.heic' ), filesize( $heic ) );

		wp_delete_attachment( $id, true );
	}
}
