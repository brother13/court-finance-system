#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
RELEASES_DIR="$ROOT_DIR/releases"
TIMESTAMP="$(date +%Y%m%d-%H%M%S)"
PACKAGE_NAME="court-finance-system-prod-$TIMESTAMP"
SKIP_FRONTEND_BUILD=0
SKIP_COMPOSER_DUMP=0
DRY_RUN=0

usage() {
  cat <<'USAGE'
Usage:
  scripts/build_offline_release.sh [options]

Options:
  --name <package-name>       Override generated package name.
  --skip-frontend-build       Use existing frontend/dist instead of running npm run build.
  --skip-composer-dump        Do not run composer dump-autoload before packaging.
  --dry-run                   Run checks only; do not create a release package.
  -h, --help                  Show this help.

Environment:
  PHP_BIN=/path/to/php        PHP executable. Defaults to MAMP PHP if present, then php.
  NPM_BIN=/path/to/npm        npm executable. Defaults to npm in PATH.

The production server can stay offline. Run this script on a build machine that
already has frontend dependencies and backend Composer dependencies installed.
The generated zip includes backend/vendor, frontend/dist, database scripts, and
runtime placeholder directories.
USAGE
}

log() {
  printf '\n==> %s\n' "$*"
}

fail() {
  printf 'ERROR: %s\n' "$*" >&2
  exit 1
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --name)
      [[ $# -ge 2 ]] || fail "--name requires a value"
      PACKAGE_NAME="$2"
      shift 2
      ;;
    --skip-frontend-build)
      SKIP_FRONTEND_BUILD=1
      shift
      ;;
    --skip-composer-dump)
      SKIP_COMPOSER_DUMP=1
      shift
      ;;
    --dry-run)
      DRY_RUN=1
      shift
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      fail "Unknown option: $1"
      ;;
  esac
done

if [[ -x "/Applications/MAMP/bin/php/php7.4.33/bin/php" ]]; then
  PHP_BIN="${PHP_BIN:-/Applications/MAMP/bin/php/php7.4.33/bin/php}"
else
  PHP_BIN="${PHP_BIN:-php}"
fi
NPM_BIN="${NPM_BIN:-npm}"
PACKAGE_DIR="$RELEASES_DIR/$PACKAGE_NAME"
ZIP_PATH="$RELEASES_DIR/$PACKAGE_NAME.zip"

require_command() {
  command -v "$1" >/dev/null 2>&1 || fail "Command not found: $1"
}

require_path() {
  [[ -e "$1" ]] || fail "Required path is missing: $1"
}

check_php_extension() {
  local extension="$1"
  "$PHP_BIN" -m | awk '{print tolower($0)}' | grep -qx "$extension" \
    || fail "PHP extension is missing: $extension"
}

copy_path() {
  local source="$1"
  local target_parent="$2"
  require_path "$ROOT_DIR/$source"
  mkdir -p "$target_parent"
  cp -R "$ROOT_DIR/$source" "$target_parent/"
}

write_deployment_notes() {
  local target="$1/OFFLINE_DEPLOYMENT.md"
  cat > "$target" <<'NOTES'
# Offline production deployment

This package is prepared for an offline production server.

Included runtime dependencies:
- backend/vendor
- frontend/dist
- backend/composer.json
- backend/composer.lock

Production checks:
1. PHP must be compatible with the application.
2. PHP extensions `gd` and `mbstring` must be enabled for mPDF.
3. `backend/runtime` must be writable by the PHP process.
4. Configure `backend/app/database.php` for the production MySQL database.
5. Apply database scripts from `backend/database` according to the target environment.

Do not run Composer on the offline production server. Build dependencies on a
connected build machine, then deploy this zip.
NOTES
}

log "Checking tools"
require_command "$PHP_BIN"
require_command zip
if [[ "$SKIP_FRONTEND_BUILD" -eq 0 ]]; then
  require_command "$NPM_BIN"
fi

log "Checking backend dependencies"
require_path "$ROOT_DIR/backend/composer.json"
require_path "$ROOT_DIR/backend/composer.lock"
require_path "$ROOT_DIR/backend/vendor/mpdf/mpdf"
require_path "$ROOT_DIR/backend/vendor/composer/ClassLoader.php"
check_php_extension gd
check_php_extension mbstring

log "Checking mPDF runtime loader"
"$PHP_BIN" -r '
$vendor = getcwd() . "/backend/vendor";
if (is_file($vendor . "/autoload.php")) {
    require_once $vendor . "/autoload.php";
}
if (!class_exists("Mpdf\\Mpdf", false)) {
    require_once $vendor . "/composer/ClassLoader.php";
    $loader = new Composer\Autoload\ClassLoader($vendor);
    $loader->addPsr4("Mpdf\\", $vendor . "/mpdf/mpdf/src");
    $loader->addPsr4("Mpdf\\PsrHttpMessageShim\\", $vendor . "/mpdf/psr-http-message-shim/src");
    $loader->addPsr4("Mpdf\\PsrLogAwareTrait\\", $vendor . "/mpdf/psr-log-aware-trait/src");
    $loader->addPsr4("setasign\\Fpdi\\", $vendor . "/setasign/fpdi/src");
    $loader->addPsr4("Psr\\Log\\", $vendor . "/psr/log/Psr/Log");
    $loader->addPsr4("Psr\\Http\\Message\\", $vendor . "/psr/http-message/src");
    $loader->addPsr4("DeepCopy\\", $vendor . "/myclabs/deep-copy/src/DeepCopy");
    $loader->register(true);
    require_once $vendor . "/myclabs/deep-copy/src/DeepCopy/deep_copy.php";
    require_once $vendor . "/mpdf/mpdf/src/functions.php";
}
if (!class_exists("Mpdf\\Mpdf")) {
    fwrite(STDERR, "Mpdf\\Mpdf class is not loadable\n");
    exit(1);
}
'

if [[ "$SKIP_FRONTEND_BUILD" -eq 0 ]]; then
  log "Building frontend"
  (cd "$ROOT_DIR/frontend" && "$NPM_BIN" run build)
else
  log "Skipping frontend build"
fi
require_path "$ROOT_DIR/frontend/dist/index.html"

if [[ "$SKIP_COMPOSER_DUMP" -eq 0 ]]; then
  log "Optimizing Composer autoload"
  if [[ -f "$ROOT_DIR/backend/composer.phar" ]]; then
    (cd "$ROOT_DIR/backend" && "$PHP_BIN" composer.phar dump-autoload --optimize)
  elif command -v composer >/dev/null 2>&1; then
    (cd "$ROOT_DIR/backend" && composer dump-autoload --optimize)
  else
    fail "Composer is not available. Use --skip-composer-dump only if backend/vendor/autoload.php is current."
  fi
fi

if [[ "$DRY_RUN" -eq 1 ]]; then
  log "Dry run complete"
  exit 0
fi

[[ ! -e "$PACKAGE_DIR" ]] || fail "Release directory already exists: $PACKAGE_DIR"
[[ ! -e "$ZIP_PATH" ]] || fail "Release zip already exists: $ZIP_PATH"

log "Creating package directory"
mkdir -p "$PACKAGE_DIR/backend" "$PACKAGE_DIR/frontend" "$PACKAGE_DIR/docs"

copy_path "AGENTS.md" "$PACKAGE_DIR"
copy_path "backend/app" "$PACKAGE_DIR/backend"
copy_path "backend/database" "$PACKAGE_DIR/backend"
copy_path "backend/public" "$PACKAGE_DIR/backend"
copy_path "backend/thinkphp" "$PACKAGE_DIR/backend"
copy_path "backend/vendor" "$PACKAGE_DIR/backend"
copy_path "backend/index.php" "$PACKAGE_DIR/backend"
copy_path "backend/README.md" "$PACKAGE_DIR/backend"
copy_path "backend/composer.json" "$PACKAGE_DIR/backend"
copy_path "backend/composer.lock" "$PACKAGE_DIR/backend"
copy_path "frontend/dist" "$PACKAGE_DIR/frontend"
copy_path "docs/requirements.md" "$PACKAGE_DIR/docs"
copy_path "docs/design" "$PACKAGE_DIR/docs"

mkdir -p "$PACKAGE_DIR/backend/runtime/cache" \
  "$PACKAGE_DIR/backend/runtime/log" \
  "$PACKAGE_DIR/backend/runtime/temp" \
  "$PACKAGE_DIR/backend/runtime/mpdf"

write_deployment_notes "$PACKAGE_DIR"

log "Writing release manifest"
{
  printf 'package=%s\n' "$PACKAGE_NAME"
  printf 'created_at=%s\n' "$(date '+%Y-%m-%d %H:%M:%S %z')"
  printf 'git_commit=%s\n' "$(git -C "$ROOT_DIR" rev-parse --short HEAD 2>/dev/null || printf 'unknown')"
  printf 'php=%s\n' "$("$PHP_BIN" -r 'echo PHP_VERSION;')"
  printf 'includes_backend_vendor=yes\n'
  printf 'includes_frontend_dist=yes\n'
} > "$PACKAGE_DIR/RELEASE_MANIFEST.txt"

log "Creating zip"
(cd "$RELEASES_DIR" && zip -qr "$ZIP_PATH" "$PACKAGE_NAME" \
  -x "*.DS_Store" \
  -x "__MACOSX/*")

log "Release ready"
printf '%s\n' "$ZIP_PATH"
