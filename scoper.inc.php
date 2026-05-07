<?php

declare(strict_types=1);

/**
 * PHP-Scoper config — rewrites every vendored class AND every plugin
 * source file under a unique prefix so this plugin can run alongside
 * other WordPress plugins that happen to vendor the same packages
 * (Guzzle, PSR-7, autotix/php-sdk, etc.) without colliding.
 *
 * Why scope the plugin's OWN source too? Because the plugin's classes
 * `use` PSR-7/PSR-3/SDK symbols. When those vendor symbols get scoped
 * (e.g. `Psr\Http\Client\ClientInterface` → `Autotix\Vendor\Psr\…`),
 * the plugin's `use` statements must be rewritten to match. PHP-Scoper
 * handles that automatically when both are in the finder.
 *
 * One side effect: in the released .zip, the plugin's class names also
 * carry the prefix (e.g. `Autotix\Webhook_Client` becomes
 * `Autotix\Vendor\Autotix\Webhook_Client`). The bootstrap file gets
 * rewritten too, so internal references stay consistent. Users looking
 * inside the .zip will see prefixed namespaces — that's the cost of
 * collision-free coexistence with other plugins.
 *
 * Run via `bin/build-zip.sh` from the plugin root.
 */

use Isolated\Symfony\Component\Finder\Finder;

return [
    // Unique prefix so other plugins' vendor classes don't collide.
    // Keep this stable across releases — changing it breaks any code
    // that explicitly references the scoped class names.
    'prefix' => 'Autotix\\Vendor',

    // Scan BOTH vendor/ and the plugin's own source files so use-statements
    // in plugin code get rewritten to point at the scoped vendor symbols.
    'finders' => [
        // All vendor packages.
        Finder::create()
            ->files()
            ->in('vendor')
            ->name('*.php')
            ->notName('/LICENSE|.*\\.md|.*\\.dist|composer\\.(json|lock)/'),

        // Plugin's own source files.
        Finder::create()
            ->files()
            ->in('includes')
            ->name('*.php'),

        // Bootstrap files at the plugin root.
        Finder::create()
            ->files()
            ->in('.')
            ->depth(0)
            ->name(['autotix.php', 'uninstall.php']),
    ],

    // No exclusions — we want EVERYTHING under our prefix to guarantee no
    // namespace collisions with other plugins. Yes, even our own classes.
    'exclude-namespaces' => [],

    // WordPress core helpers (wp_remote_post, get_option, set_transient,
    // is_wp_error, etc.) need to stay unscoped so they resolve to WP's
    // actual implementations at runtime.
    'exclude-functions' => [
        '/^wp_/',
        '/^get_/',
        '/^set_/',
        '/^add_/',
        '/^update_/',
        '/^delete_/',
        '/^is_wp_error$/',
        '/^plugin_dir_/',
        '/^plugin_basename$/',
        '/^register_activation_hook$/',
        '/^register_deactivation_hook$/',
        '/^trigger_error$/',
        '/^error_log$/',
        '/^esc_/',
        '/^sanitize_/',
        '/^current_user_can$/',
        '/^admin_url$/',
    ],

    // WP core constants that the plugin code references.
    'exclude-constants' => [
        '/^ABSPATH$/',
        '/^WP_/',
        '/^DAY_IN_SECONDS$/',
        '/^MINUTE_IN_SECONDS$/',
        '/^HOUR_IN_SECONDS$/',
        '/^E_/',
    ],

    // Don't expose anything — we want full isolation.
    'expose-classes' => [],
];
