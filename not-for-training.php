<?php
/**
 * Plugin Name:       Not For Training
 * Plugin URI:        https://wordpress.org/plugins/not-for-training/
 * Description:       Opts your site out of AI training while staying findable in search and AI search: robots.txt groups for training crawlers, noai and TDM reservation signals, and a size cap and GPS stripping for uploaded images.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            Christopher Estep
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       not-for-training
 *
 * @package NotForTraining
 */

defined( 'ABSPATH' ) || exit;

define( 'NOTFORTRAINING_VERSION', '0.1.0' );
define( 'NOTFORTRAINING_FILE', __FILE__ );
define( 'NOTFORTRAINING_DIR', plugin_dir_path( __FILE__ ) );

/*
 * Bump when the plugin's rewrite rules change, so existing installs flush
 * once on the next request (activation hooks don't run on updates).
 */
define( 'NOTFORTRAINING_REWRITE_VERSION', '1' );

require_once NOTFORTRAINING_DIR . 'includes/options.php';
require_once NOTFORTRAINING_DIR . 'includes/robots.php';
require_once NOTFORTRAINING_DIR . 'includes/tdmrep.php';
require_once NOTFORTRAINING_DIR . 'includes/exif-gps.php';
require_once NOTFORTRAINING_DIR . 'includes/heic-gps.php';
require_once NOTFORTRAINING_DIR . 'includes/uploads.php';
require_once NOTFORTRAINING_DIR . 'includes/status.php';
require_once NOTFORTRAINING_DIR . 'includes/site-health.php';
require_once NOTFORTRAINING_DIR . 'includes/admin/settings.php';
require_once NOTFORTRAINING_DIR . 'includes/admin/notice.php';
require_once NOTFORTRAINING_DIR . 'includes/admin/status-section.php';

register_activation_hook( __FILE__, 'notfortraining_activate' );
register_deactivation_hook( __FILE__, 'notfortraining_deactivate' );

/**
 * Activation: register the tdmrep.json rule and flush, so the URL works
 * right away, and queue the one-time setup notice. Settings aren't stored:
 * until they're first saved, the defaults apply. A site that has saved its
 * settings before ("done") doesn't see the notice again.
 */
function notfortraining_activate() {
	notfortraining_add_rewrite_rules();
	flush_rewrite_rules( false );
	update_option( 'notfortraining_rewrite_version', NOTFORTRAINING_REWRITE_VERSION, false );
	add_option( 'notfortraining_setup_notice', 'pending', '', false );
}

/**
 * Deactivation: drop the tdmrep.json rule and flush, so the URL stops
 * routing to WordPress once the plugin is off.
 */
function notfortraining_deactivate() {
	notfortraining_remove_rewrite_rules();
	flush_rewrite_rules( false );
	delete_option( 'notfortraining_rewrite_version' );
}
