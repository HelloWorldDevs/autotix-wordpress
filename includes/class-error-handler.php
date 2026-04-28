<?php

namespace Autotix;

/**
 * Registers PHP error, exception, and shutdown handlers to capture errors
 * and forward them to the Autotix webhook.
 *
 * This is the WordPress equivalent of Drupal's WebhookLogger which hooks
 * into watchdog. Since WordPress doesn't have a centralized logging system,
 * we hook directly into PHP's error handling and WordPress-specific hooks.
 */
class Error_Handler {

	private Webhook_Client $client;
	private Dedup $dedup;
	private array $options;

	/** @var callable|null Previous error handler to chain to. */
	private $previous_error_handler = null;

	/** @var callable|null Previous exception handler to chain to. */
	private $previous_exception_handler = null;

	/**
	 * Map PHP error constants to PSR-3-style severity strings.
	 */
	private const LEVEL_MAP = [
		E_ERROR             => 'error',
		E_WARNING           => 'warning',
		E_NOTICE            => 'notice',
		E_PARSE             => 'critical',
		E_CORE_ERROR        => 'critical',
		E_CORE_WARNING      => 'warning',
		E_COMPILE_ERROR     => 'critical',
		E_COMPILE_WARNING   => 'warning',
		E_USER_ERROR        => 'error',
		E_USER_WARNING      => 'warning',
		E_USER_NOTICE       => 'notice',
		E_STRICT            => 'notice',
		E_RECOVERABLE_ERROR => 'error',
		E_DEPRECATED        => 'notice',
		E_USER_DEPRECATED   => 'notice',
	];

	/**
	 * Human-readable names for PHP error types.
	 */
	private const TYPE_NAMES = [
		E_ERROR             => 'Fatal Error',
		E_WARNING           => 'Warning',
		E_NOTICE            => 'Notice',
		E_PARSE             => 'Parse Error',
		E_CORE_ERROR        => 'Core Error',
		E_CORE_WARNING      => 'Core Warning',
		E_COMPILE_ERROR     => 'Compile Error',
		E_COMPILE_WARNING   => 'Compile Warning',
		E_USER_ERROR        => 'User Error',
		E_USER_WARNING      => 'User Warning',
		E_USER_NOTICE       => 'User Notice',
		E_STRICT            => 'Strict Standards',
		E_RECOVERABLE_ERROR => 'Recoverable Error',
		E_DEPRECATED        => 'Deprecated',
		E_USER_DEPRECATED   => 'User Deprecated',
	];

	/** @var bool Guard against re-entry if our own code triggers an error. */
	private bool $handling = false;

	public function __construct( Webhook_Client $client, Dedup $dedup, array $options ) {
		$this->client  = $client;
		$this->dedup   = $dedup;
		$this->options = $options;
	}

	/**
	 * Register all error handlers.
	 */
	public function register(): void {
		// PHP error handler (warnings, notices, user errors, etc.)
		$previous = set_error_handler( [ $this, 'handle_error' ] );
		if ( $previous ) {
			$this->previous_error_handler = $previous;
		}

		// Uncaught exception handler.
		$previous = set_exception_handler( [ $this, 'handle_exception' ] );
		if ( $previous ) {
			$this->previous_exception_handler = $previous;
		}

		// Shutdown handler for fatal errors that can't be caught above.
		register_shutdown_function( [ $this, 'handle_shutdown' ] );

		// WordPress-specific hooks.
		add_action( 'doing_it_wrong_run', [ $this, 'handle_doing_it_wrong' ], 10, 3 );
	}

	/**
	 * PHP error handler callback.
	 *
	 * @return bool FALSE to let PHP's default handler run too.
	 */
	public function handle_error( int $errno, string $errstr, string $errfile = '', int $errline = 0 ): bool {
		if ( $this->handling ) {
			return false;
		}

		// Respect the error_reporting level (handles @ suppression operator too).
		if ( ! ( error_reporting() & $errno ) ) {
			return false;
		}

		if ( ! $this->should_capture( $errno ) ) {
			// Chain to previous handler if we're not capturing this level.
			if ( $this->previous_error_handler ) {
				return call_user_func( $this->previous_error_handler, $errno, $errstr, $errfile, $errline );
			}
			return false;
		}

		$type_name = self::TYPE_NAMES[ $errno ] ?? 'Unknown Error';
		$message   = "{$type_name}: {$errstr} in {$errfile} on line {$errline}";

		$this->capture( $message, $errno, $errfile, $errline );

		// Chain to previous handler.
		if ( $this->previous_error_handler ) {
			return call_user_func( $this->previous_error_handler, $errno, $errstr, $errfile, $errline );
		}

		return false;
	}

	/**
	 * Uncaught exception handler.
	 */
	public function handle_exception( \Throwable $exception ): void {
		if ( ! $this->handling ) {
			$message = sprintf(
				'Uncaught %s: %s in %s on line %d',
				get_class( $exception ),
				$exception->getMessage(),
				$exception->getFile(),
				$exception->getLine()
			);

			$this->capture(
				$message,
				E_ERROR,
				$exception->getFile(),
				$exception->getLine(),
				$exception->getTraceAsString()
			);
		}

		// Chain to previous handler so WordPress can still render its error page.
		if ( $this->previous_exception_handler ) {
			call_user_func( $this->previous_exception_handler, $exception );
		}
	}

	/**
	 * Shutdown handler — catches fatal errors that bypass set_error_handler.
	 */
	public function handle_shutdown(): void {
		$error = error_get_last();
		if ( null === $error ) {
			return;
		}

		// Only catch fatal-class errors.
		$fatal_types = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR;
		if ( ! ( $error['type'] & $fatal_types ) ) {
			return;
		}

		if ( $this->handling ) {
			return;
		}

		$type_name = self::TYPE_NAMES[ $error['type'] ] ?? 'Fatal Error';
		$message   = "{$type_name}: {$error['message']} in {$error['file']} on line {$error['line']}";

		$this->capture( $message, $error['type'], $error['file'], $error['line'] );
	}

	/**
	 * Hook into WordPress's _doing_it_wrong() calls.
	 */
	public function handle_doing_it_wrong( string $function, string $message, string $version ): void {
		if ( $this->handling ) {
			return;
		}

		// Treat _doing_it_wrong as a warning-level event.
		if ( ! $this->should_capture( E_USER_WARNING ) ) {
			return;
		}

		$full_message = "_doing_it_wrong: {$function}() — {$message}";
		if ( $version ) {
			$full_message .= " (since {$version})";
		}

		$this->capture( $full_message, E_USER_WARNING );
	}

	/**
	 * Determine if this error level should be captured based on settings.
	 */
	private function should_capture( int $errno ): bool {
		// Fatal errors are always captured.
		$fatal_types = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;
		if ( $errno & $fatal_types ) {
			return true;
		}

		// Warnings.
		$warning_types = E_WARNING | E_CORE_WARNING | E_COMPILE_WARNING | E_USER_WARNING;
		if ( ( $errno & $warning_types ) && ! empty( $this->options['capture_warnings'] ) ) {
			return true;
		}

		// Notices.
		$notice_types = E_NOTICE | E_USER_NOTICE | E_STRICT;
		if ( ( $errno & $notice_types ) && ! empty( $this->options['capture_notices'] ) ) {
			return true;
		}

		// Deprecated.
		$deprecated_types = E_DEPRECATED | E_USER_DEPRECATED;
		if ( ( $errno & $deprecated_types ) && ! empty( $this->options['capture_deprecated'] ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Build the payload and send (or queue) it.
	 */
	private function capture(
		string $message,
		int $errno,
		string $file = '',
		int $line = 0,
		string $backtrace = ''
	): void {
		$this->handling = true;

		try {
			// Deduplication.
			$channel = self::TYPE_NAMES[ $errno ] ?? 'php';
			if ( $this->dedup->is_duplicate( $channel, $message ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( "[Autotix] Skipped duplicate: {$message}" );
				return;
			}

			$url = $this->get_current_url();

			$payload = [
				'source'  => 'wordpress',
				'level'   => self::LEVEL_MAP[ $errno ] ?? 'error',
				'message' => $message,
				'url'     => $url,
				'details' => [
					'channel'     => $channel,
					'severity'    => $errno,
					'file'        => $file,
					'line'        => $line,
					'request_uri' => $url,
					'environment' => $this->options['environment'] ?? 'production',
					'timestamp'   => time(),
					'php_version' => PHP_VERSION,
					'wp_version'  => get_bloginfo( 'version' ),
				],
			];

			// Include backtrace if configured.
			if ( ! empty( $this->options['include_backtrace'] ) ) {
				if ( $backtrace ) {
					$payload['details']['backtrace'] = $backtrace;
				} else {
					$trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 10 );
					$payload['details']['backtrace'] = $this->format_backtrace( $trace );
				}
			}

			// Include active theme and plugin context.
			$payload['details']['active_theme']  = get_template();
			$payload['details']['error_context'] = $this->identify_source( $file );

			if ( ! empty( $this->options['send_immediately'] ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( "[Autotix] Capturing error (immediate): {$message}" );
				try {
					$this->client->send( $payload );
				} catch ( \Exception $e ) {
					// Fall back to queue.
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( "[Autotix] Immediate send failed, queuing: {$e->getMessage()}" );
					$this->enqueue( $payload );
				}
			} else {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( "[Autotix] Capturing error (queued): {$message}" );
				$this->enqueue( $payload );
			}
		} finally {
			$this->handling = false;
		}
	}

	/**
	 * Add payload to the queue for delivery on next cron run.
	 */
	private function enqueue( array $payload ): void {
		$queue   = get_option( 'autotix_queue', [] );
		$queue[] = $payload;

		// Cap the queue size to prevent runaway storage usage.
		if ( count( $queue ) > 100 ) {
			$queue = array_slice( $queue, -100 );
		}

		update_option( 'autotix_queue', $queue, false );
	}

	/**
	 * Try to determine the current page URL.
	 */
	private function get_current_url(): string {
		if ( ! isset( $_SERVER['HTTP_HOST'] ) ) {
			return site_url();
		}

		$scheme = is_ssl() ? 'https' : 'http';
		$host   = sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) );
		$uri    = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) );

		return "{$scheme}://{$host}{$uri}";
	}

	/**
	 * Identify whether the error comes from a plugin, theme, or core.
	 */
	private function identify_source( string $file ): string {
		if ( empty( $file ) ) {
			return 'unknown';
		}

		$abspath = wp_normalize_path( ABSPATH );
		$file    = wp_normalize_path( $file );

		// Check plugins.
		$plugin_dir = wp_normalize_path( WP_PLUGIN_DIR );
		if ( str_starts_with( $file, $plugin_dir ) ) {
			$relative = substr( $file, strlen( $plugin_dir ) + 1 );
			$parts    = explode( '/', $relative, 2 );
			return 'plugin:' . $parts[0];
		}

		// Check mu-plugins.
		$mu_dir = wp_normalize_path( WPMU_PLUGIN_DIR );
		if ( str_starts_with( $file, $mu_dir ) ) {
			return 'mu-plugin';
		}

		// Check themes.
		$theme_dir = wp_normalize_path( get_theme_root() );
		if ( str_starts_with( $file, $theme_dir ) ) {
			$relative = substr( $file, strlen( $theme_dir ) + 1 );
			$parts    = explode( '/', $relative, 2 );
			return 'theme:' . $parts[0];
		}

		// Everything else is core.
		if ( str_starts_with( $file, $abspath ) ) {
			return 'core';
		}

		return 'unknown';
	}

	/**
	 * Format a debug_backtrace array into a readable string.
	 */
	private function format_backtrace( array $trace ): string {
		$lines = [];
		foreach ( $trace as $i => $frame ) {
			$file  = $frame['file'] ?? '(unknown)';
			$line  = $frame['line'] ?? '?';
			$func  = $frame['function'] ?? '(unknown)';
			$class = isset( $frame['class'] ) ? $frame['class'] . $frame['type'] : '';
			$lines[] = "#{$i} {$file}:{$line} {$class}{$func}()";
		}
		return implode( "\n", $lines );
	}
}
