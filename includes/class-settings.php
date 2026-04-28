<?php

namespace Autotix;

/**
 * Admin settings page for the Autotix plugin.
 *
 * Registers a page under Settings > Autotix.
 */
class Settings {

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
	}

	/**
	 * Add the settings page under Settings.
	 */
	public function add_menu_page(): void {
		add_options_page(
			__( 'Autotix Settings', 'autotix' ),
			__( 'Autotix', 'autotix' ),
			'manage_options',
			'autotix',
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Register settings, sections, and fields.
	 */
	public function register_settings(): void {
		register_setting( 'autotix_settings_group', 'autotix_settings', [
			'type'              => 'array',
			'sanitize_callback' => [ $this, 'sanitize_settings' ],
		] );

		// ---- General section ----
		add_settings_section(
			'autotix_general',
			__( 'General', 'autotix' ),
			function () {
				echo '<p>' . esc_html__( 'Enable the plugin and configure your Autotix webhook endpoint.', 'autotix' ) . '</p>';
			},
			'autotix'
		);

		$this->add_field( 'enabled', __( 'Enable Autotix', 'autotix' ), 'autotix_general', 'render_checkbox', [
			'description' => __( 'When enabled, PHP errors meeting the severity threshold will be sent to Autotix for automated ticket creation.', 'autotix' ),
		] );

		$this->add_field( 'webhook_url', __( 'Webhook URL', 'autotix' ), 'autotix_general', 'render_url', [
			'description' => __( 'The full URL of your Autotix webhook endpoint (e.g. https://app.autotix.io/api/webhook/error).', 'autotix' ),
			'placeholder' => 'https://app.autotix.io/api/webhook/error',
		] );

		// ---- Authentication section ----
		add_settings_section(
			'autotix_auth',
			__( 'Authentication', 'autotix' ),
			null,
			'autotix'
		);

		$this->add_field( 'auth_method', __( 'Auth Method', 'autotix' ), 'autotix_auth', 'render_select', [
			'options' => [
				'token' => __( 'Token (X-Webhook-Token header)', 'autotix' ),
				'hmac'  => __( 'HMAC-SHA256 signature (X-Webhook-Signature header)', 'autotix' ),
				'none'  => __( 'None', 'autotix' ),
			],
		] );

		$has_token_env = ! empty( getenv( 'AUTOTIX_AUTH_TOKEN' ) );
		$token_desc    = __( 'Your Autotix webhook token.', 'autotix' );
		if ( $has_token_env ) {
			$token_desc .= ' <strong>' . __( 'Currently provided by environment variable.', 'autotix' ) . '</strong>';
		} else {
			$token_desc .= ' ' . __( 'Can also be set via the AUTOTIX_AUTH_TOKEN environment variable (recommended).', 'autotix' );
		}

		$this->add_field( 'auth_token', __( 'Auth Token', 'autotix' ), 'autotix_auth', 'render_password', [
			'description' => $token_desc,
		] );

		$has_secret_env = ! empty( getenv( 'AUTOTIX_HMAC_SECRET' ) );
		$secret_desc    = __( 'The HMAC signing secret.', 'autotix' );
		if ( $has_secret_env ) {
			$secret_desc .= ' <strong>' . __( 'Currently provided by environment variable.', 'autotix' ) . '</strong>';
		} else {
			$secret_desc .= ' ' . __( 'Can also be set via the AUTOTIX_HMAC_SECRET environment variable (recommended).', 'autotix' );
		}

		$this->add_field( 'auth_secret', __( 'HMAC Secret', 'autotix' ), 'autotix_auth', 'render_password', [
			'description' => $secret_desc,
		] );

		// ---- Delivery section ----
		add_settings_section(
			'autotix_delivery',
			__( 'Delivery', 'autotix' ),
			null,
			'autotix'
		);

		$this->add_field( 'send_immediately', __( 'Send Immediately', 'autotix' ), 'autotix_delivery', 'render_checkbox', [
			'description' => __( 'When checked, errors are sent in real time during the request. When unchecked, errors are queued and delivered via WP-Cron. Immediate mode falls back to the queue on failure.', 'autotix' ),
		] );

		// ---- Filtering section ----
		add_settings_section(
			'autotix_filtering',
			__( 'Error Filtering', 'autotix' ),
			function () {
				echo '<p>' . esc_html__( 'Choose which error types to capture and send to Autotix.', 'autotix' ) . '</p>';
			},
			'autotix'
		);

		$this->add_field( 'capture_warnings', __( 'Capture Warnings', 'autotix' ), 'autotix_filtering', 'render_checkbox', [
			'description' => __( 'PHP warnings (E_WARNING, E_USER_WARNING). Fatal errors are always captured.', 'autotix' ),
		] );

		$this->add_field( 'capture_notices', __( 'Capture Notices', 'autotix' ), 'autotix_filtering', 'render_checkbox', [
			'description' => __( 'PHP notices (E_NOTICE, E_USER_NOTICE, E_STRICT).', 'autotix' ),
		] );

		$this->add_field( 'capture_deprecated', __( 'Capture Deprecated', 'autotix' ), 'autotix_filtering', 'render_checkbox', [
			'description' => __( 'PHP deprecation notices (E_DEPRECATED, E_USER_DEPRECATED).', 'autotix' ),
		] );

		$this->add_field( 'dedup_window', __( 'Dedup Window (seconds)', 'autotix' ), 'autotix_filtering', 'render_number', [
			'description' => __( 'Suppress duplicate messages within this many seconds. Set to 0 to disable.', 'autotix' ),
			'min'         => 0,
			'max'         => 604800,
		] );

		// ---- Advanced section ----
		add_settings_section(
			'autotix_advanced',
			__( 'Advanced', 'autotix' ),
			null,
			'autotix'
		);

		$this->add_field( 'environment', __( 'Environment', 'autotix' ), 'autotix_advanced', 'render_text', [
			'description' => __( 'Environment label sent in the payload (e.g. production, staging, dev).', 'autotix' ),
			'placeholder' => 'production',
		] );

		$this->add_field( 'include_backtrace', __( 'Include Backtrace', 'autotix' ), 'autotix_advanced', 'render_checkbox', [
			'description' => __( 'Include a PHP backtrace in the payload. Useful for debugging but increases payload size.', 'autotix' ),
		] );

		$this->add_field( 'debug', __( 'Debug Logging', 'autotix' ), 'autotix_advanced', 'render_checkbox', [
			'description' => __( 'Log outbound requests to the PHP error log. Disable in production.', 'autotix' ),
		] );

		$this->add_field( 'timeout', __( 'HTTP Timeout (seconds)', 'autotix' ), 'autotix_advanced', 'render_number', [
			'description' => __( 'How long to wait for the Autotix endpoint to respond.', 'autotix' ),
			'min'         => 1,
			'max'         => 60,
		] );
	}

	/**
	 * Sanitize settings on save.
	 */
	public function sanitize_settings( array $input ): array {
		$existing = get_option( 'autotix_settings', [] );

		$sanitized = [
			'enabled'            => ! empty( $input['enabled'] ),
			'webhook_url'        => esc_url_raw( $input['webhook_url'] ?? '' ),
			'auth_method'        => sanitize_text_field( $input['auth_method'] ?? 'token' ),
			'send_immediately'   => ! empty( $input['send_immediately'] ),
			'capture_warnings'   => ! empty( $input['capture_warnings'] ),
			'capture_notices'    => ! empty( $input['capture_notices'] ),
			'capture_deprecated' => ! empty( $input['capture_deprecated'] ),
			'dedup_window'       => max( 0, (int) ( $input['dedup_window'] ?? 86400 ) ),
			'environment'        => sanitize_text_field( $input['environment'] ?? 'production' ),
			'include_backtrace'  => ! empty( $input['include_backtrace'] ),
			'debug'              => ! empty( $input['debug'] ),
			'timeout'            => max( 1, min( 60, (int) ( $input['timeout'] ?? 20 ) ) ),
		];

		// Only overwrite secrets when a new value is entered.
		$sanitized['auth_token'] = ! empty( $input['auth_token'] )
			? sanitize_text_field( $input['auth_token'] )
			: ( $existing['auth_token'] ?? '' );

		$sanitized['auth_secret'] = ! empty( $input['auth_secret'] )
			? sanitize_text_field( $input['auth_secret'] )
			: ( $existing['auth_secret'] ?? '' );

		return $sanitized;
	}

	/**
	 * Render the settings page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$options    = get_option( 'autotix_settings', [] );
		$queue_size = count( get_option( 'autotix_queue', [] ) );
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<?php if ( $queue_size > 0 ) : ?>
				<div class="notice notice-info">
					<p>
						<?php
						printf(
							/* translators: %d = queued error count */
							esc_html__( '%d errors queued for delivery on next cron run.', 'autotix' ),
							$queue_size
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php
				settings_fields( 'autotix_settings_group' );
				do_settings_sections( 'autotix' );
				submit_button();
				?>
			</form>

			<hr>
			<h2><?php esc_html_e( 'Testing', 'autotix' ); ?></h2>
			<p>
				<a href="<?php echo esc_url( admin_url( 'admin-post.php?action=autotix_test' ) ); ?>"
				   class="button button-secondary">
					<?php esc_html_e( 'Send Test Payload', 'autotix' ); ?>
				</a>
				<span class="description">
					<?php esc_html_e( 'Sends a test payload directly to the webhook (bypasses PHP error handlers).', 'autotix' ); ?>
				</span>
			</p>
			<p style="margin-top: 12px;">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=autotix-test-errors' ) ); ?>"
				   class="button button-secondary">
					<?php esc_html_e( 'Trigger Sample Errors', 'autotix' ); ?>
				</a>
				<span class="description">
					<?php esc_html_e( 'Trigger real PHP errors to test the full capture pipeline end-to-end.', 'autotix' ); ?>
				</span>
			</p>
		</div>
		<?php
	}

	// ---- Field renderers ----

	/**
	 * Render a checkbox field.
	 */
	public function render_checkbox( array $args ): void {
		$options = get_option( 'autotix_settings', [] );
		$value   = ! empty( $options[ $args['key'] ] );
		?>
		<label>
			<input type="checkbox"
				   name="autotix_settings[<?php echo esc_attr( $args['key'] ); ?>]"
				   value="1"
				   <?php checked( $value ); ?>
			>
			<?php if ( ! empty( $args['description'] ) ) : ?>
				<span class="description"><?php echo wp_kses_post( $args['description'] ); ?></span>
			<?php endif; ?>
		</label>
		<?php
	}

	/**
	 * Render a URL input field.
	 */
	public function render_url( array $args ): void {
		$options = get_option( 'autotix_settings', [] );
		$value   = $options[ $args['key'] ] ?? '';
		?>
		<input type="url"
			   name="autotix_settings[<?php echo esc_attr( $args['key'] ); ?>]"
			   value="<?php echo esc_attr( $value ); ?>"
			   class="regular-text"
			   placeholder="<?php echo esc_attr( $args['placeholder'] ?? '' ); ?>"
		>
		<?php if ( ! empty( $args['description'] ) ) : ?>
			<p class="description"><?php echo wp_kses_post( $args['description'] ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render a text input field.
	 */
	public function render_text( array $args ): void {
		$options = get_option( 'autotix_settings', [] );
		$value   = $options[ $args['key'] ] ?? '';
		?>
		<input type="text"
			   name="autotix_settings[<?php echo esc_attr( $args['key'] ); ?>]"
			   value="<?php echo esc_attr( $value ); ?>"
			   class="regular-text"
			   placeholder="<?php echo esc_attr( $args['placeholder'] ?? '' ); ?>"
		>
		<?php if ( ! empty( $args['description'] ) ) : ?>
			<p class="description"><?php echo wp_kses_post( $args['description'] ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render a password input field.
	 */
	public function render_password( array $args ): void {
		$options  = get_option( 'autotix_settings', [] );
		$has_value = ! empty( $options[ $args['key'] ] );
		?>
		<input type="password"
			   name="autotix_settings[<?php echo esc_attr( $args['key'] ); ?>]"
			   value=""
			   class="regular-text"
			   placeholder="<?php echo $has_value ? esc_attr__( '(saved - leave blank to keep)', 'autotix' ) : ''; ?>"
		>
		<?php if ( ! empty( $args['description'] ) ) : ?>
			<p class="description"><?php echo wp_kses_post( $args['description'] ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render a select dropdown.
	 */
	public function render_select( array $args ): void {
		$options = get_option( 'autotix_settings', [] );
		$value   = $options[ $args['key'] ] ?? '';
		?>
		<select name="autotix_settings[<?php echo esc_attr( $args['key'] ); ?>]">
			<?php foreach ( $args['options'] as $opt_value => $opt_label ) : ?>
				<option value="<?php echo esc_attr( $opt_value ); ?>" <?php selected( $value, $opt_value ); ?>>
					<?php echo esc_html( $opt_label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php if ( ! empty( $args['description'] ) ) : ?>
			<p class="description"><?php echo wp_kses_post( $args['description'] ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render a number input field.
	 */
	public function render_number( array $args ): void {
		$options = get_option( 'autotix_settings', [] );
		$value   = $options[ $args['key'] ] ?? '';
		?>
		<input type="number"
			   name="autotix_settings[<?php echo esc_attr( $args['key'] ); ?>]"
			   value="<?php echo esc_attr( $value ); ?>"
			   min="<?php echo esc_attr( $args['min'] ?? 0 ); ?>"
			   max="<?php echo esc_attr( $args['max'] ?? '' ); ?>"
			   class="small-text"
		>
		<?php if ( ! empty( $args['description'] ) ) : ?>
			<p class="description"><?php echo wp_kses_post( $args['description'] ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Helper to register a settings field with metadata.
	 */
	private function add_field( string $key, string $title, string $section, string $callback, array $args = [] ): void {
		$args['key'] = $key;
		add_settings_field(
			"autotix_{$key}",
			$title,
			[ $this, $callback ],
			'autotix',
			$section,
			$args
		);
	}
}

/**
 * Handle the "Send Test Error" button.
 */
add_action( 'admin_post_autotix_test', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to do this.', 'autotix' ) );
	}

	$options = get_option( 'autotix_settings', [] );
	$client  = new Webhook_Client( $options );

	$payload = [
		'source'  => 'wordpress',
		'level'   => 'error',
		'message' => 'Autotix Test Error: This is a test error sent from the WordPress admin panel to verify the webhook connection.',
		'url'     => site_url(),
		'details' => [
			'channel'     => 'Test',
			'severity'    => E_USER_ERROR,
			'environment' => $options['environment'] ?? 'production',
			'timestamp'   => time(),
			'php_version' => PHP_VERSION,
			'wp_version'  => get_bloginfo( 'version' ),
		],
	];

	try {
		$client->send( $payload );
		$message = 'success';
	} catch ( \Exception $e ) {
		$message = 'error&autotix_error=' . rawurlencode( $e->getMessage() );
	}

	wp_safe_redirect( admin_url( "options-general.php?page=autotix&autotix_test={$message}" ) );
	exit;
} );

/**
 * Show admin notices for test results.
 */
add_action( 'admin_notices', function () {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( ! isset( $_GET['autotix_test'] ) ) {
		return;
	}

	$page = sanitize_text_field( wp_unslash( $_GET['page'] ?? '' ) );
	if ( 'autotix' !== $page ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$result = sanitize_text_field( wp_unslash( $_GET['autotix_test'] ) );

	if ( 'success' === $result ) {
		echo '<div class="notice notice-success is-dismissible"><p>';
		esc_html_e( 'Test error sent successfully! Check your Autotix dashboard.', 'autotix' );
		echo '</p></div>';
	} else {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$error = isset( $_GET['autotix_error'] )
			? sanitize_text_field( wp_unslash( $_GET['autotix_error'] ) )
			: __( 'Unknown error', 'autotix' );
		echo '<div class="notice notice-error is-dismissible"><p>';
		printf(
			/* translators: %s = error message */
			esc_html__( 'Test failed: %s', 'autotix' ),
			esc_html( $error )
		);
		echo '</p></div>';
	}
} );
