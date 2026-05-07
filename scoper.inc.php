<?php

declare(strict_types=1);

/**
 * PHP-Scoper config — rewrites every vendored class under a unique
 * prefix so this plugin can run alongside other WordPress plugins that
 * happen to vendor the same packages (Guzzle, PSR-7, autotix/php-sdk,
 * etc.) without colliding.
 *
 * Without this, two plugins both shipping `GuzzleHttp\Client` would cause
 * PHP's autoloader to use whichever one loaded first — stale code from
 * the other plugin and very fun bug reports.
 *
 * Run via `composer build:plugin` from this directory.
 */

use Isolated\Symfony\Component\Finder\Finder;

return [
    // Unique prefix so other plugins' vendor classes don't collide.
    // Keep this stable across releases — changing it breaks any code
    // that explicitly references the scoped class names.
    'prefix' => 'Autotix\\Vendor',

    // Source files to scan.
    'finders' => [
        Finder::create()
            ->files()
            ->in('vendor')
            ->name('*.php')
            ->notName('/LICENSE|.*\\.md|.*\\.dist|composer\\.(json|lock)/'),
    ],

    // Don't rewrite the plugin's own namespace — the source files in
    // `includes/` are NOT scanned (we only scan vendor/), but symbol
    // references INSIDE vendor that point at our plugin namespace
    // should resolve back to the unscoped name.
    'exclude-namespaces' => ['Autotix'],

    // PHP-Scoper rewrites global functions/constants by default. WordPress
    // core's helpers (wp_remote_post, get_option, etc.) need to stay
    // unscoped so they resolve to WP's actual implementations.
    'expose-functions' => [
        // No vendor function aliases needed; placeholder for future deps.
    ],

    // Composer's autoloader uses some symbols by reflection; leave them.
    'expose-classes' => [],
];
