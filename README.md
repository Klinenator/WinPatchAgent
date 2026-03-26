# WinPatchAgent (Windows + Ubuntu + macOS)

Patch management scaffold with:
- A `.NET` endpoint agent (`src/PatchAgent.Service/`)
- A minimal PHP API backend (`backend/php-api/`) designed for nginx + PHP-FPM
- Job seeding endpoints for Windows Update, Ubuntu apt, macOS softwareupdate, Windows PowerShell scripts, macOS shell scripts, and cross-platform software install jobs

## Fast Start: Ubuntu Agent

### Recommended: clone and run one script

```bash
git clone https://github.com/Klinenator/WinPatchAgent.git
cd WinPatchAgent
sudo bash ./scripts/setup_ubuntu_agent.sh \
  --backend-url https://patch-api.example.com \
  --enrollment-key change-me
```

`setup_ubuntu_agent.sh` installs dotnet SDK if needed, publishes the agent, and registers a `systemd` service.
If an existing agent install is detected, it is automatically uninstalled and replaced.

Optional: pass extra installer args after `--`:

```bash
sudo bash ./scripts/setup_ubuntu_agent.sh \
  --backend-url https://patch-api.example.com \
  -- --service-name winpatchagent --self-contained
```

### Alternate path (bootstrap script)

Run this on an Ubuntu/Debian host as root. It installs dotnet SDK if needed, clones the repo, publishes the agent, and registers a `systemd` service:

```bash
curl -fsSL https://raw.githubusercontent.com/Klinenator/WinPatchAgent/main/scripts/bootstrap_ubuntu_agent.sh -o /tmp/bootstrap_winpatchagent.sh
sudo bash /tmp/bootstrap_winpatchagent.sh \
  --backend-url https://patch-api.example.com \
  --enrollment-key change-me
```

Optional: pass extra installer args after `--`:

```bash
sudo bash /tmp/bootstrap_winpatchagent.sh \
  --backend-url https://patch-api.example.com \
  -- --service-name winpatchagent --self-contained
```

### Install from local checkout (direct installer only)

```bash
cd /path/to/WinPatchAgent
sudo bash ./scripts/install_ubuntu_agent.sh \
  --backend-url https://patch-api.example.com \
  --enrollment-key change-me
```

### Uninstall from Ubuntu/Debian

```bash
cd /path/to/WinPatchAgent
sudo bash ./scripts/uninstall_ubuntu_agent.sh
```

To also delete persisted agent state:

```bash
sudo bash ./scripts/uninstall_ubuntu_agent.sh --purge-state
```

## Fast Start: macOS Agent

```bash
git clone https://github.com/Klinenator/WinPatchAgent.git
cd WinPatchAgent
sudo bash ./scripts/setup_macos_agent.sh \
  --backend-url https://patch-api.example.com \
  --enrollment-key change-me
```

`setup_macos_agent.sh` publishes the agent and registers a `launchd` service.
If an existing agent install is detected, it is automatically uninstalled and replaced.

Optional: pass extra installer args after `--`:

```bash
sudo bash ./scripts/setup_macos_agent.sh \
  --backend-url https://patch-api.example.com \
  -- --service-label com.winpatchagent.agent --self-contained
```

### Uninstall from macOS

```bash
cd /path/to/WinPatchAgent
sudo bash ./scripts/uninstall_macos_agent.sh
```

To also delete persisted agent state:

```bash
sudo bash ./scripts/uninstall_macos_agent.sh --purge-state
```

## Build Windows Prebuilt Package

Use this from a Windows machine with .NET 8 SDK:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\package_windows_agent.ps1
```

Default output:
- `artifacts/winpatchagent-windows-x64.zip`

Publish that zip as your GitHub Release asset (same filename), or host it at your own HTTPS URL and set `PATCH_API_WINDOWS_AGENT_PACKAGE_URL` on the API server.

## Backend (PHP + nginx)

API scaffold is under `backend/php-api/`.
It now includes a basic admin web view at `/admin` for viewing agents, generating installer links, and seeding/listing jobs.
Admin access supports Google OAuth login (`/admin/login`) and/or admin bearer token auth, with optional TOTP second factor and optional Touch ID/passkey (WebAuthn) as an MFA alternative.
The admin UI also supports agent renaming, viewing installed package inventory, and queuing package install jobs by platform.
It now also supports software inventory (installed applications) and cross-platform software installation jobs (`software_install`) using Winget (Windows), APT (Linux), and Homebrew (macOS).
Linux available package inventory is now CVE-enriched through OSV lookups (Ubuntu/Debian) with on-disk caching on the API server.
It now also supports queueing script jobs for Windows and macOS (inline script or script URL), including built-in GCPW and Splashtop install templates.
It now also supports per-agent self-update jobs (`agent_self_update`) from the main admin page.
Server-generated endpoint installers and self-update workflows now pull artifacts over HTTPS (curl/wget/Invoke-WebRequest), so Git is not required on endpoints.
It also includes an agent-row `Connect` button that launches the Splashtop Business app URI for Windows/macOS agents.
If you set `PATCH_API_WINDOWS_SPLASHTOP_MSI_URL` on the API server, Windows agent installs from `/install/windows.ps1` will auto-install Splashtop during provisioning. The URL can point to either an Easy Deployment `.exe` installer or a deployable `.msi`.
Set `PATCH_API_WINDOWS_AGENT_PACKAGE_URL` on the API server to your published Windows agent zip (default points to GitHub Releases latest asset `winpatchagent-windows-x64.zip`).
Windows installs can enforce SOC2 baseline controls by default (`PATCH_API_WINDOWS_DISABLE_REMOVABLE_STORAGE_ON_INSTALL=true`, `PATCH_API_WINDOWS_ENSURE_DEFENDER_ON_INSTALL=true`), with env toggles to opt out per environment.
The admin main page now surfaces a Windows SOC2 baseline status card (Defender service/realtime, firewall profiles, removable storage policy, and BitLocker state). BitLocker is marked not-supported on editions where it is unavailable.
The admin main page also provides one-click SOC2 evidence exports (JSON/CSV) suitable for audit packages.
Windows install links from `/install/windows.ps1` support `mode=prebuilt` (default, no endpoint compile) or `mode=source` (compile on endpoint after downloading source).
Admin pages are split into `/admin` (main), `/admin/automation`, `/admin/seed-jobs`, `/admin/install-agent`, and `/admin/settings`.

Local dev run:

```bash
cd backend/php-api
php -S 127.0.0.1:8080 -t public
```

See backend details and endpoints in:
- `backend/php-api/README.md`
- `docs/php-backend-api.md`
- `docs/server-pull-and-permissions.md` (server pull + permission fix runbook)
- `docs/examples/patchapi-secrets.conf.example` (nginx `fastcgi_param` secrets template for `/etc/winpatchagent/`)
