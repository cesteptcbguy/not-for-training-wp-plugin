<?php
/**
 * The "Is it working?" section at the top of Settings > Not For Training:
 * one line per status check, a "Check again" button, and the actions some
 * problems need (copy the robots.txt rules, download tdmrep.json).
 *
 * @package NotForTraining
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_post_notfortraining_check_again', 'notfortraining_handle_check_again' );
add_action( 'admin_post_notfortraining_download_tdmrep', 'notfortraining_handle_download_tdmrep' );
add_action( 'wp_ajax_notfortraining_status', 'notfortraining_ajax_status' );
add_action( 'admin_enqueue_scripts', 'notfortraining_status_script' );

/**
 * Icon and word for each status. The word carries the meaning; the icon
 * and its color only repeat it.
 *
 * @return array<string, array{0: string, 1: string}> Status => array( dashicon, word ).
 */
function notfortraining_status_labels() {
	return array(
		'ok'      => array( 'dashicons-yes-alt', __( 'OK', 'not-for-training' ) ),
		'warn'    => array( 'dashicons-warning', __( 'Check this', 'not-for-training' ) ),
		'problem' => array( 'dashicons-dismiss', __( 'Problem', 'not-for-training' ) ),
		'info'    => array( 'dashicons-info', __( 'FYI', 'not-for-training' ) ),
		'unknown' => array( 'dashicons-editor-help', __( "Couldn't check", 'not-for-training' ) ),
	);
}

/**
 * Print the status section. Never waits on the checks: cached results are
 * shown as they are; otherwise a placeholder goes out and the page's
 * script asks notfortraining_ajax_status() for the results. Without
 * JavaScript, a "Run checks" link reloads the page with a nonce'd query
 * arg, and only then do the checks run while the page loads.
 */
function notfortraining_render_status_section() {
	$results = notfortraining_get_cached_status();
	if ( null === $results && notfortraining_is_run_checks_request() ) {
		$results = notfortraining_get_status();
	}
	?>
	<div class="notfortraining-status" id="notfortraining-status">
		<h2><?php esc_html_e( 'Is it working?', 'not-for-training' ); ?></h2>
		<div class="notfortraining-status-body" aria-live="polite" data-pending="<?php echo null === $results ? '1' : '0'; ?>">
			<?php
			if ( null === $results ) {
				notfortraining_render_status_placeholder();
			} else {
				notfortraining_render_status_results( $results );
			}
			?>
		</div>
	</div>
	<?php
}

/**
 * The placeholder while results load: a message for script-enabled
 * browsers, a "Run checks" link for the rest (WordPress admin hides one or
 * the other by the "js" / "no-js" class on the page).
 */
function notfortraining_render_status_placeholder() {
	?>
	<p class="notfortraining-status-loading hide-if-no-js">
		<span class="spinner is-active" aria-hidden="true"></span>
		<span class="notfortraining-status-loading-text"><?php esc_html_e( 'Checking your site...', 'not-for-training' ); ?></span>
	</p>
	<p class="hide-if-js">
		<a class="button" href="<?php echo esc_url( notfortraining_run_checks_url() ); ?>"><?php esc_html_e( 'Run checks', 'not-for-training' ); ?></a>
	</p>
	<?php
}

/**
 * The result lines and the "Check again" button.
 *
 * @param array $results Status results.
 */
function notfortraining_render_status_results( array $results ) {
	$labels = notfortraining_status_labels();
	?>
	<ul class="notfortraining-status-list">
		<?php foreach ( $results as $result ) : ?>
			<?php
			$status = isset( $labels[ $result['status'] ] ) ? $result['status'] : 'unknown';
			?>
			<li class="notfortraining-status-item notfortraining-status-<?php echo esc_attr( $status ); ?>" data-check="<?php echo esc_attr( $result['id'] ); ?>">
				<span class="dashicons <?php echo esc_attr( $labels[ $status ][0] ); ?>" aria-hidden="true"></span>
				<div class="notfortraining-status-text">
					<strong class="notfortraining-status-word"><?php echo esc_html( $labels[ $status ][1] ); ?></strong>
					<?php echo esc_html( $result['message'] ); ?>
					<?php notfortraining_render_status_action( $result ); ?>
				</div>
			</li>
		<?php endforeach; ?>
	</ul>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="notfortraining-check-again">
		<input type="hidden" name="action" value="notfortraining_check_again">
		<?php wp_nonce_field( 'notfortraining_check_again' ); ?>
		<?php submit_button( __( 'Check again', 'not-for-training' ), 'secondary', 'notfortraining-check-again', false ); ?>
	</form>
	<?php
}

/**
 * The results HTML as a string, for the AJAX response.
 *
 * @param array $results Status results.
 * @return string
 */
function notfortraining_status_results_html( array $results ) {
	ob_start();
	notfortraining_render_status_results( $results );
	return (string) ob_get_clean();
}

/**
 * URL of the no-JS "Run checks" link. Raw, not HTML-escaped (unlike
 * wp_nonce_url()), because it's also used as a redirect location; escape
 * it when printing.
 *
 * @return string
 */
function notfortraining_run_checks_url() {
	return add_query_arg(
		array(
			'notfortraining-run-checks' => '1',
			'_wpnonce'                  => wp_create_nonce( 'notfortraining_run_checks' ),
		),
		notfortraining_settings_url()
	) . '#notfortraining-status';
}

/**
 * Whether this page load is the no-JS "Run checks" link, with a valid nonce,
 * for a user who can manage the settings.
 *
 * @return bool
 */
function notfortraining_is_run_checks_request() {
	if ( empty( $_GET['notfortraining-run-checks'] ) || empty( $_GET['_wpnonce'] ) ) {
		return false;
	}
	$nonce = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) );
	return wp_verify_nonce( $nonce, 'notfortraining_run_checks' ) && current_user_can( 'manage_options' );
}

/**
 * AJAX: run the checks (fresh when "refresh" is set) and return the
 * results HTML.
 */
function notfortraining_ajax_status() {
	check_ajax_referer( 'notfortraining_status', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Sorry, you are not allowed to manage these settings.', 'not-for-training' ) ), 403 );
	}
	$refresh = ! empty( $_POST['refresh'] );
	wp_send_json_success( array( 'html' => notfortraining_status_results_html( notfortraining_get_status( $refresh ) ) ) );
}

/**
 * The settings page's script: load results into the placeholder, and run
 * "Check again" without leaving the page.
 *
 * @param string $hook_suffix Current admin page.
 */
function notfortraining_status_script( $hook_suffix ) {
	if ( 'settings_page_' . notfortraining_settings_slug() !== $hook_suffix ) {
		return;
	}
	wp_register_script( 'notfortraining-status', false, array(), NOTFORTRAINING_VERSION, array( 'in_footer' => true ) );
	wp_enqueue_script( 'notfortraining-status' );

	$config = array(
		'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
		'nonce'    => wp_create_nonce( 'notfortraining_status' ),
		'slowMs'   => 8000,
		'checking' => __( 'Checking your site...', 'not-for-training' ),
		'slow'     => __( 'Still checking. Some hosts are slow to answer.', 'not-for-training' ),
		'failed'   => __( "Couldn't check this from your site. That's often a host setting, not a problem with the plugin.", 'not-for-training' ),
		'runUrl'   => notfortraining_run_checks_url(),
		'run'      => __( 'Run checks', 'not-for-training' ),
	);
	wp_add_inline_script( 'notfortraining-status', 'window.notfortrainingStatus=' . wp_json_encode( $config ) . ';' . notfortraining_status_script_source(), 'after' );
}

/**
 * The script itself. Plain JavaScript, no dependencies.
 *
 * @return string
 */
function notfortraining_status_script_source() {
	return <<<'JS'
(function () {
	var c = window.notfortrainingStatus, body = document.querySelector('#notfortraining-status .notfortraining-status-body');
	if (!c || !body || !window.fetch) { return; }

	function text(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

	function placeholder(msg) {
		body.innerHTML = '<p class="notfortraining-status-loading"><span class="spinner is-active" aria-hidden="true"></span> <span class="notfortraining-status-loading-text">' + text(msg) + '</span></p>';
	}

	function load(refresh) {
		body.setAttribute('aria-busy', 'true');
		placeholder(c.checking);
		var slow = setTimeout(function () {
			var t = body.querySelector('.notfortraining-status-loading-text');
			if (t) { t.textContent = c.slow; }
		}, c.slowMs);
		var data = new FormData();
		data.append('action', 'notfortraining_status');
		data.append('nonce', c.nonce);
		if (refresh) { data.append('refresh', '1'); }
		fetch(c.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (r) {
				if (!r || !r.success) { throw new Error('status'); }
				body.innerHTML = r.data.html;
			})
			.catch(function () {
				body.innerHTML = '<p>' + text(c.failed) + '</p><p><a class="button" href="' + text(c.runUrl) + '">' + text(c.run) + '</a></p>';
			})
			.then(function () {
				clearTimeout(slow);
				body.removeAttribute('aria-busy');
				body.setAttribute('data-pending', '0');
			});
	}

	body.addEventListener('submit', function (e) {
		if (e.target && e.target.classList.contains('notfortraining-check-again')) {
			e.preventDefault();
			load(true);
		}
	});

	if ('1' === body.getAttribute('data-pending')) { load(false); }
})();
JS;
}

/**
 * Print the action a result asks for, if any.
 *
 * @param array $result Status result.
 */
function notfortraining_render_status_action( array $result ) {
	$action = $result['action'] ?? '';

	if ( 'copy_rules' === $action ) {
		$block = notfortraining_robots_block();
		if ( '' === $block ) {
			return;
		}
		$id = 'notfortraining-rules-' . $result['id'];
		?>
		<details class="notfortraining-copy-rules">
			<summary class="button"><?php esc_html_e( 'Copy the rules', 'not-for-training' ); ?></summary>
			<label class="screen-reader-text" for="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'AI rules for robots.txt', 'not-for-training' ); ?></label>
			<textarea id="<?php echo esc_attr( $id ); ?>" class="large-text code" rows="<?php echo esc_attr( (string) min( 30, substr_count( $block, "\n" ) + 2 ) ); ?>" readonly><?php echo esc_textarea( $block ); ?></textarea>
		</details>
		<?php
		return;
	}

	if ( 'download_tdmrep' === $action ) {
		?>
		<p class="notfortraining-download">
			<a class="button" href="<?php echo esc_url( notfortraining_download_tdmrep_url() ); ?>"><?php esc_html_e( 'Download tdmrep.json', 'not-for-training' ); ?></a>
			<span class="notfortraining-upload-hint">
				<?php
				printf(
					/* translators: %s: the URL where tdmrep.json should open. */
					esc_html__( 'Upload it so it opens at %s', 'not-for-training' ),
					'<code>' . esc_html( notfortraining_site_root_url() . '/.well-known/tdmrep.json' ) . '</code>'
				);
				?>
			</span>
		</p>
		<?php
	}
}

/**
 * The nonce'd download URL for tdmrep.json.
 *
 * @return string
 */
function notfortraining_download_tdmrep_url() {
	return wp_nonce_url( admin_url( 'admin-post.php?action=notfortraining_download_tdmrep' ), 'notfortraining_download_tdmrep' );
}

/**
 * Stop unless the request has the nonce for $action and the user can
 * manage the settings.
 *
 * @param string $action Nonce action.
 */
function notfortraining_verify_settings_request( $action ) {
	check_admin_referer( $action );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to manage these settings.', 'not-for-training' ), 403 );
	}
}

/**
 * "Check again" without JavaScript: forget the cached results and go to the
 * "Run checks" URL, which runs them fresh while the page loads. With
 * JavaScript the form never posts here; the script uses the AJAX endpoint.
 */
function notfortraining_handle_check_again() {
	notfortraining_verify_settings_request( 'notfortraining_check_again' );
	notfortraining_clear_status();
	wp_safe_redirect( notfortraining_run_checks_url() );
	exit;
}

/**
 * "Download tdmrep.json": send the file as a download.
 */
function notfortraining_handle_download_tdmrep() {
	notfortraining_verify_settings_request( 'notfortraining_download_tdmrep' );
	if ( ! headers_sent() ) {
		nocache_headers();
		header( 'Content-Disposition: attachment; filename="tdmrep.json"' );
	}
	wp_send_json( notfortraining_tdmrep_data(), 200, JSON_UNESCAPED_SLASHES );
}
