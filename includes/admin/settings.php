<?php
/**
 * Settings > Not For Training: the settings page (Settings API) and the
 * "Settings" link on the Plugins screen.
 *
 * @package NotForTraining
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', 'notfortraining_add_settings_page' );
add_action( 'admin_init', 'notfortraining_register_settings' );
add_action( 'admin_enqueue_scripts', 'notfortraining_settings_styles' );
add_filter( 'plugin_action_links_' . plugin_basename( NOTFORTRAINING_FILE ), 'notfortraining_plugin_action_links' );

/**
 * The settings page slug.
 *
 * @return string
 */
function notfortraining_settings_slug() {
	return 'not-for-training';
}

/**
 * URL of the settings page.
 *
 * @return string
 */
function notfortraining_settings_url() {
	return admin_url( 'options-general.php?page=' . notfortraining_settings_slug() );
}

/**
 * Add Settings > Not For Training.
 *
 * @return string|false The page's hook suffix, or false without access.
 */
function notfortraining_add_settings_page() {
	return add_options_page(
		__( 'Not For Training', 'not-for-training' ),
		__( 'Not For Training', 'not-for-training' ),
		'manage_options',
		notfortraining_settings_slug(),
		'notfortraining_render_settings_page'
	);
}

/**
 * "Settings" link in the plugin's row on the Plugins screen.
 *
 * @param string[] $links Action links.
 * @return string[]
 */
function notfortraining_plugin_action_links( $links ) {
	if ( current_user_can( 'manage_options' ) ) {
		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( notfortraining_settings_url() ), esc_html__( 'Settings', 'not-for-training' ) )
		);
	}
	return $links;
}

/**
 * Register the option, sections and fields.
 */
function notfortraining_register_settings() {
	register_setting(
		'notfortraining',
		'notfortraining_settings',
		array(
			'type'              => 'array',
			'sanitize_callback' => 'notfortraining_sanitize_settings',
			'default'           => notfortraining_defaults(),
			'show_in_rest'      => false,
		)
	);

	$page = notfortraining_settings_slug();

	add_settings_section( 'notfortraining_bots', __( 'AI bots', 'not-for-training' ), '__return_false', $page );
	add_settings_field( 'notfortraining_robots_mode', __( 'AI bot rules', 'not-for-training' ), 'notfortraining_field_robots_mode', $page, 'notfortraining_bots' );

	add_settings_section( 'notfortraining_signals', __( 'Signals', 'not-for-training' ), '__return_false', $page );
	add_settings_field(
		'notfortraining_noai_signals',
		__( 'No-AI tags', 'not-for-training' ),
		'notfortraining_field_checkbox',
		$page,
		'notfortraining_signals',
		array(
			'key'   => 'noai_signals',
			'label' => __( 'Add no-AI tags to every page', 'not-for-training' ),
			'help'  => __( "A signal some AI tools honor. It doesn't block anything by itself.", 'not-for-training' ),
		)
	);
	add_settings_field(
		'notfortraining_tdm_reservation',
		__( 'Rights-reserved notice', 'not-for-training' ),
		'notfortraining_field_checkbox',
		$page,
		'notfortraining_signals',
		array(
			'key'   => 'tdm_reservation',
			'label' => __( 'Add a rights-reserved notice for text and data mining', 'not-for-training' ),
			'help'  => __( 'A machine-readable notice that you reserve your rights, recognized under EU copyright law.', 'not-for-training' ),
		)
	);

	add_settings_section( 'notfortraining_images', __( 'Images', 'not-for-training' ), '__return_false', $page );
	add_settings_field(
		'notfortraining_max_image_size',
		__( 'Largest image size', 'not-for-training' ),
		'notfortraining_field_max_image_size',
		$page,
		'notfortraining_images',
		array( 'label_for' => 'notfortraining-max_image_size' )
	);
	add_settings_field(
		'notfortraining_strip_gps',
		__( 'Location data', 'not-for-training' ),
		'notfortraining_field_checkbox',
		$page,
		'notfortraining_images',
		array(
			'key'   => 'strip_gps',
			'label' => __( 'Remove location data from new photos', 'not-for-training' ),
			'help'  => __( 'Phones save where a photo was taken. This removes it and keeps your copyright info.', 'not-for-training' ),
		)
	);
	add_settings_field(
		'notfortraining_delete_originals',
		__( 'Full-size files', 'not-for-training' ),
		'notfortraining_field_checkbox',
		$page,
		'notfortraining_images',
		array(
			'key'   => 'delete_originals',
			'label' => __( 'Only keep the smaller copy', 'not-for-training' ),
			'help'  => __( "WordPress normally also keeps your full-size file, at a web address anyone can guess. Turn this on only if your full-size files are saved somewhere else. Full-size files for images uploaded while this is on can't be brought back.", 'not-for-training' ),
		)
	);
}

/**
 * The robots_mode radio group.
 */
function notfortraining_field_robots_mode() {
	$current = notfortraining_get_option( 'robots_mode' );
	$choices = array(
		'training' => array(
			__( 'Block AI training, allow AI search (recommended)', 'not-for-training' ),
			__( "AI companies can't use your site to train their models. ChatGPT, Claude and Perplexity can still find you and link to you when people search.", 'not-for-training' ),
		),
		'all'      => array(
			__( 'Block all AI', 'not-for-training' ),
			__( "Also keeps you out of AI search tools. People asking ChatGPT, Claude or Perplexity won't be pointed to your site.", 'not-for-training' ),
		),
		'off'      => array(
			__( 'Off', 'not-for-training' ),
			__( 'No AI rules are added to your robots.txt.', 'not-for-training' ),
		),
	);
	?>
	<fieldset>
		<legend class="screen-reader-text"><?php esc_html_e( 'AI bot rules', 'not-for-training' ); ?></legend>
		<?php foreach ( $choices as $value => $text ) : ?>
			<?php $help_id = 'notfortraining-robots_mode-' . $value . '-help'; ?>
			<div class="notfortraining-choice">
				<label>
					<input type="radio" name="notfortraining_settings[robots_mode]" value="<?php echo esc_attr( $value ); ?>" aria-describedby="<?php echo esc_attr( $help_id ); ?>" <?php checked( $current, $value ); ?>>
					<?php echo esc_html( $text[0] ); ?>
				</label>
				<p class="description" id="<?php echo esc_attr( $help_id ); ?>"><?php echo esc_html( $text[1] ); ?></p>
			</div>
		<?php endforeach; ?>
	</fieldset>
	<p class="notfortraining-note"><?php esc_html_e( "Google's AI Overviews use the same crawler as Google search, so they can't be blocked without leaving Google. This plugin leaves Google, Bing and Pinterest alone.", 'not-for-training' ); ?></p>
	<?php
}

/**
 * A checkbox setting. A hidden "0" goes first so an unchecked box is
 * submitted as off rather than missing.
 *
 * @param array{key: string, label: string, help: string} $args Field args.
 */
function notfortraining_field_checkbox( $args ) {
	$key     = $args['key'];
	$id      = 'notfortraining-' . $key;
	$name    = 'notfortraining_settings[' . $key . ']';
	$help_id = $id . '-help';
	?>
	<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="0">
	<label for="<?php echo esc_attr( $id ); ?>">
		<input type="checkbox" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="1" aria-describedby="<?php echo esc_attr( $help_id ); ?>" <?php checked( (bool) notfortraining_get_option( $key ) ); ?>>
		<?php echo esc_html( $args['label'] ); ?>
	</label>
	<p class="description" id="<?php echo esc_attr( $help_id ); ?>"><?php echo esc_html( $args['help'] ); ?></p>
	<?php
}

/**
 * The max_image_size select.
 */
function notfortraining_field_max_image_size() {
	$current = (int) notfortraining_get_option( 'max_image_size' );
	$labels  = array(
		/* translators: %d: image size in pixels. */
		1200 => sprintf( __( '%dpx', 'not-for-training' ), 1200 ),
		/* translators: %d: image size in pixels. */
		1600 => sprintf( __( '%dpx (recommended)', 'not-for-training' ), 1600 ),
		/* translators: %d: image size in pixels. */
		2048 => sprintf( __( '%dpx (good if a print shop needs zoom)', 'not-for-training' ), 2048 ),
		0    => __( "Don't change (WordPress default, 2560px)", 'not-for-training' ),
	);
	?>
	<select id="notfortraining-max_image_size" name="notfortraining_settings[max_image_size]" aria-describedby="notfortraining-max_image_size-help">
		<?php foreach ( notfortraining_image_size_choices() as $size ) : ?>
			<option value="<?php echo esc_attr( (string) $size ); ?>" <?php selected( $current, $size ); ?>><?php echo esc_html( $labels[ $size ] ); ?></option>
		<?php endforeach; ?>
	</select>
	<p class="description" id="notfortraining-max_image_size-help"><?php esc_html_e( "The largest copy of new uploads. Smaller copies are less useful for AI training and copying. Images already on your site aren't changed.", 'not-for-training' ); ?></p>
	<?php
}

/**
 * Render the settings page.
 */
function notfortraining_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to manage these settings.', 'not-for-training' ), 403 );
	}
	?>
	<div class="wrap notfortraining-settings">
		<h1><?php esc_html_e( 'Not For Training', 'not-for-training' ); ?></h1>
		<p class="notfortraining-intro"><?php esc_html_e( "Not For Training tells AI companies not to train on your work and makes your images harder to take, without hiding you from Google. It sends opt-out signals and adds speed bumps. It can't stop someone who ignores the rules or takes a screenshot.", 'not-for-training' ); ?></p>

		<?php notfortraining_render_status_section(); ?>

		<form action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" method="post">
			<?php
			settings_fields( 'notfortraining' );
			do_settings_sections( notfortraining_settings_slug() );
			submit_button();
			?>
		</form>

		<div class="notfortraining-tip">
			<p>
				<?php
				printf(
					/* translators: %s: "buy me a coffee" link. */
					esc_html__( "Not For Training is free. If it's helped you, you can %s.", 'not-for-training' ),
					sprintf(
						'<a href="%1$s" target="_blank" rel="noopener">%2$s<span class="screen-reader-text"> %3$s</span></a>',
						esc_url( 'https://buymeacoffee.com/cesteptcbguy' ),
						esc_html__( 'buy me a coffee', 'not-for-training' ),
						/* translators: Hidden text for links that open in a new tab. */
						esc_html__( '(opens in a new tab)', 'not-for-training' )
					)
				);
				?>
			</p>
		</div>
	</div>
	<?php
}

/**
 * A few layout rules for the settings page only.
 *
 * @param string $hook_suffix Current admin page.
 */
function notfortraining_settings_styles( $hook_suffix ) {
	if ( 'settings_page_' . notfortraining_settings_slug() !== $hook_suffix ) {
		return;
	}
	wp_register_style( 'notfortraining-admin', false, array(), NOTFORTRAINING_VERSION );
	wp_enqueue_style( 'notfortraining-admin' );
	wp_add_inline_style(
		'notfortraining-admin',
		'.notfortraining-intro{max-width:46em;font-size:14px}'
		. '.notfortraining-choice{margin:0 0 .9em}'
		. '.notfortraining-choice .description{margin:.2em 0 0 1.85em;max-width:40em}'
		. '.notfortraining-settings .form-table .description{max-width:40em}'
		. '.notfortraining-note{max-width:40em;margin-top:1em;padding:.6em .9em;background:#f6f7f7;border-left:4px solid #c3c4c7}'
		. '.notfortraining-tip{max-width:40em;margin-top:2em;padding:.2em 1em;background:#fff;border:1px solid #c3c4c7;border-radius:4px}'
		. '.notfortraining-status{max-width:52em;margin:1.5em 0 1em;padding:.4em 1.2em 1.2em;background:#fff;border:1px solid #c3c4c7;border-radius:4px}'
		. '.notfortraining-status-list{margin:0 0 1em}'
		. '.notfortraining-status-item{display:flex;gap:.6em;align-items:flex-start;margin:0;padding:.6em 0;border-bottom:1px solid #f0f0f1}'
		. '.notfortraining-status-item .dashicons{flex:none;margin-top:.1em}'
		. '.notfortraining-status-word{margin-right:.35em}'
		. '.notfortraining-status-ok .dashicons{color:#00a32a}'
		. '.notfortraining-status-warn .dashicons{color:#996800}'
		. '.notfortraining-status-problem .dashicons{color:#d63638}'
		. '.notfortraining-status-info .dashicons{color:#2271b1}'
		. '.notfortraining-status-unknown .dashicons{color:#646970}'
		. '.notfortraining-copy-rules,.notfortraining-download{margin:.6em 0 0}'
		. '.notfortraining-copy-rules summary{display:inline-block;list-style:none}'
		. '.notfortraining-copy-rules summary::-webkit-details-marker{display:none}'
		. '.notfortraining-copy-rules textarea{display:block;margin-top:.6em;font-size:12px}'
		. '.notfortraining-upload-hint{display:block;margin-top:.5em}'
		. '.notfortraining-upload-hint code{word-break:break-all}'
	);
}
