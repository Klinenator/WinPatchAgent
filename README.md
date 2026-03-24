# WinPatchAgent (Windows + Ubuntu)

Patch management scaffold with:
- A `.NET` endpoint agent (`src/PatchAgent.Service/`)
- A minimal PHP API backend (`backend/php-api/`) designed for nginx + PHP-FPM
- Job seeding endpoints for both Windows update and Ubuntu apt-based patch jobs

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

## Backend (PHP + nginx)

API scaffold is under `backend/php-api/`.
It now includes a basic admin web view at `/admin` for viewing agents, generating installer links, and seeding/listing jobs.
Admin access supports Google OAuth login (`/admin/login`) and/or admin bearer token auth.
The admin UI also supports agent renaming, viewing installed package inventory, and queuing package install jobs (Windows updates).
Admin pages are split into `/admin` (main), `/admin/seed-jobs`, and `/admin/install-agent`.

Local dev run:

```bash
cd backend/php-api
php -S 127.0.0.1:8080 -t public
```

See backend details and endpoints in:
- `backend/php-api/README.md`
- `docs/php-backend-api.md`
