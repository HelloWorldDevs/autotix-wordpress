# Autotix for WordPress

Captures PHP errors, warnings, and exceptions from your WordPress site and forwards them to [Autotix](https://autotix.io), which turns them into tickets (Jira or GitHub Issues) and AI-generated fix PRs automatically.

> This repository is a read-only mirror, split from the Autotix monorepo. Issues and PRs are welcome here and will be applied upstream.

## Requirements

- WordPress >= 6.0
- PHP >= 8.1

## Install

### From a release zip

Download the latest release zip and install it via Plugins → Add New → Upload Plugin. Release zips ship with a pre-scoped `vendor/` directory (via php-scoper), so they can't collide with other plugins' dependencies.

### From source

```bash
cd wp-content/plugins/autotix
composer install
```

## Configure

1. Activate the plugin.
2. Go to **Settings → Autotix**.
3. Paste your org webhook token from app.autotix.io → Settings and enable capture.

## Testing the pipeline

The plugin adds an admin test page that raises a deliberate error end-to-end so you can confirm a ticket shows up in Autotix.

## Development

```bash
composer install
vendor/bin/phpunit
```

Build a distributable zip (scoped vendor included):

```bash
composer run build:plugin
```

## License

GPL-2.0-or-later
