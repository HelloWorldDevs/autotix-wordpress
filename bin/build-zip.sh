#!/usr/bin/env bash
#
# Builds a self-contained WordPress plugin zip with PHP-Scoped vendor
# dependencies. The resulting `dist/autotix.zip` is what end users
# upload via wp-admin → Plugins → Add New → Upload.
#
# Why scoping? Two WP plugins both vendoring `Autotix\PhpSdk\WebhookClient`
# (or its transitive deps like Nyholm\Psr7) would collide on PHP's
# autoloader — whichever loads first wins, and the second plugin runs
# with the wrong code. PHP-Scoper renames every vendor class to live
# under a unique `Autotix\Vendor` prefix so each plugin gets its own
# isolated copy.
#
# Run from the plugin root:
#   ./bin/build-zip.sh

set -euo pipefail

cd "$(dirname "$0")/.."
PLUGIN_ROOT="$(pwd)"
BUILD_DIR="${PLUGIN_ROOT}/build"
DIST_DIR="${PLUGIN_ROOT}/dist"
SCOPED_DIR="${BUILD_DIR}/autotix"

echo "==> Cleaning previous build artifacts"
rm -rf "${BUILD_DIR}" "${DIST_DIR}"
mkdir -p "${SCOPED_DIR}" "${DIST_DIR}"

echo "==> Installing prod dependencies"
composer install --no-dev --optimize-autoloader --quiet

echo "==> Running PHP-Scoper on vendor/"
vendor-bin/php-scoper/vendor/bin/php-scoper add-prefix \
  --output-dir="${BUILD_DIR}/vendor-scoped" \
  --force \
  --quiet \
  --working-dir="${PLUGIN_ROOT}" \
  --config=scoper.inc.php

echo "==> Regenerating autoloader for scoped vendor"
composer dump-autoload \
  --working-dir="${BUILD_DIR}/vendor-scoped" \
  --classmap-authoritative \
  --quiet

echo "==> Assembling plugin directory"
cp -R \
  autotix.php \
  uninstall.php \
  includes \
  "${SCOPED_DIR}/"
mv "${BUILD_DIR}/vendor-scoped" "${SCOPED_DIR}/vendor"

echo "==> Restoring dev dependencies"
composer install --quiet

echo "==> Creating dist/autotix.zip"
( cd "${BUILD_DIR}" && zip -qr "${DIST_DIR}/autotix.zip" autotix )

echo
echo "✓ Built ${DIST_DIR}/autotix.zip"
ls -lh "${DIST_DIR}/autotix.zip" | awk '{print "  size: " $5}'
