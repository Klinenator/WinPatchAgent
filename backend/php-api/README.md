# PatchAgent PHP API

This is a minimal PHP backend scaffold for the PatchAgent endpoint service.

It is intentionally framework-free so it can run behind nginx with plain PHP-FPM.

Current endpoints:
- `GET /admin` (simple admin web view)
- `GET /admin/automation`
- `GET /admin/seed-jobs`
- `GET /admin/install-agent`
- `GET /admin/settings`
- `GET /admin/login` (Google login page for admin)
- `GET /v1/admin/auth/status`
- `GET /v1/admin/auth/google/start`
- `GET /v1/admin/auth/google/callback`
- `POST /v1/admin/auth/logout`
- `POST /v1/admin/auth/totp/verify`
- `GET /v1/admin/auth/passkeys`
- `POST /v1/admin/auth/passkey/challenge`
- `POST /v1/admin/auth/passkey/verify`
- `POST /v1/admin/auth/passkey/register/options`
- `POST /v1/admin/auth/passkey/register/complete`
- `POST /v1/admin/auth/passkeys/{credentialId}/delete`
- `POST /v1/agents/register`
- `POST /v1/agents/heartbeat`
- `POST /v1/agents/inventory`
- `POST /v1/agents/jobs/next`
- `POST /v1/agents/jobs/{jobId}/ack`
- `POST /v1/agents/jobs/{jobId}/complete`
- `POST /v1/agents/job-events`
- `POST /v1/admin/jobs`
- `POST /v1/admin/jobs/{jobId}/cancel`
- `GET /v1/admin/jobs`
- `GET /v1/admin/automations`
- `POST /v1/admin/automations`
- `POST /v1/admin/automations/{profileId}/run`
- `POST /v1/admin/automations/{profileId}/delete`
- `GET /v1/admin/agents`
- `GET /v1/admin/agents/{agentRecordId}/inventory`
- `POST /v1/admin/agents/{agentRecordId}/rename`
- `POST /v1/admin/enrollments`
- `GET /install/linux.sh?enrollment_key=...`
- `GET /install/macos.sh?enrollment_key=...`
- `GET /install/windows.ps1?enrollment_key=...`
- `GET /healthz`

Storage model:
- File-backed JSON and NDJSON under `storage/runtime/`
- Good enough for early prototyping
- Not intended for production scale

Files:
- `public/index.php`: front controller for nginx or the PHP built-in server
- `src/`: routing, auth, storage, and endpoint handlers
- `storage/runtime/`: local file-backed state

Environment variables:
- `PATCH_API_ENROLLMENT_KEY`: optional shared key required at registration time
- `PATCH_API_ADMIN_KEY`: optional bearer token required for admin routes
- `PATCH_API_GOOGLE_CLIENT_ID`: optional Google OAuth client ID for admin login
- `PATCH_API_GOOGLE_CLIENT_SECRET`: optional Google OAuth client secret for admin login
- `PATCH_API_GOOGLE_REDIRECT_URI`: optional OAuth callback URL (defaults to `https://<current-host>/v1/admin/auth/google/callback`)
- `PATCH_API_GOOGLE_HOSTED_DOMAIN`: optional Google Workspace domain allow-list (example: `accessrrs.com`)
- `PATCH_API_LEGACY_CONFIG_FILE`: optional path to legacy PHP config include used as fallback for Google OAuth values (default `/var/lib/php/config.php`)
- `PATCH_API_ADMIN_SESSION_NAME`: optional admin session cookie name (default `patchagent_admin`)
- `PATCH_API_ADMIN_SESSION_TTL_SECONDS`: optional admin session lifetime seconds (default `28800`)
- `PATCH_API_ADMIN_TOTP_SECRET`: optional Base32 TOTP secret for admin MFA (Google Authenticator/Authy compatible)
- `PATCH_API_ADMIN_TOTP_ISSUER`: optional TOTP issuer label (default `PatchAgent Admin`)
- `PATCH_API_ADMIN_TOTP_WINDOW`: optional clock-drift window in 30-second steps (default `1`)
- `PATCH_API_ADMIN_TOTP_CHALLENGE_TTL_SECONDS`: optional pending TOTP challenge lifetime in seconds (default `300`)
- `PATCH_API_STORAGE_ROOT`: optional override for the runtime storage path
- `PATCH_API_HEARTBEAT_SECONDS`: default `300`
- `PATCH_API_JOBS_SECONDS`: default `120`
- `PATCH_API_INVENTORY_SECONDS`: default `21600`

Local run example once PHP is installed:

```bash
cd /Users/seankline/src/windows_patch_management_agent/backend/php-api
php -S 127.0.0.1:8080 -t public
```

Open the admin page in your browser:

`http://127.0.0.1:8080/admin`

Admin authentication supports either:
- `PATCH_API_ADMIN_KEY` bearer token (for scripts/curl)
- Google OAuth session login when `PATCH_API_GOOGLE_CLIENT_ID`, `PATCH_API_GOOGLE_CLIENT_SECRET`, and `PATCH_API_GOOGLE_REDIRECT_URI` are set
- Optional TOTP second factor for OAuth sessions when `PATCH_API_ADMIN_TOTP_SECRET` is set
- Optional WebAuthn passkey second factor (Touch ID / platform passkey) for OAuth sessions once enrolled in `/admin/settings`
  - Passkeys require HTTPS and a WebAuthn-capable browser/device.

Admin pages:
- `/admin` main dashboard (agents + jobs)
- `/admin/automation` automation profile builder and run-now controls
- `/admin/seed-jobs` generic job seeding
- `/admin/install-agent` enrollment key + installer generation
- `/admin/settings` admin token storage and auth diagnostics

The admin UI can generate one-time enrollment keys, rename agents, show installed package inventory, queue package install jobs by platform (Windows, Linux, macOS), queue Windows PowerShell script jobs for Windows agents (including GCPW and Splashtop script templates), and manage automation profiles with recurring schedules and run-now execution.

Suggested nginx site:

```nginx
server {
    listen 80;
    server_name patch-api.local;
    root /Users/seankline/src/windows_patch_management_agent/backend/php-api/public;
    index index.php;

    location / {
        try_files $uri /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass 127.0.0.1:9000;
    }
}
```

Suggested next steps:
- Replace file storage with MySQL or PostgreSQL
- Add agent key rotation and signed enrollment flow
- Add admin APIs for job creation and rollout targeting

Job seeding example:

```bash
curl -X POST http://127.0.0.1:8080/v1/admin/jobs \
  -H 'Authorization: Bearer change-me-admin-key' \
  -H 'Content-Type: application/json' \
  --data '{
    "target_device_id": "dev-001",
    "type": "windows_update_install",
    "correlation_id": "lab-rollout-001",
    "policy": {
      "maintenance_window": {
        "start": "2026-03-20T01:00:00Z",
        "end": "2026-03-20T05:00:00Z"
      }
    },
    "payload": {
      "windows_update": {
        "install_all": false,
        "kbs": ["KB5039999"]
      }
    }
  }'
```

Windows PowerShell script job seeding example:

```bash
curl -X POST http://127.0.0.1:8080/v1/admin/jobs \
  -H 'Authorization: Bearer change-me-admin-key' \
  -H 'Content-Type: application/json' \
  --data '{
    "target_device_id": "win-node-001",
    "type": "windows_powershell_script",
    "correlation_id": "windows-provision-gcpw-001",
    "payload": {
      "windows_script": {
        "script_url": "https://patch.rrsaccess.com/scripts/provision.ps1"
      }
    }
  }'
```

Inline PowerShell script payload is also supported with:
- `payload.windows_script.script` (inline script content)
- `payload.windows_script.script_url` (download and execute script URL)

Ubuntu apt job seeding example:

```bash
curl -X POST http://127.0.0.1:8080/v1/admin/jobs \
  -H 'Authorization: Bearer change-me-admin-key' \
  -H 'Content-Type: application/json' \
  --data '{
    "target_device_id": "ubuntu-node-001",
    "type": "ubuntu_apt_upgrade",
    "correlation_id": "ubuntu-patch-window-001",
    "payload": {
      "apt": {
        "upgrade_all": true,
        "packages": ["curl", "openssl"]
      }
    }
  }'
```

macOS software update job seeding example:

```bash
curl -X POST http://127.0.0.1:8080/v1/admin/jobs \
  -H 'Authorization: Bearer change-me-admin-key' \
  -H 'Content-Type: application/json' \
  --data '{
    "target_device_id": "mac-studio-001",
    "type": "macos_software_update",
    "correlation_id": "macos-patch-window-001",
    "payload": {
      "macos_update": {
        "install_all": false,
        "labels": ["Safari17.5SonomaAuto-14.5"]
      }
    }
  }'
```

List seeded jobs:

```bash
curl http://127.0.0.1:8080/v1/admin/jobs \
  -H 'Authorization: Bearer change-me-admin-key'
```

Agent acknowledgement example:

```bash
curl -X POST http://127.0.0.1:8080/v1/agents/jobs/job_123/ack \
  -H 'Authorization: Bearer agent-token' \
  -H 'Content-Type: application/json' \
  --data '{
    "ack": "accepted",
    "acknowledged_at": "2026-03-24T14:00:00Z"
  }'
```

Agent completion example:

```bash
curl -X POST http://127.0.0.1:8080/v1/agents/jobs/job_123/complete \
  -H 'Authorization: Bearer agent-token' \
  -H 'Content-Type: application/json' \
  --data '{
    "final_state": "Succeeded",
    "completed_at": "2026-03-24T14:15:00Z",
    "result": {
      "install_result": "success",
      "reboot_required": true,
      "reboot_performed": true,
      "post_reboot_validation": "passed"
    }
  }'
```
