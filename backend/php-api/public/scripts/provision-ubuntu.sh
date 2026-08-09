#!/bin/bash
# provision-ubuntu.sh — enroll an Ubuntu/Debian host with PatchAgent.
#
# Served from the PatchAgent API host so that machines being enrolled fetch
# everything from inside your own perimeter, rather than pulling source from
# github.com. This matches how provision.ps1 and provision-mac.sh are advertised.
#
#   curl -fsSL https://patch.rrsaccess.com/scripts/provision-ubuntu.sh -o /tmp/provision-ubuntu.sh
#   sudo bash /tmp/provision-ubuntu.sh --enrollment-key <KEY>
#
# This file is served unauthenticated and contains NO secret. The enrollment key
# is supplied by the operator at run time and never written to disk here.
#
# Defaults to --self-contained: the agent ships with its own .NET runtime, so no
# SDK is installed on the host. On servers holding regulated data, not leaving a
# build toolchain behind is worth the extra download.

set -uo pipefail

BACKEND_URL="https://patch.rrsaccess.com"
ENROLLMENT_KEY=""
SELF_CONTAINED=1
WORKDIR=""

usage() {
    cat <<'USAGE'
Usage: provision-ubuntu.sh [options]

  --enrollment-key KEY   Enrollment key (required unless the API allows open enrolment)
  --backend-url URL      PatchAgent API base URL (default: https://patch.rrsaccess.com)
  --with-sdk             Install the .NET SDK instead of a self-contained build
  -h, --help             Show this help

Everything after -- is passed through to install_ubuntu_agent.sh.
USAGE
}

PASSTHROUGH=()
while [ $# -gt 0 ]; do
    case "$1" in
        --enrollment-key) ENROLLMENT_KEY="${2:-}"; shift 2 ;;
        --backend-url)    BACKEND_URL="${2:-}"; shift 2 ;;
        --with-sdk)       SELF_CONTAINED=0; shift ;;
        -h|--help)        usage; exit 0 ;;
        --)               shift; PASSTHROUGH=("$@"); break ;;
        *) echo "provision-ubuntu: unknown option '$1'" >&2; usage; exit 2 ;;
    esac
done

if [ "$(id -u)" -ne 0 ]; then
    echo "provision-ubuntu: must run as root (use sudo)" >&2
    exit 1
fi

for tool in curl tar; do
    command -v "$tool" >/dev/null 2>&1 || { echo "provision-ubuntu: $tool is required" >&2; exit 1; }
done

BACKEND_URL="${BACKEND_URL%/}"

# Fail fast on an unreachable or unhealthy API rather than part-installing an
# agent that will never be able to register.
echo "==> checking ${BACKEND_URL}/healthz"
if ! curl -fsS --max-time 15 "${BACKEND_URL}/healthz" >/dev/null; then
    echo "provision-ubuntu: API health check failed at ${BACKEND_URL}/healthz" >&2
    exit 1
fi

WORKDIR="$(mktemp -d /tmp/patchagent-provision.XXXXXX)" || exit 1
cleanup() { [ -n "$WORKDIR" ] && rm -rf "$WORKDIR"; }
trap cleanup EXIT

echo "==> downloading agent source from ${BACKEND_URL}/scripts/agent-source.tar.gz"
if ! curl -fsSL --max-time 180 "${BACKEND_URL}/scripts/agent-source.tar.gz" -o "${WORKDIR}/agent-source.tar.gz"; then
    echo "provision-ubuntu: could not download agent source." >&2
    echo "  Generate it on the API host with:" >&2
    echo "    git -C /var/www/WinPatchAgent archive --format=tar.gz -o \\" >&2
    echo "      /var/www/WinPatchAgent/backend/php-api/public/scripts/agent-source.tar.gz HEAD" >&2
    exit 1
fi

echo "==> extracting"
mkdir -p "${WORKDIR}/src"
tar -xzf "${WORKDIR}/agent-source.tar.gz" -C "${WORKDIR}/src" || {
    echo "provision-ubuntu: archive did not extract" >&2; exit 1; }

INSTALLER="${WORKDIR}/src/scripts/install_ubuntu_agent.sh"
if [ ! -f "$INSTALLER" ]; then
    # git archive of a repo root puts scripts/ at the top; a tarball with a
    # wrapper directory needs one level down.
    INSTALLER="$(find "${WORKDIR}/src" -maxdepth 3 -path '*/scripts/install_ubuntu_agent.sh' -print -quit 2>/dev/null)"
fi
if [ -z "$INSTALLER" ] || [ ! -f "$INSTALLER" ]; then
    echo "provision-ubuntu: install_ubuntu_agent.sh not found in the archive" >&2
    exit 1
fi

INSTALL_ARGS=(--backend-url "$BACKEND_URL")
[ -n "$ENROLLMENT_KEY" ] && INSTALL_ARGS+=(--enrollment-key "$ENROLLMENT_KEY")
[ "$SELF_CONTAINED" -eq 1 ] && INSTALL_ARGS+=(--self-contained)
[ ${#PASSTHROUGH[@]} -gt 0 ] && INSTALL_ARGS+=("${PASSTHROUGH[@]}")

echo "==> installing agent (self-contained=${SELF_CONTAINED})"
bash "$INSTALLER" "${INSTALL_ARGS[@]}"
STATUS=$?

if [ "$STATUS" -ne 0 ]; then
    echo "provision-ubuntu: installer exited ${STATUS}" >&2
    exit "$STATUS"
fi

echo "==> verifying"
UNIT="$(systemctl list-units --type=service --all --no-legend 2>/dev/null \
    | awk '$1 ~ /winpatchagent/ {print $1; exit}')"
if [ -n "$UNIT" ]; then
    echo "    unit:    $UNIT"
    echo "    active:  $(systemctl is-active "$UNIT" 2>&1)"
    echo "    enabled: $(systemctl is-enabled "$UNIT" 2>&1)"
else
    echo "    no winpatchagent unit found - check the installer output above" >&2
fi

echo "==> done. The host should appear in the admin Servers view within a few minutes."
