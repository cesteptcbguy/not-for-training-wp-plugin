<?php
/**
 * Uninstall: remove the plugin's options, on every site of a network.
 * Uploaded files the plugin changed (GPS stripped, originals deleted) stay
 * as they are.
 *
 * @package NotForTraining
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Delete this site's options.
 */
function notfortraining_uninstall_site() {
	delete_option( 'notfortraining_settings' );
	delete_option( 'notfortraining_rewrite_version' );
}

if ( is_multisite() ) {
	$notfortraining_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $notfortraining_site_ids as $notfortraining_site_id ) {
		switch_to_blog( $notfortraining_site_id );
		notfortraining_uninstall_site();
		restore_current_blog();
	}
} else {
	notfortraining_uninstall_site();
}
