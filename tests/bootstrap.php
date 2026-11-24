<?php
/**
 * PHPUnit bootstrap: load the WordPress test library, then the plugin.
 *
 * The wp-env tests-cli container sets WP_TESTS_DIR. Elsewhere, point it at a
 * wordpress-develop checkout's tests/phpunit directory.
 *
 * @package NotForTraining
 */

$notfortraining_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $notfortraining_tests_dir ) {
	$notfortraining_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $notfortraining_tests_dir . '/includes/functions.php' ) ) {
	echo 'Could not find ' . $notfortraining_tests_dir . '/includes/functions.php. Run the tests through wp-env (npm run test:php) or set WP_TESTS_DIR.' . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput
	exit( 1 );
}

define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' );

require_once $notfortraining_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__ ) . '/not-for-training.php';
	}
);

require $notfortraining_tests_dir . '/includes/bootstrap.php';
require_once __DIR__ . '/class-notfortraining-testcase.php';
require_once __DIR__ . '/class-notfortraining-exif-keeping-editor.php';
require_once __DIR__ . '/class-notfortraining-redirect-exception.php';
require_once __DIR__ . '/class-notfortraining-fake-imagick-editor.php';
