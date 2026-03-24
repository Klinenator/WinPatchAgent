#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'EOF'
Install WinPatchAgent on Ubuntu using systemd.

Usage:
  sudo ./scripts/install_ubuntu_agent.sh --backend-url URL [options]

If an existing WinPatchAgent installation is detected, it is automatically
uninstalled first, then the new version is installed.

Required:
  --backend-url URL            Patch API base URL (for example: https://patch-api.example.com)

Optional:
  --enrollment-key KEY         Enrollment key for agent registration
  --service-name NAME          systemd unit/service name (default: winpatchagent)
  --install-dir PATH           Published app directory (default: /opt/winpatchagent)
  --state-dir PATH             Agent storage root (default: /var/lib/winpatchagent)
  --env-file PATH              Environment file path (default: /etc/winpatchagent/winpatchagent.env)
  --runtime RID                dotnet publish runtime (default: linux-x64)
  --configuration CONFIG       dotnet publish configuration (default: Release)
  --self-contained             Publish self-contained binary (default: false)
  --apt-use-sudo true|false    Use sudo -n for apt when agent is not root (default: true)
  --apt-run-update true|false  Run apt update before install/upgrade (default: true)
  --apt-timeout-seconds N      apt command timeout seconds (default: 1800)
  --loop-delay-seconds N       Agent loop delay seconds (default: 15)
  --job-poll-seconds N         Job poll interval seconds (default: 120)
  --heartbeat-seconds N        Heartbeat interval seconds (default: 300)
  --inventory-seconds N        Inventory interval seconds (default: 21600)
  --help                       Show this help
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

bool_or_die() {
  local value="$1"
  local name="$2"
  case "${value}" in
    true|false) ;;
    *)
      echo "Invalid value for ${name}: ${value} (expected true or false)" >&2
      exit 1
      ;;
  esac
}

get_project_version() {
  local project_path="$1"
  local version=""

  version="$(
    dotnet msbuild "${project_path}" -nologo -getProperty:Version 2>/dev/null \
      | awk 'NF { value=$0 } END { print value }' \
      | tr -d '\r'
  )" || true

  if [[ -z "${version}" ]]; then
    version="unknown"
  fi

  printf '%s' "${version}"
}

detect_existing_install() {
  local service_file="$1"
  local install_dir="$2"

  if [[ -f "${service_file}" ]]; then
    return 0
  fi

  if [[ -f "${install_dir}/PatchAgent.Service.dll" ]]; then
    return 0
  fi

  if [[ -x "${install_dir}/PatchAgent.Service" ]]; then
    return 0
  fi

  return 1
}

BACKEND_URL=""
ENROLLMENT_KEY=""
SERVICE_NAME="winpatchagent"
INSTALL_DIR="/opt/winpatchagent"
STATE_DIR="/var/lib/winpatchagent"
ENV_FILE="/etc/winpatchagent/winpatchagent.env"
RUNTIME="linux-x64"
CONFIGURATION="Release"
SELF_CONTAINED="false"
APT_USE_SUDO_WHEN_NOT_ROOT="true"
APT_RUN_UPDATE_BEFORE_INSTALL="true"
APT_COMMAND_TIMEOUT_SECONDS="1800"
LOOP_DELAY_SECONDS="15"
JOB_POLL_SECONDS="120"
HEARTBEAT_SECONDS="300"
INVENTORY_SECONDS="21600"

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
    --runtime)
      RUNTIME="${2:-}"
      shift 2
      ;;
    --configuration)
      CONFIGURATION="${2:-}"
      shift 2
      ;;
    --self-contained)
      SELF_CONTAINED="true"
      shift
      ;;
    --apt-use-sudo)
      APT_USE_SUDO_WHEN_NOT_ROOT="${2:-}"
      shift 2
      ;;
    --apt-run-update)
      APT_RUN_UPDATE_BEFORE_INSTALL="${2:-}"
      shift 2
      ;;
    --apt-timeout-seconds)
      APT_COMMAND_TIMEOUT_SECONDS="${2:-}"
      shift 2
      ;;
    --loop-delay-seconds)
      LOOP_DELAY_SECONDS="${2:-}"
      shift 2
      ;;
    --job-poll-seconds)
      JOB_POLL_SECONDS="${2:-}"
      shift 2
      ;;
    --heartbeat-seconds)
      HEARTBEAT_SECONDS="${2:-}"
      shift 2
      ;;
    --inventory-seconds)
      INVENTORY_SECONDS="${2:-}"
      shift 2
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

if [[ -z "${BACKEND_URL}" ]]; then
  echo "--backend-url is required" >&2
  usage
  exit 1
fi

bool_or_die "${APT_USE_SUDO_WHEN_NOT_ROOT}" "--apt-use-sudo"
bool_or_die "${APT_RUN_UPDATE_BEFORE_INSTALL}" "--apt-run-update"

require_root
require_command dotnet
require_command systemctl

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
PROJECT_PATH="${REPO_ROOT}/src/PatchAgent.Service/PatchAgent.Service.csproj"
DOTNET_PATH="$(command -v dotnet)"
SERVICE_FILE="/etc/systemd/system/${SERVICE_NAME}.service"
EXEC_START="${DOTNET_PATH} ${INSTALL_DIR}/PatchAgent.Service.dll"
VERSION_FILE="${INSTALL_DIR}/.winpatchagent-version"
UNINSTALL_SCRIPT="${SCRIPT_DIR}/uninstall_ubuntu_agent.sh"

if [[ ! -f "${PROJECT_PATH}" ]]; then
  echo "Could not find project file: ${PROJECT_PATH}" >&2
  exit 1
fi

TARGET_VERSION="$(get_project_version "${PROJECT_PATH}")"
INSTALLED_VERSION="unknown"

if [[ -f "${VERSION_FILE}" ]]; then
  INSTALLED_VERSION="$(head -n 1 "${VERSION_FILE}" | tr -d '\r' || true)"
  if [[ -z "${INSTALLED_VERSION}" ]]; then
    INSTALLED_VERSION="unknown"
  fi
fi

if detect_existing_install "${SERVICE_FILE}" "${INSTALL_DIR}"; then
  if [[ ! -f "${UNINSTALL_SCRIPT}" ]]; then
    echo "Detected existing installation but uninstall script was not found: ${UNINSTALL_SCRIPT}" >&2
    exit 1
  fi

  echo "Existing WinPatchAgent installation detected (installed version: ${INSTALLED_VERSION})."
  echo "Removing old installation before installing version ${TARGET_VERSION}..."

  bash "${UNINSTALL_SCRIPT}" \
    --service-name "${SERVICE_NAME}" \
    --install-dir "${INSTALL_DIR}" \
    --state-dir "${STATE_DIR}" \
    --env-file "${ENV_FILE}"
fi

mkdir -p "${INSTALL_DIR}" "${STATE_DIR}" "$(dirname "${ENV_FILE}")"

echo "Publishing agent to ${INSTALL_DIR}..."
dotnet publish "${PROJECT_PATH}" \
  -c "${CONFIGURATION}" \
  -r "${RUNTIME}" \
  --self-contained "${SELF_CONTAINED}" \
  -o "${INSTALL_DIR}"

if [[ "${SELF_CONTAINED}" == "true" && -x "${INSTALL_DIR}/PatchAgent.Service" ]]; then
  EXEC_START="${INSTALL_DIR}/PatchAgent.Service"
fi

echo "${TARGET_VERSION}" > "${VERSION_FILE}"
chmod 644 "${VERSION_FILE}"

cat > "${ENV_FILE}" <<EOF
PATCHAGENT_Agent__BackendBaseUrl=${BACKEND_URL}
PATCHAGENT_Agent__StorageRoot=${STATE_DIR}
PATCHAGENT_Agent__LoopDelaySeconds=${LOOP_DELAY_SECONDS}
PATCHAGENT_Agent__JobPollIntervalSeconds=${JOB_POLL_SECONDS}
PATCHAGENT_Agent__HeartbeatIntervalSeconds=${HEARTBEAT_SECONDS}
PATCHAGENT_Agent__InventoryIntervalSeconds=${INVENTORY_SECONDS}
PATCHAGENT_Agent__EnableAptJobExecution=true
PATCHAGENT_Agent__AptUseSudoWhenNotRoot=${APT_USE_SUDO_WHEN_NOT_ROOT}
PATCHAGENT_Agent__AptRunUpdateBeforeInstall=${APT_RUN_UPDATE_BEFORE_INSTALL}
PATCHAGENT_Agent__AptCommandTimeoutSeconds=${APT_COMMAND_TIMEOUT_SECONDS}
EOF

if [[ -n "${ENROLLMENT_KEY}" ]]; then
  echo "PATCHAGENT_Agent__EnrollmentKey=${ENROLLMENT_KEY}" >> "${ENV_FILE}"
fi

chmod 640 "${ENV_FILE}"

cat > "${SERVICE_FILE}" <<EOF
[Unit]
Description=WinPatchAgent Service
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
EnvironmentFile=${ENV_FILE}
WorkingDirectory=${INSTALL_DIR}
ExecStart=${EXEC_START}
Restart=always
RestartSec=5
User=root

[Install]
WantedBy=multi-user.target
EOF

echo "Reloading systemd and starting ${SERVICE_NAME}..."
systemctl daemon-reload
systemctl enable --now "${SERVICE_NAME}"

echo
echo "Install complete."
echo "Service: ${SERVICE_NAME}"
echo "Status: systemctl status ${SERVICE_NAME}"
echo "Logs:   journalctl -u ${SERVICE_NAME} -f"
