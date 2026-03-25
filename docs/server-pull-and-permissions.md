# Server Pull + Permissions Runbook

Use this on the API host to pull the latest code and fix nginx/php-fpm readable/writable permissions.

## One-shot command

```bash
#!/usr/bin/env bash
set -euo pipefail

APP_ROOT="/var/www/WinPatchAgent"
API_ROOT="$APP_ROOT/backend/php-api"
BRANCH="main"

# If you use SSH remotes and hit auth issues, switch to HTTPS once:
# sudo git -C "$APP_ROOT" remote set-url origin https://github.com/Klinenator/WinPatchAgent.git

sudo git config --global --add safe.directory "$APP_ROOT" || true
sudo git -C "$APP_ROOT" pull origin "$BRANCH"

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
BRANCH="main"
sudo git config --global --add safe.directory "$APP_ROOT" || true
sudo git -C "$APP_ROOT" pull origin "$BRANCH"
sudo chown -R root:www-data "$APP_ROOT"
sudo find "$APP_ROOT" -type d -exec chmod 750 {} \;
sudo find "$APP_ROOT" -type f -exec chmod 640 {} \;
sudo find "$API_ROOT/public" -type d -exec chmod 755 {} \;
sudo find "$API_ROOT/public" -type f -exec chmod 644 {} \;
sudo install -d -o root -g www-data -m 2775 "$API_ROOT/storage/runtime"
sudo find "$API_ROOT/storage/runtime" -type d -exec chmod 2775 {} \;
sudo find "$API_ROOT/storage/runtime" -type f -exec chmod 664 {} \;
sudo nginx -t
sudo systemctl reload php8.1-fpm
sudo systemctl reload nginx
EOF
sudo chmod +x /usr/local/bin/winpatch-pull
```

Then run:

```bash
winpatch-pull
```
