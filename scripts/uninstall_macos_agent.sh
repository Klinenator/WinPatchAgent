#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'EOF'
Uninstall WinPatchAgent from macOS.

Usage:
  sudo ./scripts/uninstall_macos_agent.sh [options]

Options:
  --service-label LABEL          launchd label (default: com.winpatchagent.agent)
  --install-dir PATH             Published app directory (default: /usr/local/lib/winpatchagent)
  --state-dir PATH               Agent storage root (default: /usr/local/var/winpatchagent/state)
  --logs-dir PATH                Agent log directory (default: /usr/local/var/log/winpatchagent)
  --purge-state                  Remove state directory contents
  --help                         Show this help
EOF
}

require_root() {
  if [[ "${EUID}" -ne 0 ]]; then
    echo "This script must be run as root (use sudo)." >&2
    exit 1
  fi
}

SERVICE_LABEL="com.winpatchagent.agent"
INSTALL_DIR="/usr/local/lib/winpatchagent"
STATE_DIR="/usr/local/var/winpatchagent/state"
LOGS_DIR="/usr/local/var/log/winpatchagent"
PURGE_STATE="false"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --service-label)
      SERVICE_LABEL="${2:-}"
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
    --logs-dir)
      LOGS_DIR="${2:-}"
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

PLIST_PATH="/Library/LaunchDaemons/${SERVICE_LABEL}.plist"

if command -v launchctl >/dev/null 2>&1; then
  launchctl bootout "system/${SERVICE_LABEL}" >/dev/null 2>&1 || true
else
  echo "launchctl not found; skipping service stop/unload." >&2
fi

if [[ -f "${PLIST_PATH}" ]]; then
  rm -f "${PLIST_PATH}"
fi

rm -rf "${INSTALL_DIR}"
rm -rf "${LOGS_DIR}"

if [[ "${PURGE_STATE}" == "true" ]]; then
  rm -rf "${STATE_DIR}"
fi

echo
echo "Uninstall complete."
echo "Removed install directory: ${INSTALL_DIR}"
echo "Removed logs directory:   ${LOGS_DIR}"

if [[ "${PURGE_STATE}" == "true" ]]; then
  echo "Removed state directory:  ${STATE_DIR}"
else
  echo "State directory retained: ${STATE_DIR}"
fi
