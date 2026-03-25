#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'EOF'
Install WinPatchAgent on macOS using launchd.

Usage:
  sudo ./scripts/install_macos_agent.sh --backend-url URL [options]

If an existing WinPatchAgent installation is detected, it is automatically
uninstalled first, then the new version is installed.

Required:
  --backend-url URL              Patch API base URL (for example: https://patch-api.example.com)

Optional:
  --enrollment-key KEY           Enrollment key for agent registration
  --service-label LABEL          launchd label (default: com.winpatchagent.agent)
  --install-dir PATH             Published app directory (default: /usr/local/lib/winpatchagent)
  --state-dir PATH               Agent storage root (default: /usr/local/var/winpatchagent/state)
  --logs-dir PATH                Agent log directory (default: /usr/local/var/log/winpatchagent)
  --runtime RID                  dotnet publish runtime (default: auto-detected osx-arm64/osx-x64)
  --configuration CONFIG         dotnet publish configuration (default: Release)
  --self-contained               Publish self-contained binary (default: false)
  --mac-timeout-seconds N        softwareupdate command timeout seconds (default: 5400)
  --loop-delay-seconds N         Agent loop delay seconds (default: 15)
  --job-poll-seconds N           Job poll interval seconds (default: 120)
  --heartbeat-seconds N          Heartbeat interval seconds (default: 300)
  --inventory-seconds N          Inventory interval seconds (default: 21600)
  --help                         Show this help
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

detect_runtime() {
  local arch
  arch="$(uname -m)"
  case "${arch}" in
    arm64)
      echo "osx-arm64"
      ;;
    x86_64)
      echo "osx-x64"
      ;;
    *)
      echo "Unsupported macOS architecture: ${arch}" >&2
      exit 1
      ;;
  esac
}

get_project_version() {
  local dotnet_bin="$1"
  local project_path="$2"
  local version=""

  version="$(
    "${dotnet_bin}" msbuild "${project_path}" -nologo -getProperty:Version 2>/dev/null \
      | awk 'NF { value=$0 } END { print value }' \
      | tr -d '\r'
  )" || true

  if [[ -z "${version}" ]]; then
    version="unknown"
  fi

  printf '%s' "${version}"
}

resolve_dotnet_bin() {
  if command -v dotnet >/dev/null 2>&1; then
    command -v dotnet
    return 0
  fi

  local candidates=(
    "/opt/homebrew/bin/dotnet"
    "/usr/local/bin/dotnet"
    "/usr/local/share/dotnet/dotnet"
  )

  local candidate=""
  for candidate in "${candidates[@]}"; do
    if [[ -x "${candidate}" ]]; then
      echo "${candidate}"
      return 0
    fi
  done

  return 1
}

detect_existing_install() {
  local plist_path="$1"
  local install_dir="$2"

  if [[ -f "${plist_path}" ]]; then
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
SERVICE_LABEL="com.winpatchagent.agent"
INSTALL_DIR="/usr/local/lib/winpatchagent"
STATE_DIR="/usr/local/var/winpatchagent/state"
LOGS_DIR="/usr/local/var/log/winpatchagent"
RUNTIME=""
CONFIGURATION="Release"
SELF_CONTAINED="false"
MAC_TIMEOUT_SECONDS="5400"
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
    --mac-timeout-seconds)
      MAC_TIMEOUT_SECONDS="${2:-}"
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

if [[ -z "${RUNTIME}" ]]; then
  RUNTIME="$(detect_runtime)"
fi

require_root
require_command launchctl

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
PROJECT_PATH="${REPO_ROOT}/src/PatchAgent.Service/PatchAgent.Service.csproj"
PLIST_PATH="/Library/LaunchDaemons/${SERVICE_LABEL}.plist"
VERSION_FILE="${INSTALL_DIR}/.winpatchagent-version"
UNINSTALL_SCRIPT="${SCRIPT_DIR}/uninstall_macos_agent.sh"
STDOUT_LOG="${LOGS_DIR}/winpatchagent.stdout.log"
STDERR_LOG="${LOGS_DIR}/winpatchagent.stderr.log"

if ! DOTNET_PATH="$(resolve_dotnet_bin)"; then
  echo "dotnet SDK 8+ is required but was not found in PATH." >&2
  echo "Install it (for example with Homebrew), then rerun this script." >&2
  exit 1
fi

if [[ ! -f "${PROJECT_PATH}" ]]; then
  echo "Could not find project file: ${PROJECT_PATH}" >&2
  exit 1
fi

TARGET_VERSION="$(get_project_version "${DOTNET_PATH}" "${PROJECT_PATH}")"
INSTALLED_VERSION="unknown"

if [[ -f "${VERSION_FILE}" ]]; then
  INSTALLED_VERSION="$(head -n 1 "${VERSION_FILE}" | tr -d '\r' || true)"
  if [[ -z "${INSTALLED_VERSION}" ]]; then
    INSTALLED_VERSION="unknown"
  fi
fi

if detect_existing_install "${PLIST_PATH}" "${INSTALL_DIR}"; then
  if [[ ! -f "${UNINSTALL_SCRIPT}" ]]; then
    echo "Detected existing installation but uninstall script was not found: ${UNINSTALL_SCRIPT}" >&2
    exit 1
  fi

  echo "Existing WinPatchAgent installation detected (installed version: ${INSTALLED_VERSION})."
  echo "Removing old installation before installing version ${TARGET_VERSION}..."

  bash "${UNINSTALL_SCRIPT}" \
    --service-label "${SERVICE_LABEL}" \
    --install-dir "${INSTALL_DIR}" \
    --state-dir "${STATE_DIR}" \
    --logs-dir "${LOGS_DIR}"
fi

mkdir -p "${INSTALL_DIR}" "${STATE_DIR}" "${LOGS_DIR}"

echo "Publishing agent to ${INSTALL_DIR}..."
"${DOTNET_PATH}" publish "${PROJECT_PATH}" \
  -c "${CONFIGURATION}" \
  -r "${RUNTIME}" \
  --self-contained "${SELF_CONTAINED}" \
  -o "${INSTALL_DIR}"

EXECUTABLE_PATH="${INSTALL_DIR}/PatchAgent.Service"
EXEC_MODE="dotnet"

if [[ "${SELF_CONTAINED}" == "true" && -x "${EXECUTABLE_PATH}" ]]; then
  EXEC_MODE="self-contained"
fi

CONFIG_PATH="${INSTALL_DIR}/appsettings.Production.json"
cat > "${CONFIG_PATH}" <<EOF
{
  "Agent": {
    "ServiceName": "${SERVICE_LABEL}",
    "BackendBaseUrl": "${BACKEND_URL}",
    "EnrollmentKey": "${ENROLLMENT_KEY}",
    "AgentChannel": "stable",
    "StorageRoot": "${STATE_DIR}",
    "RequestTimeoutSeconds": 30,
    "LoopDelaySeconds": ${LOOP_DELAY_SECONDS},
    "HeartbeatIntervalSeconds": ${HEARTBEAT_SECONDS},
    "InventoryIntervalSeconds": ${INVENTORY_SECONDS},
    "JobPollIntervalSeconds": ${JOB_POLL_SECONDS},
    "EnableStubJobExecution": true,
    "StubJobDurationSeconds": 20,
    "EnableAptJobExecution": false,
    "EnableWindowsUpdateJobExecution": false,
    "EnableMacSoftwareUpdateJobExecution": true,
    "MacSoftwareUpdateCommandTimeoutSeconds": ${MAC_TIMEOUT_SECONDS},
    "AptUseSudoWhenNotRoot": true,
    "AptRunUpdateBeforeInstall": true,
    "AptCommandTimeoutSeconds": 1800
  }
}
EOF

chmod 640 "${CONFIG_PATH}"

if [[ "${EXEC_MODE}" == "self-contained" ]]; then
  cat > "${PLIST_PATH}" <<EOF
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
  <key>Label</key>
  <string>${SERVICE_LABEL}</string>
  <key>ProgramArguments</key>
  <array>
    <string>${EXECUTABLE_PATH}</string>
  </array>
  <key>WorkingDirectory</key>
  <string>${INSTALL_DIR}</string>
  <key>RunAtLoad</key>
  <true/>
  <key>KeepAlive</key>
  <true/>
  <key>EnvironmentVariables</key>
  <dict>
    <key>DOTNET_ENVIRONMENT</key>
    <string>Production</string>
  </dict>
  <key>StandardOutPath</key>
  <string>${STDOUT_LOG}</string>
  <key>StandardErrorPath</key>
  <string>${STDERR_LOG}</string>
</dict>
</plist>
EOF
else
  cat > "${PLIST_PATH}" <<EOF
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
  <key>Label</key>
  <string>${SERVICE_LABEL}</string>
  <key>ProgramArguments</key>
  <array>
    <string>${DOTNET_PATH}</string>
    <string>${INSTALL_DIR}/PatchAgent.Service.dll</string>
  </array>
  <key>WorkingDirectory</key>
  <string>${INSTALL_DIR}</string>
  <key>RunAtLoad</key>
  <true/>
  <key>KeepAlive</key>
  <true/>
  <key>EnvironmentVariables</key>
  <dict>
    <key>DOTNET_ENVIRONMENT</key>
    <string>Production</string>
  </dict>
  <key>StandardOutPath</key>
  <string>${STDOUT_LOG}</string>
  <key>StandardErrorPath</key>
  <string>${STDERR_LOG}</string>
</dict>
</plist>
EOF
fi

chown root:wheel "${PLIST_PATH}"
chmod 644 "${PLIST_PATH}"

launchctl bootout "system/${SERVICE_LABEL}" >/dev/null 2>&1 || true
launchctl bootstrap system "${PLIST_PATH}"
launchctl kickstart -k "system/${SERVICE_LABEL}" >/dev/null 2>&1 || true

echo "${TARGET_VERSION}" > "${VERSION_FILE}"
chmod 644 "${VERSION_FILE}"

echo
echo "Install complete."
echo "Service label: ${SERVICE_LABEL}"
echo "Status: launchctl print system/${SERVICE_LABEL}"
echo "Logs:   tail -f ${STDOUT_LOG} ${STDERR_LOG}"
