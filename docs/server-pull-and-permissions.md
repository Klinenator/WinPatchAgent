# Server Pull + Permissions Runbook

Use this on the API host to pull the latest code and fix nginx/php-fpm readable/writable permissions.

## Use the repo script (recommended)

```bash
cd /var/www/WinPatchAgent
sudo ./scripts/pull_and_fix_permissions.sh
```

## One-shot command

```bash
#!/usr/bin/env bash
set -euo pipefail

APP_ROOT="/var/www/WinPatchAgent"
API_ROOT="$APP_ROOT/backend/php-api"
SECRETS_DIR="/etc/winpatchagent"
SECRETS_FILE="$SECRETS_DIR/patchapi-secrets.conf"
BRANCH="main"
SSH_KEY="${HOME}/.ssh/winpatchagentkey"
SSH_CMD="ssh -i ${SSH_KEY} -o IdentitiesOnly=yes"

if [ ! -r "$SSH_KEY" ]; then
  echo "Missing SSH key: $SSH_KEY"
  exit 1
fi

sudo git config --global --add safe.directory "$APP_ROOT" || true
sudo GIT_SSH_COMMAND="$SSH_CMD" git -C "$APP_ROOT" pull origin "$BRANCH"

sudo chown -R root:www-data "$APP_ROOT"
sudo find "$APP_ROOT" -type d -exec chmod 750 {} \;
sudo find "$APP_ROOT" -type f -exec chmod 640 {} \;

# Public files must be web-readable.
sudo find "$API_ROOT/public" -type d -exec chmod 755 {} \;
sudo find "$API_ROOT/public" -type f -exec chmod 644 {} \;

# Runtime storage must stay writable by www-data.
sudo install -d -o root -g www-data -m 2775 "$API_ROOT/storage/runtime"
sudo find "$API_ROOT/storage/runtime" -type d -exec chmod 2775 {} \;
sudo find "$API_ROOT/storage/runtime" -type f -exec chmod 664 {} \;

# Ensure server secrets path exists for nginx fastcgi_param includes.
sudo install -d -o root -g www-data -m 750 "$SECRETS_DIR"
if [ ! -f "$SECRETS_FILE" ]; then
  sudo install -o root -g www-data -m 640 /dev/null "$SECRETS_FILE"
  echo "Created $SECRETS_FILE (empty). Add fastcgi_param lines, then nginx -t."
fi

sudo -u www-data test -r "$API_ROOT/public/index.php" && echo "index readable"
sudo -u www-data test -r "$API_ROOT/public/admin.html" && echo "admin readable"
sudo -u www-data test -w "$API_ROOT/storage/runtime" && echo "runtime writable"

sudo nginx -t
sudo systemctl reload php8.1-fpm
sudo systemctl reload nginx
```

## Save as script (optional)

```bash
sudo tee /usr/local/bin/winpatch-pull >/dev/null <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
APP_ROOT="/var/www/WinPatchAgent"
API_ROOT="$APP_ROOT/backend/php-api"
SECRETS_DIR="/etc/winpatchagent"
SECRETS_FILE="$SECRETS_DIR/patchapi-secrets.conf"
BRANCH="main"
SSH_KEY="${HOME}/.ssh/winpatchagentkey"
SSH_CMD="ssh -i ${SSH_KEY} -o IdentitiesOnly=yes"
if [ ! -r "$SSH_KEY" ]; then
  echo "Missing SSH key: $SSH_KEY"
  exit 1
fi
sudo git config --global --add safe.directory "$APP_ROOT" || true
sudo GIT_SSH_COMMAND="$SSH_CMD" git -C "$APP_ROOT" pull origin "$BRANCH"
sudo chown -R root:www-data "$APP_ROOT"
sudo find "$APP_ROOT" -type d -exec chmod 750 {} \;
sudo find "$APP_ROOT" -type f -exec chmod 640 {} \;
sudo find "$API_ROOT/public" -type d -exec chmod 755 {} \;
sudo find "$API_ROOT/public" -type f -exec chmod 644 {} \;
sudo install -d -o root -g www-data -m 2775 "$API_ROOT/storage/runtime"
sudo find "$API_ROOT/storage/runtime" -type d -exec chmod 2775 {} \;
sudo find "$API_ROOT/storage/runtime" -type f -exec chmod 664 {} \;
sudo install -d -o root -g www-data -m 750 "$SECRETS_DIR"
if [ ! -f "$SECRETS_FILE" ]; then
  sudo install -o root -g www-data -m 640 /dev/null "$SECRETS_FILE"
  echo "Created $SECRETS_FILE (empty). Add fastcgi_param lines, then nginx -t."
fi
sudo nginx -t
sudo systemctl reload php8.1-fpm
sudo systemctl reload nginx
EOF
sudo chmod +x /usr/local/bin/winpatch-pull
```

## Nginx include for secrets

In your nginx `location ~ \.php$` block, include the secrets file:

```nginx
include /etc/winpatchagent/patchapi-secrets.conf;
```

Use this repo template to populate it:

- `docs/examples/patchapi-secrets.conf.example`

Then run:

```bash
winpatch-pull
```
