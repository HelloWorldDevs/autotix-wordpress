<?php

namespace Autotix;

use Autotix\PhpSdk\EnvReporter as CoreReporter;
use Nyholm\Psr7\Factory\Psr17Factory;

/**
 * WordPress-side adapter around \Autotix\PhpSdk\EnvReporter.
 *
 * Collects the site's environment fingerprint (WordPress version, PHP
 * version, active plugins + versions) and reports it to Autotix for the
 * Vulnerability Scanning feature.  Mirrors the Webhook_Client adapter:
 * transport + auth + fingerprint live in the shared SDK; this class
 * only handles the WordPress-specific bits — collecting the plugin
 * list, reading the token, and the "report once, recheck monthly, send
 * only if changed" cadence stored in an option.
 *
 * If the org hasn't enabled vulnerability scanning, the server accepts
 * the report but stores nothing and tells us to back off — so it's safe
 * (and cheap) to report unconditionally; the customer's opt-in gate
 * lives server-side.
 */
class Env_Reporter {

	private const OPTION_KEY   = 'autotix_env_report_state';
	private const RECHECK_DAYS = 30;

	private array $options;

	public function __construct( array $options ) {
		$this->options = $options;
	}

	/**
	 * Collect + report the environment when due.  Called on the daily cron
	 * and once on activation.  No-ops (without a network call) when the
	 * fingerprint is unchanged and the recheck window hasn't elapsed.
	 *
	 * @param bool $force Bypass the send-only-if-changed cadence. Used by the
	 *                    "Re-report environment now" button and report-on-save,
	 *                    e.g. right after enabling Vulnerability Scanning
	 *                    server-side — a declined report also advanced the
	 *                    recheck window, so without force the site would stay
	 *                    silent for up to a month.
	 *
	 * @return bool True when a report was sent (and accepted).
	 */
	public function maybe_report( bool $force = false ): bool {
		$env         = $this->collect();
		$fingerprint = CoreReporter::fingerprint(
			$env['platform'],
			$env['platformVersion'],
			$env['runtime'],
			$env['packages'],
		);

		$state = get_option( self::OPTION_KEY, [] );
		$last  = $state['fingerprint'] ?? '';
		$next  = (int) ( $state['next_check'] ?? 0 );

		// Unchanged + not yet due → skip the network call entirely.
		if ( ! $force && $fingerprint === $last && time() < $next ) {
			return false;
		}

		$factory = new Psr17Factory();
		$reporter = new CoreReporter(
			new WpHttpClient( (int) ( $this->options['timeout'] ?? 20 ) ),
			$factory,
			$factory,
			[
				'auth_token' => $this->get_env_or_option( 'AUTOTIX_AUTH_TOKEN', 'auth_token' ) ?: null,
				'debug'      => ! empty( $this->options['debug'] ),
				'user_agent' => 'Autotix-WordPress/' . AUTOTIX_VERSION,
			],
			new WpErrorLogLogger(),
		);

		$sent = false;
		try {
			$sent = $reporter->report( $env );
		} catch ( \Throwable $e ) {
			// Telemetry must never break the site — swallow + record for
			// the next cron to retry.
			if ( ! empty( $this->options['debug'] ) ) {
				error_log( 'Autotix env report failed: ' . $e->getMessage() );
			}
		}

		// Record the server's verdict for the settings-page status line:
		// sent+stored = accepted; sent+stored:false = declined (reason says
		// Vulnerability Scanning is off server-side); !sent = network failure.
		// getLastResult() is null if report() threw before responding.
		$result = $reporter->getLastResult();

		// Persist the new fingerprint + next-check regardless of outcome,
		// so a transient failure doesn't hammer the endpoint every minute.
		update_option(
			self::OPTION_KEY,
			[
				'fingerprint' => $fingerprint,
				'next_check'  => time() + self::RECHECK_DAYS * DAY_IN_SECONDS,
				'last_sent'   => $sent ? time() : ( $state['last_sent'] ?? 0 ),
				'last_report' => [
					'time'   => time(),
					'sent'   => $sent,
					'stored' => $result['stored'] ?? null,
					'reason' => $result['reason'] ?? null,
				],
			],
			false,
		);

		return $sent;
	}

	/**
	 * Last report outcome for the settings-page status line, or null if the
	 * environment has never been reported.
	 *
	 * @return array{time: int, sent: bool, stored: ?bool, reason: ?string}|null
	 */
	public function last_report(): ?array {
		$state = get_option( self::OPTION_KEY, [] );
		$last  = $state['last_report'] ?? null;
		return is_array( $last ) ? $last : null;
	}

	/**
	 * Next scheduled env re-check (Unix timestamp), or null if none stored.
	 */
	public function next_check_at(): ?int {
		$state = get_option( self::OPTION_KEY, [] );
		$next  = (int) ( $state['next_check'] ?? 0 );
		return $next > 0 ? $next : null;
	}

	/**
	 * Build the env report payload from WordPress state.
	 *
	 * @return array{
	 *   siteUrl: string,
	 *   platform: string,
	 *   platformVersion: string,
	 *   runtime: string,
	 *   packages: array<int, array{name: string, version: string, ecosystem: string}>,
	 *   projectKey: ?string,
	 * }
	 */
	public function collect(): array {
		return [
			'siteUrl'         => home_url(),
			'platform'        => 'wordpress',
			'platformVersion' => get_bloginfo( 'version' ),
			'runtime'         => 'php ' . PHP_VERSION,
			'packages'        => $this->collect_plugins(),
			'projectKey'      => $this->options['project_key'] ?? null,
		];
	}

	/**
	 * Enumerate installed plugins as {name, version} pairs.  Uses the
	 * plugin's directory slug as the package name (the closest stable
	 * identifier WPScan / OSV can match against) and the header Version.
	 *
	 * @return array<int, array{name: string, version: string, ecosystem: string}>
	 */
	private function collect_plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$packages = [];
		foreach ( get_plugins() as $file => $data ) {
			// $file is "slug/entry.php" or "single-file.php".
			$slug    = dirname( $file );
			$name    = '.' === $slug ? basename( $file, '.php' ) : $slug;
			$version = $data['Version'] ?? '';
			if ( '' === $name || '' === $version ) {
				continue;
			}
			$packages[] = [
				'name'      => $name,
				'version'   => $version,
				// WordPress plugins are tracked in the WordPress ecosystem
				// by OSV / WPScan rather than Packagist.
				'ecosystem' => 'WordPress',
			];
		}
		return $packages;
	}

	/**
	 * Env var wins over the saved option so secrets can stay out of the
	 * wp_options table.  Mirrors Webhook_Client::get_env_or_option().
	 */
	private function get_env_or_option( string $env_name, string $option_key ): string {
		$env = getenv( $env_name );
		if ( ! empty( $env ) ) {
			return $env;
		}
		return $this->options[ $option_key ] ?? '';
	}
}
