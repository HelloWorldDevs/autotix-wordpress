#!/usr/bin/env bash
#
# Builds a self-contained WordPress plugin zip with PHP-Scoped vendor
# dependencies AND scoped plugin source files. The resulting
# `dist/autotix.zip` is what end users upload via wp-admin.
#
# Why scope plugin sources too? PHP-Scoper rewrites the `use` statements
# inside includes/*.php so they point at the scoped vendor symbols
# (`Autotix\Vendor\Psr\…`). Without that, the bundled vendor is scoped
# but the plugin code still tries to load the unscoped names → fatal.
#
# Usage:  ./bin/build-zip.sh
#
# Requires: composer, php >= 8.1, zip.

set -euo pipefail

cd "$(dirname "$0")/.."
PLUGIN_ROOT="$(pwd)"
BUILD_DIR="${PLUGIN_ROOT}/build"
DIST_DIR="${PLUGIN_ROOT}/dist"
TOOLS_DIR="${PLUGIN_ROOT}/tools"
SCOPED_DIR="${BUILD_DIR}/scoped"
SCOPED_PLUGIN_DIR="${BUILD_DIR}/autotix"

# 1. Install php-scoper into an isolated tools/ dir. We DON'T put it in
#    the main vendor/ because `composer install --no-dev` (step 3) wipes
#    that whole directory.
if [ ! -x "${TOOLS_DIR}/vendor/bin/php-scoper" ]; then
  echo "==> Installing php-scoper into tools/"
  mkdir -p "${TOOLS_DIR}"
  composer --working-dir="${TOOLS_DIR}" require --quiet \
    "humbug/php-scoper:^0.18"
fi
SCOPER="${TOOLS_DIR}/vendor/bin/php-scoper"

# 2. Clean previous build artifacts. Don't touch tools/ — that's cached.
echo "==> Cleaning previous build artifacts"
rm -rf "${BUILD_DIR}" "${DIST_DIR}"
mkdir -p "${BUILD_DIR}" "${DIST_DIR}"

# 3. Install ONLY production deps so the zip doesn't ship phpunit, etc.
echo "==> Installing prod dependencies"
composer install --no-dev --optimize-autoloader --quiet

# 3b. Composer's path repo (used in monorepo dev for autotix/php-sdk)
#     symlinks instead of copying. PHP-Scoper would follow the symlink
#     and the zip would end up with a dangling reference. Materialize
#     any symlinks under vendor/ before scoping.
echo "==> Materializing vendor/ symlinks"
while IFS= read -r link; do
  target="$(readlink "${link}")"
  [[ "${target}" != /* ]] && target="$(dirname "${link}")/${target}"
  rm "${link}"
  cp -R "${target}" "${link}"
done < <(find vendor -type l 2>/dev/null)

# 4. Run PHP-Scoper. The finder in scoper.inc.php scans vendor/ AND
#    includes/ AND the bootstrap files, so the output dir contains
#    BOTH a scoped vendor/ and scoped plugin source. Layout:
#       build/scoped/
#         ├── vendor/          # rewritten vendor packages
#         ├── includes/        # rewritten plugin classes
#         ├── autotix.php      # rewritten bootstrap
#         └── uninstall.php
echo "==> Scoping vendor/ and plugin sources → build/scoped/"
"${SCOPER}" add-prefix \
  --output-dir="${SCOPED_DIR}" \
  --force \
  --quiet

# 5. Write a build-time composer.json that points at the scoped layout.
#    The original composer.json declares psr-4 `Autotix\\` → `includes/`,
#    but post-scoping the classes there are in `Autotix\Vendor\Autotix\`
#    so PSR-4 lookups would fail. We instead use a classmap that scans
#    every .php file under includes/ and vendor/ — Composer reads each
#    file's actual namespace + class name and builds a complete map.
cat > "${SCOPED_DIR}/composer.json" <<'JSON'
{
  "name": "autotix/wordpress-plugin-build",
  "description": "Build-time composer.json for autoload regeneration after scoping. Not shipped.",
  "autoload": {
    "classmap": ["includes/", "vendor/"]
  }
}
JSON

# 6. Regenerate the autoloader. classmap-authoritative reads every PHP
#    file in the scoped tree and builds a complete class → file map, so
#    no PSR-4 lookups happen at runtime — important since the scoped
#    namespaces don't match PSR-4 conventions anymore.
echo "==> Regenerating autoloader for scoped classes"
composer dump-autoload \
  --working-dir="${SCOPED_DIR}" \
  --classmap-authoritative \
  --no-dev \
  --quiet

# 7. Assemble the plugin directory: scoped sources + scoped vendor.
echo "==> Assembling plugin directory"
mkdir -p "${SCOPED_PLUGIN_DIR}"
# Bootstrap + uninstall hook (scoped versions from build/scoped/).
cp "${SCOPED_DIR}/autotix.php" "${SCOPED_DIR}/uninstall.php" "${SCOPED_PLUGIN_DIR}/"
# Scoped plugin classes.
cp -R "${SCOPED_DIR}/includes" "${SCOPED_PLUGIN_DIR}/"
# Scoped vendor (with regenerated autoloader).
cp -R "${SCOPED_DIR}/vendor" "${SCOPED_PLUGIN_DIR}/"

# 8. Restore dev deps so contributors can keep working.
echo "==> Restoring dev dependencies"
composer install --quiet

# 9. Zip it up.
echo "==> Creating dist/autotix.zip"
( cd "${BUILD_DIR}" && zip -qr "${DIST_DIR}/autotix.zip" autotix )

echo
echo "✓ Built ${DIST_DIR}/autotix.zip"
ls -lh "${DIST_DIR}/autotix.zip" | awk '{print "  size: " $5}'
echo
echo "Upload via wp-admin → Plugins → Add New → Upload Plugin"
