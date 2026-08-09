#!/usr/bin/env bash
set -Eeuo pipefail

APP_ROOT="${APP_ROOT:-/var/www/WinPatchAgent}"
API_ROOT="$APP_ROOT/backend/php-api"
RUNTIME_ROOT="$API_ROOT/storage/runtime"
REMOTE="${REMOTE:-origin}"
BRANCH="${BRANCH:-main}"
PHP_FPM_SERVICE="${PHP_FPM_SERVICE:-php8.3-fpm}"
NGINX_SERVICE="${NGINX_SERVICE:-nginx}"
SSH_KEY="${WINPATCH_SSH_KEY:-$HOME/.ssh/winpatchagentkey}"

if [[ "${EUID}" -ne 0 ]]; then
  echo "Run this script as root (sudo)."
  exit 1
fi

if [[ ! -d "$APP_ROOT/.git" ]]; then
  echo "Git repo not found at $APP_ROOT"
  exit 1
fi

echo "Configuring safe.directory..."
git config --global --add safe.directory "$APP_ROOT" || true
if id -u www-data >/dev/null 2>&1; then
  sudo -u www-data git config --global --add safe.directory "$APP_ROOT" || true
fi

echo "Pulling latest code from $REMOTE/$BRANCH..."
if [[ -r "$SSH_KEY" ]]; then
  GIT_SSH_COMMAND="ssh -i ${SSH_KEY} -o IdentitiesOnly=yes" \
    git -C "$APP_ROOT" pull --ff-only "$REMOTE" "$BRANCH"
else
  echo "SSH key not found at $SSH_KEY; pulling without explicit key."
  git -C "$APP_ROOT" pull --ff-only "$REMOTE" "$BRANCH"
fi

echo "Applying code ownership and read permissions..."
chown -R root:www-data "$APP_ROOT"
find "$APP_ROOT" -type d -exec chmod g+rx {} +
find "$APP_ROOT" -type f -exec chmod g+r {} +

echo "Applying writable runtime permissions..."
install -d -o www-data -g www-data -m 2770 "$RUNTIME_ROOT"
chown -R www-data:www-data "$RUNTIME_ROOT"
find "$RUNTIME_ROOT" -type d -exec chmod 2770 {} +
find "$RUNTIME_ROOT" -type f -exec chmod 660 {} +

echo "Publishing provisioning artifacts..."
# Both are served from public/scripts/ but are not tracked there, so they must be
# republished on every deploy. Skipping this leaves enrolling hosts installing
# whatever the repo looked like the last time someone remembered to run it by
# hand - which is invisible until a host is enrolled months later.
SCRIPTS_DIR="$API_ROOT/public/scripts"
install -d -m 0750 -o root -g www-data "$SCRIPTS_DIR"
if [[ -f "$APP_ROOT/agents/linux/patchagent_linux.py" ]]; then
  install -m 0640 -o root -g www-data \
    "$APP_ROOT/agents/linux/patchagent_linux.py" "$SCRIPTS_DIR/patchagent_linux.py"
  echo "  patchagent_linux.py published"
fi
git -C "$APP_ROOT" archive --format=tar.gz -o "$SCRIPTS_DIR/agent-source.tar.gz" HEAD
chown root:www-data "$SCRIPTS_DIR/agent-source.tar.gz"
chmod 0640 "$SCRIPTS_DIR/agent-source.tar.gz"
echo "  agent-source.tar.gz published ($(stat -c%s "$SCRIPTS_DIR/agent-source.tar.gz") bytes)"

echo "Verifying effective permissions..."
sudo -u www-data test -r "$API_ROOT/public/index.php" && echo "index readable"
sudo -u www-data test -r "$API_ROOT/public/admin.html" && echo "admin readable"
sudo -u www-data test -w "$RUNTIME_ROOT" && echo "runtime writable"
sudo -u www-data test -r "$SCRIPTS_DIR/patchagent_linux.py" && echo "linux agent readable"
sudo -u www-data test -r "$SCRIPTS_DIR/agent-source.tar.gz" && echo "agent source readable"

echo "Reloading services..."
nginx -t
systemctl reload "$PHP_FPM_SERVICE"
systemctl reload "$NGINX_SERVICE"

echo "Done."
echo "Current HEAD: $(git -C "$APP_ROOT" rev-parse --short HEAD)"
