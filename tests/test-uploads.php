<?php
/**
 * Image size cap and original deletion.
 *
 * @package NotForTraining
 */

/**
 * Tests for includes/uploads.php.
 */
class NotForTraining_Test_Uploads extends NotForTraining_TestCase {

	/**
	 * Default cap: 1600 everywhere.
	 */
	public function test_default_cap() {
		$this->assertSame( 1600, apply_filters( 'big_image_size_threshold', 2560, array( 3000, 2000 ), '', 0 ) );
		$this->assertSame( 1600, apply_filters( 'max_srcset_image_width', 2048, array( 3000, 2000 ) ) );

		$sizes = apply_filters( 'intermediate_image_sizes_advanced', wp_get_registered_image_subsizes(), array(), 0 );
		$this->assertArrayNotHasKey( '2048x2048', $sizes );
		$this->assertArrayHasKey( '1536x1536', $sizes );
		$this->assertArrayHasKey( 'large', $sizes );
	}

	/**
	 * A cap below 1536 drops that size too.
	 */
	public function test_small_cap() {
		$this->set_settings( array( 'max_image_size' => 1200 ) );
		$sizes = apply_filters( 'intermediate_image_sizes_advanced', wp_get_registered_image_subsizes(), array(), 0 );
		$this->assertArrayNotHasKey( '1536x1536', $sizes );
		$this->assertSame( 1200, apply_filters( 'max_srcset_image_width', 2048, array( 3000, 2000 ) ) );
	}

	/**
	 * Cap 0: WordPress's own values.
	 */
	public function test_cap_off() {
		$this->set_settings( array( 'max_image_size' => 0 ) );
		$this->assertSame( 2560, apply_filters( 'big_image_size_threshold', 2560, array( 3000, 2000 ), '', 0 ) );
		$this->assertSame( 2048, apply_filters( 'max_srcset_image_width', 2048, array( 3000, 2000 ) ) );
		$this->assertArrayHasKey( '2048x2048', apply_filters( 'intermediate_image_sizes_advanced', wp_get_registered_image_subsizes(), array(), 0 ) );
	}

	/**
	 * Upload a 2400px JPEG and generate its sizes.
	 *
	 * @return array{0: int, 1: array} Attachment ID and metadata.
	 */
	private function upload_large_image() {
		$image = imagecreatetruecolor( 2400, 600 );
		$tmp   = wp_tempnam( 'big.jpg' );
		imagejpeg( $image, $tmp, 80 );

		$upload = wp_upload_bits( 'notfortraining-big.jpg', null, file_get_contents( $tmp ) );
		unlink( $tmp );
		$this->assertEmpty( $upload['error'] );

		$id   = self::factory()->attachment->create_object(
			$upload['file'],
			0,
			array( 'post_mime_type' => 'image/jpeg' )
		);
		$meta = wp_generate_attachment_metadata( $id, $upload['file'] );
		return array( $id, $meta );
	}

	/**
	 * End to end: the full size is capped, no 2048 size, original kept by
	 * default.
	 */
	public function test_upload_is_capped_and_original_kept() {
		list( $id, $meta ) = $this->upload_large_image();

		$this->assertSame( 1600, $meta['width'] );
		$this->assertArrayNotHasKey( '2048x2048', $meta['sizes'] );
		$this->assertArrayHasKey( 'original_image', $meta );
		$this->assertFileExists( wp_get_original_image_path( $id ) );

		wp_delete_attachment( $id, true );
	}

	/**
	 * With delete_originals on, the original goes and the metadata no longer
	 * points at it.
	 */
	public function test_delete_originals() {
		$this->set_settings( array( 'delete_originals' => true ) );
		list( $id, $meta ) = $this->upload_large_image();

		$original = path_join( dirname( get_attached_file( $id ) ), 'notfortraining-big.jpg' );
		$this->assertArrayNotHasKey( 'original_image', $meta );
		$this->assertFileDoesNotExist( $original );
		$this->assertFileExists( get_attached_file( $id ) );

		wp_delete_attachment( $id, true );
	}

	/**
	 * Settings are coerced to their types.
	 */
	public function test_option_sanitizing() {
		update_option(
			'notfortraining_settings',
			array(
				'max_image_size'   => '-5',
				'delete_originals' => '1',
			)
		);
		$this->assertSame( 0, notfortraining_get_option( 'max_image_size' ) );
		$this->assertTrue( notfortraining_get_option( 'delete_originals' ) );
		$this->assertTrue( notfortraining_get_option( 'strip_gps' ) );
		$this->assertNull( notfortraining_get_option( 'nope' ) );
	}

	/**
	 * A 2400x600 JPEG carrying the EXIF block (with GPS) from gps-le.jpg,
	 * written with the plugin's wp_handle_upload hook unhooked (as for a
	 * HEIC upload, which that hook skips), sizes made by an editor that
	 * copies EXIF into every size, as Imagick does on real hosts.
	 *
	 * @param bool $with_pass Leave the post-generation GPS pass hooked.
	 * @return array{0: int, 1: array} Attachment ID and metadata.
	 */
	private function upload_gps_image( $with_pass ) {
		$fixture = file_get_contents( __DIR__ . '/fixtures/gps-le.jpg' );
		$exif    = strpos( $fixture, "\xFF\xE1" );
		$length  = unpack( 'n', substr( $fixture, $exif + 2, 2 ) )[1];
		$app1    = substr( $fixture, $exif, 2 + $length );

		$image = imagecreatetruecolor( 2400, 600 );
		imagefilledrectangle( $image, 0, 0, 2399, 599, imagecolorallocate( $image, 30, 90, 160 ) );
		ob_start();
		imagejpeg( $image, null, 80 );
		$jpeg = ob_get_clean();

		// wp_upload_bits() runs wp_handle_upload too; keep GPS for this test.
		remove_filter( 'wp_handle_upload', 'notfortraining_handle_upload_strip_gps' );
		$upload = wp_upload_bits( 'notfortraining-gps.jpg', null, substr( $jpeg, 0, 2 ) . $app1 . substr( $jpeg, 2 ) );
		add_filter( 'wp_handle_upload', 'notfortraining_handle_upload_strip_gps' );
		$this->assertEmpty( $upload['error'] );
		$this->assertArrayHasKey( 'GPSLatitude', exif_read_data( $upload['file'], null, true )['GPS'] );

		$exif_keeping = static function () {
			return array( 'NotForTraining_Exif_Keeping_Editor' );
		};
		add_filter( 'wp_image_editors', $exif_keeping );
		if ( ! $with_pass ) {
			remove_filter( 'wp_generate_attachment_metadata', 'notfortraining_strip_gps_generated_files', 20 );
		}

		$id   = self::factory()->attachment->create_object( $upload['file'], 0, array( 'post_mime_type' => 'image/jpeg' ) );
		$meta = wp_generate_attachment_metadata( $id, $upload['file'] );

		add_filter( 'wp_generate_attachment_metadata', 'notfortraining_strip_gps_generated_files', 20, 3 );
		remove_filter( 'wp_image_editors', $exif_keeping );

		return array( $id, $meta );
	}

	/**
	 * Every file of an attachment: main, sizes, original.
	 *
	 * @param int   $id   Attachment ID.
	 * @param array $meta Metadata.
	 * @return string[]
	 */
	private function attachment_files( $id, array $meta ) {
		$dir   = dirname( get_attached_file( $id ) );
		$files = array( get_attached_file( $id ), path_join( $dir, $meta['original_image'] ) );
		foreach ( $meta['sizes'] as $size ) {
			$files[] = path_join( $dir, $size['file'] );
		}
		return array_unique( $files );
	}

	/**
	 * Whether a file still has GPS latitude in EXIF.
	 *
	 * @param string $file Path.
	 * @return bool
	 */
	private function has_gps( $file ) {
		$exif = exif_read_data( $file, null, true );
		return isset( $exif['GPS']['GPSLatitude'] );
	}

	/**
	 * Control: without the pass, Imagick carries GPS into every size, so the
	 * next test is checking something real.
	 */
	public function test_sizes_inherit_gps_without_the_pass() {
		list( $id, $meta ) = $this->upload_gps_image( false );

		$this->assertNotEmpty( $meta['sizes'] );
		foreach ( $this->attachment_files( $id, $meta ) as $file ) {
			$this->assertTrue( $this->has_gps( $file ), wp_basename( $file ) );
		}
		wp_delete_attachment( $id, true );
	}

	/**
	 * The pass cleans the main (-scaled) file, every size and the original,
	 * keeps copyright, and a second run writes nothing.
	 */
	public function test_post_generation_pass_cleans_every_file() {
		list( $id, $meta ) = $this->upload_gps_image( true );
		$files             = $this->attachment_files( $id, $meta );

		$this->assertGreaterThanOrEqual( 5, count( $files ), 'Main, original and at least three sizes.' );
		$this->assertStringContainsString( '-scaled', get_attached_file( $id ) );

		$mtimes = array();
		foreach ( $files as $file ) {
			$this->assertFileExists( $file );
			$this->assertFalse( $this->has_gps( $file ), wp_basename( $file ) );
			$this->assertSame( '(c) 2026 Jane Doe. All rights reserved.', exif_read_data( $file, null, true )['IFD0']['Copyright'], wp_basename( $file ) );
			touch( $file, time() - 3600 );
			clearstatcache();
			$mtimes[ $file ] = filemtime( $file );
		}

		$results  = notfortraining_strip_gps_attachment_files( $id, $meta );
		$expected = array_fill_keys( $files, 'none' );
		ksort( $results );
		ksort( $expected );
		$this->assertSame( $expected, $results );
		clearstatcache();
		foreach ( $files as $file ) {
			$this->assertSame( $mtimes[ $file ], filemtime( $file ), 'Clean file rewritten: ' . wp_basename( $file ) );
		}

		wp_delete_attachment( $id, true );
	}

	/**
	 * Non-JPEG sizes and files are skipped; the setting turns the pass off.
	 */
	public function test_post_generation_pass_skips() {
		$this->assertSame( 'heic', notfortraining_gps_format( '/x/a.HEIC' ) );
		$this->assertSame( 'heic', notfortraining_gps_format( '/x/a.bin', 'image/heif' ) );
		$this->assertSame( '', notfortraining_gps_format( '/x/a.png' ) );
		$this->assertSame( '', notfortraining_gps_format( '/x/a.jpg', 'image/webp' ) );
		$this->assertSame( 'jpeg', notfortraining_gps_format( '/x/a.JPEG' ) );
		$this->assertSame( 'jpeg', notfortraining_gps_format( '/x/a.bin', 'image/jpeg' ) );

		$this->set_settings( array( 'strip_gps' => false ) );
		list( $id, $meta ) = $this->upload_gps_image( true );
		$this->assertTrue( $this->has_gps( get_attached_file( $id ) ) );
		wp_delete_attachment( $id, true );
	}
}
