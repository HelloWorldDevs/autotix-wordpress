<?php

namespace Autotix;

/**
 * Registers an admin page that triggers sample PHP errors for testing
 * the Autotix error capture pipeline end-to-end.
 *
 * Each error type is triggered by visiting the page with a specific
 * query parameter, so admins can test individual severity levels.
 */
class Test_Error_Page {

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
		add_action( 'admin_post_autotix_trigger_error', [ $this, 'handle_trigger' ] );
	}

	/**
	 * Add a hidden admin page (not in the menu — linked from settings).
	 */
	public function add_menu_page(): void {
		add_submenu_page(
			null, // Hidden — no parent menu.
			__( 'Autotix Test Errors', 'autotix' ),
			'',
			'manage_options',
			'autotix-test-errors',
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Render the test error page with buttons for each error type.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'autotix' ) );
		}

		$options  = get_option( 'autotix_settings', [] );
		$enabled  = ! empty( $options['enabled'] ) && ! empty( $options['webhook_url'] );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Autotix - Trigger Test Errors', 'autotix' ); ?></h1>

			<p>
				<a href="<?php echo esc_url( admin_url( 'options-general.php?page=autotix' ) ); ?>">
					&larr; <?php esc_html_e( 'Back to Autotix Settings', 'autotix' ); ?>
				</a>
			</p>

			<?php if ( ! $enabled ) : ?>
				<div class="notice notice-warning">
					<p>
						<?php esc_html_e( 'Autotix is not enabled or the webhook URL is not configured. Errors triggered here will NOT be sent to your Autotix dashboard.', 'autotix' ); ?>
					</p>
				</div>
			<?php endif; ?>

			<div class="card" style="max-width: 700px; padding: 16px 20px;">
				<h2 style="margin-top: 0;"><?php esc_html_e( 'How it works', 'autotix' ); ?></h2>
				<p>
					<?php esc_html_e( 'Each button below triggers a real PHP error of that type. The Autotix error handler will capture it and send it to your webhook, just like a real production error.', 'autotix' ); ?>
				</p>
				<p>
					<?php esc_html_e( 'After triggering, check your Autotix dashboard for the new ticket. If auto-fix is configured, a PR will be opened automatically.', 'autotix' ); ?>
				</p>
			</div>

			<table class="widefat striped" style="max-width: 700px; margin-top: 20px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Error Type', 'autotix' ); ?></th>
						<th><?php esc_html_e( 'Severity', 'autotix' ); ?></th>
						<th><?php esc_html_e( 'Captured?', 'autotix' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php
					$error_types = [
						'fatal'      => [
							'label'    => __( 'Fatal Error', 'autotix' ),
							'severity' => __( 'E_ERROR', 'autotix' ),
							'captured' => true,
							'desc'     => __( 'Calls an undefined function — triggers a fatal error caught by the shutdown handler.', 'autotix' ),
						],
						'exception'  => [
							'label'    => __( 'Uncaught Exception', 'autotix' ),
							'severity' => __( 'Exception', 'autotix' ),
							'captured' => true,
							'desc'     => __( 'Throws an uncaught RuntimeException.', 'autotix' ),
						],
						'warning'    => [
							'label'    => __( 'Warning', 'autotix' ),
							'severity' => __( 'E_WARNING', 'autotix' ),
							'captured' => ! empty( $options['capture_warnings'] ),
							'desc'     => __( 'Attempts to include a non-existent file.', 'autotix' ),
						],
						'notice'     => [
							'label'    => __( 'Notice', 'autotix' ),
							'severity' => __( 'E_USER_NOTICE', 'autotix' ),
							'captured' => ! empty( $options['capture_notices'] ),
							'desc'     => __( 'Triggers a user notice via trigger_error().', 'autotix' ),
						],
						'deprecated' => [
							'label'    => __( 'Deprecated', 'autotix' ),
							'severity' => __( 'E_USER_DEPRECATED', 'autotix' ),
							'captured' => ! empty( $options['capture_deprecated'] ),
							'desc'     => __( 'Triggers a user deprecation via trigger_error().', 'autotix' ),
						],
						'type_error' => [
							'label'    => __( 'TypeError', 'autotix' ),
							'severity' => __( 'TypeError', 'autotix' ),
							'captured' => true,
							'desc'     => __( 'Passes wrong argument type to a function — realistic bug.', 'autotix' ),
						],
						'null_access' => [
							'label'    => __( 'Null Property Access', 'autotix' ),
							'severity' => __( 'E_WARNING', 'autotix' ),
							'captured' => ! empty( $options['capture_warnings'] ),
							'desc'     => __( 'Accesses a property on null — common in production code.', 'autotix' ),
						],
					];

					foreach ( $error_types as $type => $info ) :
						$nonce_url = wp_nonce_url(
							admin_url( "admin-post.php?action=autotix_trigger_error&error_type={$type}" ),
							'autotix_trigger_error'
						);
						$badge_class = $info['captured'] ? 'color: green;' : 'color: #999;';
						$badge_text  = $info['captured']
							? __( 'Yes', 'autotix' )
							: __( 'No (disabled)', 'autotix' );
						?>
						<tr>
							<td>
								<strong><?php echo esc_html( $info['label'] ); ?></strong>
								<br>
								<span class="description"><?php echo esc_html( $info['desc'] ); ?></span>
							</td>
							<td><code><?php echo esc_html( $info['severity'] ); ?></code></td>
							<td><span style="<?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( $badge_text ); ?></span></td>
							<td>
								<a href="<?php echo esc_url( $nonce_url ); ?>"
								   class="button button-secondary button-small"
								   onclick="return confirm('<?php echo esc_js( sprintf(
									   /* translators: %s = error type */
									   __( 'Trigger a %s? This will generate a real PHP error.', 'autotix' ),
									   $info['label']
								   ) ); ?>');">
									<?php esc_html_e( 'Trigger', 'autotix' ); ?>
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Handle the error trigger form submission.
	 */
	public function handle_trigger(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'autotix' ) );
		}

		check_admin_referer( 'autotix_trigger_error' );

		$type = sanitize_key( $_GET['error_type'] ?? '' );

		switch ( $type ) {
			case 'fatal':
				// Redirect first since the fatal will kill the script.
				$this->redirect_with_message( 'fatal_triggered' );
				// Call an undefined function — caught by shutdown handler.
				// phpcs:ignore
				autotix_undefined_function_that_does_not_exist_92847();
				break;

			case 'exception':
				throw new \RuntimeException(
					'Autotix Test: Uncaught exception thrown from the Autotix test error page. '
					. 'This is a deliberate test error to verify the error capture pipeline. '
					. 'File: ' . __FILE__ . ' Line: ' . __LINE__
				);

			case 'warning':
				// Include a file that doesn't exist — generates E_WARNING.
				// phpcs:ignore
				include '/tmp/autotix_nonexistent_file_' . uniqid() . '.php';
				$this->redirect_with_message( 'success' );
				break;

			case 'notice':
				// phpcs:ignore
				trigger_error(
					'Autotix Test: This is a deliberate E_USER_NOTICE to test the error capture pipeline.',
					E_USER_NOTICE
				);
				$this->redirect_with_message( 'success' );
				break;

			case 'deprecated':
				// phpcs:ignore
				trigger_error(
					'Autotix Test: Function autotix_old_api() is deprecated since version 2.0. Use autotix_new_api() instead.',
					E_USER_DEPRECATED
				);
				$this->redirect_with_message( 'success' );
				break;

			case 'type_error':
				// Pass wrong type — common real-world bug.
				// strlen() expects string, null given in PHP 8.1+.
				// phpcs:ignore
				$result = strlen( null );
				$this->redirect_with_message( 'success' );
				break;

			case 'null_access':
				// Access property on null — very common production bug.
				$user = $this->get_nonexistent_user();
				// phpcs:ignore
				$name = $user->display_name;
				$this->redirect_with_message( 'success' );
				break;

			default:
				$this->redirect_with_message( 'unknown_type' );
				break;
		}
	}

	/**
	 * Returns null to simulate a failed database lookup.
	 *
	 * @return object|null
	 */
	private function get_nonexistent_user(): ?object {
		// Simulate a function that returns null when the user isn't found.
		return null;
	}

	/**
	 * Redirect back to the test page with a status message.
	 */
	private function redirect_with_message( string $status ): void {
		wp_safe_redirect(
			admin_url( "admin.php?page=autotix-test-errors&triggered={$status}" )
		);
		exit;
	}
}

/**
 * Show result notices on the test error page.
 */
add_action( 'admin_notices', function () {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$page = sanitize_text_field( wp_unslash( $_GET['page'] ?? '' ) );
	if ( 'autotix-test-errors' !== $page ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$triggered = sanitize_key( $_GET['triggered'] ?? '' );
	if ( empty( $triggered ) ) {
		return;
	}

	$messages = [
		'success'         => __( 'Test error triggered! Check your Autotix dashboard for the new ticket.', 'autotix' ),
		'fatal_triggered' => __( 'Fatal error triggered! The page will crash after this redirect — check your Autotix dashboard.', 'autotix' ),
		'unknown_type'    => __( 'Unknown error type specified.', 'autotix' ),
	];

	$message = $messages[ $triggered ] ?? $messages['unknown_type'];
	$class   = 'fatal_triggered' === $triggered ? 'notice-warning' : 'notice-success';

	echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
} );
