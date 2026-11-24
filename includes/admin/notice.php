<?php
/**
 * One-time setup notice after activation, on the Plugins screen and the
 * settings page only.
 *
 * State: the notfortraining_setup_notice option is "pending" from
 * activation until the settings are first saved ("done"). Each user can
 * also dismiss it for themselves (user meta).
 *
 * @package NotForTraining
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_notices', 'notfortraining_setup_notice' );
add_action( 'admin_post_notfortraining_dismiss_notice', 'notfortraining_handle_dismiss_notice' );
add_filter( 'pre_update_option_notfortraining_settings', 'notfortraining_mark_setup_done' );

/**
 * User meta key for a dismissed notice.
 *
 * @return string
 */
function notfortraining_notice_meta_key() {
	return 'notfortraining_notice_dismissed';
}

/**
 * Whether the current user should see the setup notice on this screen.
 *
 * @return bool
 */
function notfortraining_should_show_setup_notice() {
	if ( 'pending' !== get_option( 'notfortraining_setup_notice' ) || ! current_user_can( 'manage_options' ) ) {
		return false;
	}
	if ( get_user_meta( get_current_user_id(), notfortraining_notice_meta_key(), true ) ) {
		return false;
	}
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	return $screen && in_array( $screen->id, array( 'plugins', 'settings_page_' . notfortraining_settings_slug() ), true );
}

/**
 * URL that dismisses the notice for the current user.
 *
 * @return string
 */
function notfortraining_dismiss_notice_url() {
	return wp_nonce_url( admin_url( 'admin-post.php?action=notfortraining_dismiss_notice' ), 'notfortraining_dismiss_notice' );
}

/**
 * Print the setup notice.
 */
function notfortraining_setup_notice() {
	if ( ! notfortraining_should_show_setup_notice() ) {
		return;
	}

	$message = sprintf(
		'<p>%1$s</p><p><a class="button button-primary" href="%2$s">%3$s</a> <a class="button" href="%4$s">%5$s</a></p>',
		esc_html__( 'Not For Training is on. Your site now blocks AI training and keeps AI search. Want to only keep smaller copies of new uploads?', 'not-for-training' ),
		esc_url( notfortraining_settings_url() ),
		esc_html__( 'Review settings', 'not-for-training' ),
		esc_url( notfortraining_dismiss_notice_url() ),
		esc_html__( 'Dismiss', 'not-for-training' )
	);

	wp_admin_notice(
		$message,
		array(
			'type'               => 'info',
			'additional_classes' => array( 'notfortraining-setup-notice' ),
			'paragraph_wrap'     => false,
		)
	);
}

/**
 * Dismiss the notice for the current user (admin-post action), then go
 * back where they came from.
 */
function notfortraining_handle_dismiss_notice() {
	check_admin_referer( 'notfortraining_dismiss_notice' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to manage these settings.', 'not-for-training' ), 403 );
	}

	update_user_meta( get_current_user_id(), notfortraining_notice_meta_key(), 1 );

	$back = wp_get_referer();
	wp_safe_redirect( $back ? $back : admin_url( 'plugins.php' ) );
	exit;
}

/**
 * Saving the settings (the first time or any time) retires the notice for
 * everyone.
 *
 * @param mixed $value New settings value, unchanged.
 * @return mixed
 */
function notfortraining_mark_setup_done( $value ) {
	if ( 'done' !== get_option( 'notfortraining_setup_notice' ) ) {
		update_option( 'notfortraining_setup_notice', 'done', false );
	}
	return $value;
}
