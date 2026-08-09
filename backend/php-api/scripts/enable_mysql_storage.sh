#!/usr/bin/env bash
set -Eeuo pipefail

APP_ROOT="${APP_ROOT:-/var/www/WinPatchAgent}"
API_ROOT="$APP_ROOT/backend/php-api"
MIGRATION_SCRIPT="$API_ROOT/scripts/migrate_runtime_to_mysql.php"
STORAGE_ROOT="${PATCH_API_STORAGE_ROOT:-$API_ROOT/storage/runtime}"
SECRETS_FILE="${PATCH_API_SECRETS_FILE:-/etc/winpatchagent/patchapi-secrets.conf}"
PHP_BIN="${PHP_BIN:-php}"
DB_HOST="${PATCH_API_DB_HOST:-127.0.0.1}"
DB_PORT="${PATCH_API_DB_PORT:-3306}"
DB_NAME="${PATCH_API_DB_NAME:-}"
DB_USER="${PATCH_API_DB_USER:-}"
DB_PASSWORD="${PATCH_API_DB_PASSWORD:-}"
DB_TABLE="${PATCH_API_DB_TABLE:-patchapi_documents}"
PHP_FPM_SERVICE="${PHP_FPM_SERVICE:-php8.1-fpm}"
NGINX_SERVICE="${NGINX_SERVICE:-nginx}"
VERIFY_URL="${PATCH_API_VERIFY_URL:-http://127.0.0.1/healthz}"
BACKUP_ROOT="${PATCH_API_BACKUP_ROOT:-/var/backups/winpatchagent}"
SKIP_BACKUP=0
SKIP_RELOAD=0

usage() {
  cat <<'TXT'
Usage:
  sudo ./backend/php-api/scripts/enable_mysql_storage.sh [options]

Options:
  --app-root PATH
  --storage-root PATH
  --secrets-file PATH
  --php-bin PATH
  --db-host HOST
  --db-port PORT
  --db-name NAME
  --db-user USER
  --db-password PASSWORD
  --db-table TABLE
  --php-fpm-service NAME
  --nginx-service NAME
  --verify-url URL
  --backup-root PATH
  --skip-backup
  --skip-reload
  --help

Environment fallbacks:
  APP_ROOT
  PATCH_API_STORAGE_ROOT
  PATCH_API_SECRETS_FILE
  PATCH_API_DB_HOST
  PATCH_API_DB_PORT
  PATCH_API_DB_NAME
  PATCH_API_DB_USER
  PATCH_API_DB_PASSWORD
  PATCH_API_DB_TABLE
  PHP_FPM_SERVICE
  NGINX_SERVICE
  PATCH_API_VERIFY_URL
  PATCH_API_BACKUP_ROOT
TXT
}

fail() {
  echo "Error: $*" >&2
  exit 1
}

prompt_hidden() {
  local label="$1"
  local value
  read -r -s -p "$label" value
  echo >&2
  printf '%s' "$value"
}

while (($# > 0)); do
  case "$1" in
    --app-root)
      APP_ROOT="$2"
      API_ROOT="$APP_ROOT/backend/php-api"
      MIGRATION_SCRIPT="$API_ROOT/scripts/migrate_runtime_to_mysql.php"
      STORAGE_ROOT="$API_ROOT/storage/runtime"
      shift 2
      ;;
    --storage-root)
      STORAGE_ROOT="$2"
      shift 2
      ;;
    --secrets-file)
      SECRETS_FILE="$2"
      shift 2
      ;;
    --php-bin)
      PHP_BIN="$2"
      shift 2
      ;;
    --db-host)
      DB_HOST="$2"
      shift 2
      ;;
    --db-port)
      DB_PORT="$2"
      shift 2
      ;;
    --db-name)
      DB_NAME="$2"
      shift 2
      ;;
    --db-user)
      DB_USER="$2"
      shift 2
      ;;
    --db-password)
      DB_PASSWORD="$2"
      shift 2
      ;;
    --db-table)
      DB_TABLE="$2"
      shift 2
      ;;
    --php-fpm-service)
      PHP_FPM_SERVICE="$2"
      shift 2
      ;;
    --nginx-service)
      NGINX_SERVICE="$2"
      shift 2
      ;;
    --verify-url)
      VERIFY_URL="$2"
      shift 2
      ;;
    --backup-root)
      BACKUP_ROOT="$2"
      shift 2
      ;;
    --skip-backup)
      SKIP_BACKUP=1
      shift
      ;;
    --skip-reload)
      SKIP_RELOAD=1
      shift
      ;;
    --help|-h)
      usage
      exit 0
      ;;
    *)
      fail "Unknown option: $1"
      ;;
  esac
done

if [[ "${EUID}" -ne 0 ]]; then
  fail "Run this script as root (sudo)."
fi

[[ -d "$APP_ROOT/.git" ]] || fail "Git repo not found at $APP_ROOT"
[[ -d "$API_ROOT" ]] || fail "API root not found at $API_ROOT"
[[ -f "$MIGRATION_SCRIPT" ]] || fail "Migration script not found at $MIGRATION_SCRIPT"
[[ -d "$STORAGE_ROOT" ]] || fail "Storage root not found at $STORAGE_ROOT"
[[ -f "$SECRETS_FILE" ]] || fail "Secrets file not found at $SECRETS_FILE"
command -v "$PHP_BIN" >/dev/null 2>&1 || fail "PHP binary not found: $PHP_BIN"
command -v rsync >/dev/null 2>&1 || fail "rsync is required for runtime backup."

[[ -n "$DB_NAME" ]] || fail "MySQL database name is required."
[[ -n "$DB_USER" ]] || fail "MySQL username is required."
if [[ -z "$DB_PASSWORD" ]]; then
  DB_PASSWORD="$(prompt_hidden "MySQL password for ${DB_USER}: ")"
fi
[[ -n "$DB_PASSWORD" ]] || fail "MySQL password is required."

if ! [[ "$DB_PORT" =~ ^[0-9]+$ ]] || [[ "$DB_PORT" -le 0 ]]; then
  fail "MySQL port must be a positive integer."
fi

TIMESTAMP="$(date -u +%Y%m%dT%H%M%SZ)"
BACKUP_DIR="$BACKUP_ROOT/runtime-${TIMESTAMP}"
SECRETS_BACKUP="${SECRETS_FILE}.bak.${TIMESTAMP}"

echo "==> Preparing MySQL storage cutover"
echo "    App root: $APP_ROOT"
echo "    Storage root: $STORAGE_ROOT"
echo "    Secrets file: $SECRETS_FILE"
echo "    MySQL target: ${DB_HOST}:${DB_PORT}/${DB_NAME}"
echo "    Table prefix source: $DB_TABLE"

if [[ "$SKIP_BACKUP" -eq 0 ]]; then
  echo "==> Backing up runtime storage to $BACKUP_DIR"
  install -d -o root -g root -m 750 "$BACKUP_DIR"
  rsync -a "$STORAGE_ROOT"/ "$BACKUP_DIR"/
else
  echo "==> Skipping runtime backup"
fi

echo "==> Importing runtime data into MySQL"
PATCH_API_DB_PASSWORD="$DB_PASSWORD" "$PHP_BIN" "$MIGRATION_SCRIPT" \
  --storage-root "$STORAGE_ROOT" \
  --db-host "$DB_HOST" \
  --db-port "$DB_PORT" \
  --db-name "$DB_NAME" \
  --db-user "$DB_USER" \
  --db-table "$DB_TABLE"

echo "==> Backing up secrets file to $SECRETS_BACKUP"
install -o root -g root -m 640 "$SECRETS_FILE" "$SECRETS_BACKUP"

echo "==> Enabling MySQL storage in nginx fastcgi params"
tmp_file="$(mktemp)"
trap 'rm -f "$tmp_file"' EXIT

awk '
  BEGIN { skip_block = 0 }
  /# BEGIN PATCHAGENT MYSQL STORAGE/ { skip_block = 1; next }
  /# END PATCHAGENT MYSQL STORAGE/ { skip_block = 0; next }
  skip_block == 1 { next }
  /^[[:space:]]*fastcgi_param[[:space:]]+PATCH_API_DB_/ { next }
  { print }
' "$SECRETS_FILE" > "$tmp_file"

cat <<EOF >> "$tmp_file"

# BEGIN PATCHAGENT MYSQL STORAGE
fastcgi_param PATCH_API_DB_DRIVER mysql;
fastcgi_param PATCH_API_DB_HOST $DB_HOST;
fastcgi_param PATCH_API_DB_PORT $DB_PORT;
fastcgi_param PATCH_API_DB_NAME $DB_NAME;
fastcgi_param PATCH_API_DB_USER $DB_USER;
fastcgi_param PATCH_API_DB_PASSWORD $DB_PASSWORD;
fastcgi_param PATCH_API_DB_TABLE $DB_TABLE;
# END PATCHAGENT MYSQL STORAGE
EOF

install -o root -g www-data -m 640 "$tmp_file" "$SECRETS_FILE"

if [[ "$SKIP_RELOAD" -eq 0 ]]; then
  echo "==> Testing nginx config"
  nginx -t

  echo "==> Reloading PHP-FPM and nginx"
  systemctl reload "$PHP_FPM_SERVICE"
  systemctl reload "$NGINX_SERVICE"
else
  echo "==> Skipping service reload"
fi

if [[ "$SKIP_RELOAD" -eq 0 ]] && command -v curl >/dev/null 2>&1; then
  echo "==> Verifying health endpoint at $VERIFY_URL"
  health_output="$(curl -fsS "$VERIFY_URL")"
  echo "$health_output"
  if [[ "$health_output" != *'"storage_driver":"mysql"'* ]]; then
    fail "Health check did not report mysql storage. Roll back using $SECRETS_BACKUP if needed."
  fi
elif [[ "$SKIP_RELOAD" -eq 0 ]]; then
  echo "==> curl not found; skipping health check"
else
  echo "==> Services were not reloaded; verify $VERIFY_URL manually after reload."
fi

echo "==> MySQL storage cutover complete"
echo "    Runtime backup: ${SKIP_BACKUP:-0}"
echo "    Secrets backup: $SECRETS_BACKUP"
if [[ "$SKIP_BACKUP" -eq 0 ]]; then
  echo "    Runtime backup path: $BACKUP_DIR"
fi
