#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'EOF'
Uninstall WinPatchAgent from Ubuntu/Debian.

Usage:
  sudo ./scripts/uninstall_ubuntu_agent.sh [options]

Options:
  --service-name NAME          systemd unit/service name (default: winpatchagent)
  --install-dir PATH           Published app directory (default: /opt/winpatchagent)
  --state-dir PATH             Agent storage root (default: /var/lib/winpatchagent)
  --env-file PATH              Environment file path (default: /etc/winpatchagent/winpatchagent.env)
  --purge-state                Remove state directory contents
  --help                       Show this help
EOF
}

require_root() {
  if [[ "${EUID}" -ne 0 ]]; then
    echo "This script must be run as root (use sudo)." >&2
    exit 1
  fi
}

SERVICE_NAME="winpatchagent"
INSTALL_DIR="/opt/winpatchagent"
STATE_DIR="/var/lib/winpatchagent"
ENV_FILE="/etc/winpatchagent/winpatchagent.env"
PURGE_STATE="false"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --service-name)
      SERVICE_NAME="${2:-}"
      shift 2
      ;;
    --install-dir)
      INSTALL_DIR="${2:-}"
      shift 2
      ;;
    --state-dir)
      STATE_DIR="${2:-}"
      shift 2
      ;;
    --env-file)
      ENV_FILE="${2:-}"
      shift 2
      ;;
    --purge-state)
      PURGE_STATE="true"
      shift
      ;;
    --help|-h)
      usage
      exit 0
      ;;
    *)
      echo "Unknown option: $1" >&2
      usage
      exit 1
      ;;
  esac
done

require_root

SERVICE_FILE="/etc/systemd/system/${SERVICE_NAME}.service"

if command -v systemctl >/dev/null 2>&1; then
  if systemctl list-unit-files --type=service | grep -q "^${SERVICE_NAME}.service"; then
    echo "Stopping and disabling ${SERVICE_NAME}..."
    systemctl disable --now "${SERVICE_NAME}" || true
  fi
else
  echo "systemctl not found; skipping service stop/disable." >&2
fi

if [[ -f "${SERVICE_FILE}" ]]; then
  rm -f "${SERVICE_FILE}"
fi

if command -v systemctl >/dev/null 2>&1; then
  systemctl daemon-reload || true
fi

rm -rf "${INSTALL_DIR}"
rm -f "${ENV_FILE}"

ENV_DIR="$(dirname "${ENV_FILE}")"
if [[ -d "${ENV_DIR}" ]]; then
  rmdir "${ENV_DIR}" 2>/dev/null || true
fi

if [[ "${PURGE_STATE}" == "true" ]]; then
  rm -rf "${STATE_DIR}"
fi

echo
echo "Uninstall complete."
echo "Removed install directory: ${INSTALL_DIR}"
echo "Removed env file:         ${ENV_FILE}"

if [[ "${PURGE_STATE}" == "true" ]]; then
  echo "Removed state directory:  ${STATE_DIR}"
else
  echo "State directory retained: ${STATE_DIR}"
fi
