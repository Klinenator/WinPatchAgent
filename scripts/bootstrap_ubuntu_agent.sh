#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'EOF'
Bootstrap WinPatchAgent on Ubuntu/Debian with one command.

Usage:
  sudo bash bootstrap_ubuntu_agent.sh --backend-url URL [options] [-- <install-script-options>]

Required:
  --backend-url URL            Patch API base URL (for example: https://patch-api.example.com)

Optional:
  --enrollment-key KEY         Enrollment key for agent registration
  --repo-url URL               Git repository URL (default: https://github.com/Klinenator/WinPatchAgent.git)
  --repo-ref REF               Git branch/tag/commit to deploy (default: main)
  --work-dir PATH              Source checkout path (default: /opt/winpatchagent-src)
  --help                       Show this help

Extra install options:
  Any args after `--` are passed to scripts/install_ubuntu_agent.sh.

Example:
  sudo bash bootstrap_ubuntu_agent.sh \
    --backend-url https://patch-api.example.com \
    --enrollment-key change-me \
    -- --service-name winpatchagent --self-contained
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

apt_install() {
  DEBIAN_FRONTEND=noninteractive apt-get install -y "$@"
}

configure_dotnet_repo() {
  if [[ ! -r /etc/os-release ]]; then
    echo "Cannot read /etc/os-release to configure dotnet apt repository." >&2
    exit 1
  fi

  # shellcheck source=/dev/null
  source /etc/os-release

  local distro=""
  case "${ID}" in
    ubuntu)
      distro="ubuntu/${VERSION_ID}"
      ;;
    debian)
      distro="debian/${VERSION_ID}"
      ;;
    *)
      echo "Unsupported Linux distribution for automatic dotnet setup: ${ID}" >&2
      echo "Install dotnet-sdk-8.0 manually, then rerun this script." >&2
      exit 1
      ;;
  esac

  install -m 0755 -d /etc/apt/keyrings
  if [[ ! -f /etc/apt/keyrings/microsoft-prod.gpg ]]; then
    curl -fsSL https://packages.microsoft.com/keys/microsoft.asc \
      | gpg --dearmor \
      | tee /etc/apt/keyrings/microsoft-prod.gpg >/dev/null
    chmod 0644 /etc/apt/keyrings/microsoft-prod.gpg
  fi

  cat > /etc/apt/sources.list.d/microsoft-prod.list <<EOF
deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/microsoft-prod.gpg] https://packages.microsoft.com/${distro}/prod ${VERSION_CODENAME} main
EOF
}

ensure_dotnet_sdk() {
  if command -v dotnet >/dev/null 2>&1; then
    return
  fi

  echo "dotnet not found; installing dotnet-sdk-8.0..."
  apt-get update
  apt_install ca-certificates curl gpg
  configure_dotnet_repo
  apt-get update
  apt_install dotnet-sdk-8.0
}

BACKEND_URL=""
ENROLLMENT_KEY=""
REPO_URL="https://github.com/Klinenator/WinPatchAgent.git"
REPO_REF="main"
WORK_DIR="/opt/winpatchagent-src"
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
    --repo-url)
      REPO_URL="${2:-}"
      shift 2
      ;;
    --repo-ref)
      REPO_REF="${2:-}"
      shift 2
      ;;
    --work-dir)
      WORK_DIR="${2:-}"
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
require_command apt-get
require_command git
require_command curl
require_command systemctl

ensure_dotnet_sdk

if [[ -d "${WORK_DIR}" && ! -d "${WORK_DIR}/.git" ]]; then
  echo "Work directory exists but is not a git repository: ${WORK_DIR}" >&2
  echo "Choose another --work-dir or remove the existing directory." >&2
  exit 1
fi

if [[ -d "${WORK_DIR}/.git" ]]; then
  echo "Updating existing checkout at ${WORK_DIR}..."
  git -C "${WORK_DIR}" remote set-url origin "${REPO_URL}"
  git -C "${WORK_DIR}" fetch --depth 1 origin "${REPO_REF}"
  git -C "${WORK_DIR}" checkout -B "${REPO_REF}" FETCH_HEAD
else
  echo "Cloning ${REPO_URL} (${REPO_REF}) into ${WORK_DIR}..."
  git clone --depth 1 --branch "${REPO_REF}" "${REPO_URL}" "${WORK_DIR}"
fi

INSTALL_CMD=(
  bash
  "${WORK_DIR}/scripts/install_ubuntu_agent.sh"
  --backend-url "${BACKEND_URL}"
)

if [[ -n "${ENROLLMENT_KEY}" ]]; then
  INSTALL_CMD+=(--enrollment-key "${ENROLLMENT_KEY}")
fi

if [[ ${#INSTALLER_EXTRA_ARGS[@]} -gt 0 ]]; then
  INSTALL_CMD+=("${INSTALLER_EXTRA_ARGS[@]}")
fi

"${INSTALL_CMD[@]}"
