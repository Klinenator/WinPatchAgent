#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'EOF'
Setup WinPatchAgent on macOS from a local git clone.

Usage:
  sudo bash ./scripts/setup_macos_agent.sh --backend-url URL [options] [-- <install-script-options>]

Required:
  --backend-url URL            Patch API base URL (for example: https://patch-api.example.com)

Optional:
  --enrollment-key KEY         Enrollment key for agent registration
  --help                       Show this help

Extra install options:
  Any args after `--` are passed to scripts/install_macos_agent.sh.

Examples:
  sudo bash ./scripts/setup_macos_agent.sh \
    --backend-url https://patch-api.example.com \
    --enrollment-key change-me

  sudo bash ./scripts/setup_macos_agent.sh \
    --backend-url https://patch-api.example.com \
    -- --service-label com.winpatchagent.agent --self-contained
EOF
}

require_root() {
  if [[ "${EUID}" -ne 0 ]]; then
    echo "This script must be run as root (use sudo)." >&2
    exit 1
  fi
}

require_command() {
  local cmd="$1"
  if ! command -v "${cmd}" >/dev/null 2>&1; then
    echo "Missing required command: ${cmd}" >&2
    exit 1
  fi
}

BACKEND_URL=""
ENROLLMENT_KEY=""
INSTALLER_EXTRA_ARGS=()

while [[ $# -gt 0 ]]; do
  case "$1" in
    --backend-url)
      BACKEND_URL="${2:-}"
      shift 2
      ;;
    --enrollment-key)
      ENROLLMENT_KEY="${2:-}"
      shift 2
      ;;
    --help|-h)
      usage
      exit 0
      ;;
    --)
      shift
      INSTALLER_EXTRA_ARGS=("$@")
      break
      ;;
    *)
      echo "Unknown option: $1" >&2
      usage
      exit 1
      ;;
  esac
done

if [[ -z "${BACKEND_URL}" ]]; then
  echo "--backend-url is required" >&2
  usage
  exit 1
fi

require_root
require_command git
require_command launchctl

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
INSTALL_SCRIPT="${SCRIPT_DIR}/install_macos_agent.sh"

if [[ ! -f "${INSTALL_SCRIPT}" ]]; then
  echo "Expected install script not found: ${INSTALL_SCRIPT}" >&2
  exit 1
fi

INSTALL_CMD=(
  bash
  "${INSTALL_SCRIPT}"
  --backend-url "${BACKEND_URL}"
)

if [[ -n "${ENROLLMENT_KEY}" ]]; then
  INSTALL_CMD+=(--enrollment-key "${ENROLLMENT_KEY}")
fi

if [[ ${#INSTALLER_EXTRA_ARGS[@]} -gt 0 ]]; then
  INSTALL_CMD+=("${INSTALLER_EXTRA_ARGS[@]}")
fi

"${INSTALL_CMD[@]}"
