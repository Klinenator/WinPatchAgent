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
  --repo-url URL               Repository URL (default: https://github.com/Klinenator/WinPatchAgent.git)
  --repo-ref REF               Branch/tag/commit to deploy (default: main)
  --work-dir PATH              Extracted source path (default: /opt/winpatchagent-src)
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
require_command tar
require_command systemctl

ensure_dotnet_sdk

if ! command -v curl >/dev/null 2>&1 && ! command -v wget >/dev/null 2>&1; then
  apt-get update
  apt_install ca-certificates curl wget
fi

normalize_repo_url() {
  local raw="$1"
  raw="${raw%/}"
  if [[ "${raw}" == git@github.com:* ]]; then
    raw="https://github.com/${raw#git@github.com:}"
  fi
  raw="${raw%.git}"
  printf '%s' "${raw}"
}

build_archive_url() {
  local repo_http
  repo_http="$(normalize_repo_url "$1")"
  printf '%s/archive/%s.tar.gz' "${repo_http}" "$2"
}

download_file() {
  local url="$1"
  local output="$2"
  if command -v wget >/dev/null 2>&1; then
    wget -qO "${output}" "${url}"
    return 0
  fi
  curl -fsSL "${url}" -o "${output}"
}

ARCHIVE_URL="$(build_archive_url "${REPO_URL}" "${REPO_REF}")"
TMP_ARCHIVE="$(mktemp /tmp/winpatchagent-bootstrap.XXXXXX.tar.gz)"
TMP_EXTRACT="$(mktemp -d /tmp/winpatchagent-bootstrap.XXXXXX)"

cleanup() {
  rm -f "${TMP_ARCHIVE}" || true
  rm -rf "${TMP_EXTRACT}" || true
}
trap cleanup EXIT

echo "Downloading ${REPO_URL} (${REPO_REF}) source archive..."
download_file "${ARCHIVE_URL}" "${TMP_ARCHIVE}"
tar -xzf "${TMP_ARCHIVE}" -C "${TMP_EXTRACT}"
SOURCE_DIR="$(find "${TMP_EXTRACT}" -mindepth 1 -maxdepth 1 -type d | head -n 1)"
if [[ -z "${SOURCE_DIR}" || ! -x "${SOURCE_DIR}/scripts/install_ubuntu_agent.sh" ]]; then
  echo "Downloaded archive did not contain scripts/install_ubuntu_agent.sh" >&2
  exit 1
fi

if [[ -e "${WORK_DIR}" ]]; then
  rm -rf "${WORK_DIR}"
fi

if ! mv "${SOURCE_DIR}" "${WORK_DIR}"; then
  echo "Failed to place extracted source into ${WORK_DIR}" >&2
  exit 1
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
