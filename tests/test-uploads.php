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
}
