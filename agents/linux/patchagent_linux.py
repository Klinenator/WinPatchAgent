#!/usr/bin/env python3
"""
PatchAgent Linux agent.

Speaks the same API as the .NET agent, but the Linux side of that agent only
ever shells out to dpkg/apt and reads a few files under /etc and /proc — so it
carries a ~73 MB .NET runtime and a build-time SDK dependency to do work the
standard library already does. This is stdlib-only: no pip, no runtime, no
compiler on the endpoint. Ubuntu ships Python 3.12.

Endpoints used (all POST, Bearer auth after registration):
    /v1/agents/register     enrollment_key -> agent_token
    /v1/agents/heartbeat    liveness
    /v1/agents/inventory    OS, kernel, packages, pending reboot
    /v1/agents/jobs/next    fetch queued work
    /v1/agents/job-events   report results

Install:
    sudo install -m 0755 patchagent_linux.py /usr/local/sbin/
    sudo patchagent_linux.py --register --backend-url https://patch.rrsaccess.com \
        --enrollment-key <KEY>
    (writes /etc/patchagent/agent.json, then run --daemon under systemd)
"""

from __future__ import annotations

import argparse
import json
import os
import platform
import re
import socket
import subprocess
import sys
import time
import urllib.error
import urllib.request
import uuid
from datetime import datetime, timezone

AGENT_VERSION = "1.0.0-python"
STATE_PATH = "/etc/patchagent/agent.json"
HEARTBEAT_SECONDS = 300
INVENTORY_SECONDS = 21600
JOB_POLL_SECONDS = 60
HTTP_TIMEOUT = 30


# ---------------------------------------------------------------- utilities


def utcnow() -> str:
    return datetime.now(timezone.utc).isoformat(timespec="seconds")


def log(message: str) -> None:
    # systemd captures stdout into the journal; no separate log file to rotate.
    print(f"{utcnow()} {message}", flush=True)


def run(cmd: list[str], timeout: int = 300) -> tuple[int, str, str]:
    try:
        p = subprocess.run(cmd, capture_output=True, text=True, timeout=timeout)
        return p.returncode, p.stdout, p.stderr
    except FileNotFoundError:
        return 127, "", f"not found: {cmd[0]}"
    except subprocess.TimeoutExpired:
        return 124, "", f"timed out after {timeout}s: {' '.join(cmd)}"


def read_file(path: str, default: str = "") -> str:
    try:
        with open(path, "r", encoding="utf-8", errors="replace") as fh:
            return fh.read().strip()
    except OSError:
        return default


# ---------------------------------------------------------------- state


def load_state() -> dict:
    raw = read_file(STATE_PATH)
    if not raw:
        return {}
    try:
        return json.loads(raw)
    except json.JSONDecodeError:
        log(f"WARN {STATE_PATH} is not valid JSON; treating as unregistered")
        return {}


def save_state(state: dict) -> None:
    os.makedirs(os.path.dirname(STATE_PATH), mode=0o750, exist_ok=True)
    tmp = STATE_PATH + ".tmp"
    with open(tmp, "w", encoding="utf-8") as fh:
        json.dump(state, fh, indent=2)
    # The agent token is a credential: root-only, and swapped in atomically so a
    # crash mid-write cannot leave a half-file that looks unregistered.
    os.chmod(tmp, 0o600)
    os.replace(tmp, STATE_PATH)


# ---------------------------------------------------------------- transport


def post(base_url: str, path: str, payload: dict, token: str | None = None) -> dict:
    url = base_url.rstrip("/") + path
    body = json.dumps(payload).encode("utf-8")
    req = urllib.request.Request(url, data=body, method="POST")
    req.add_header("Content-Type", "application/json")
    req.add_header("Accept", "application/json")
    req.add_header("User-Agent", f"patchagent-linux/{AGENT_VERSION}")
    if token:
        req.add_header("Authorization", "Bearer " + token)

    try:
        with urllib.request.urlopen(req, timeout=HTTP_TIMEOUT) as resp:
            raw = resp.read().decode("utf-8", errors="replace")
    except urllib.error.HTTPError as exc:
        detail = exc.read().decode("utf-8", errors="replace")[:400]
        raise RuntimeError(f"{path} -> HTTP {exc.code}: {detail}") from None
    except urllib.error.URLError as exc:
        raise RuntimeError(f"{path} -> unreachable: {exc.reason}") from None

    if not raw.strip():
        return {}
    try:
        return json.loads(raw)
    except json.JSONDecodeError:
        raise RuntimeError(f"{path} -> non-JSON response: {raw[:200]}") from None


# ---------------------------------------------------------------- collection


def os_release() -> dict:
    values = {}
    for line in read_file("/etc/os-release").splitlines():
        if "=" not in line:
            continue
        key, _, value = line.partition("=")
        values[key.strip()] = value.strip().strip('"')
    return values


def device_identity(state: dict) -> tuple[str, str]:
    """Stable device id. Prefers the machine-id so reinstalling the agent does
    not create a duplicate device record on the backend."""
    device_id = state.get("device_id") or ""
    if not device_id:
        device_id = read_file("/etc/machine-id") or read_file("/var/lib/dbus/machine-id")
        device_id = device_id or str(uuid.uuid4())
    return device_id, socket.getfqdn() or platform.node()


def collect_os() -> dict:
    rel = os_release()
    return {
        # detectOsFamily() matches on 'linux'/'ubuntu'/'debian' in either field.
        "family": "linux",
        "description": rel.get("PRETTY_NAME", "") or rel.get("NAME", "Linux"),
        "version": rel.get("VERSION_ID", ""),
        "architecture": platform.machine(),
        "kernel": platform.release(),
    }


def apt_security_map() -> dict[str, bool]:
    """Which upgradable packages come from a security pocket.

    'apt list --upgradable' names the origin suite, so -security / -esm entries
    are distinguishable from ordinary updates. Package counts alone overstate
    urgency; the Servers page reports these separately.
    """
    rc, out, _ = run(["apt", "list", "--upgradable"], timeout=120)
    if rc != 0:
        return {}
    security = {}
    for line in out.splitlines():
        if "/" not in line:
            continue
        name = line.split("/", 1)[0].strip()
        suite = line.split("/", 1)[1].split()[0] if len(line.split("/", 1)) > 1 else ""
        if name:
            security[name] = ("-security" in suite) or ("-esm" in suite)
    return security


def collect_linux() -> dict:
    rel = os_release()
    security = apt_security_map()

    details = []
    rc, out, _ = run(
        ["apt-get", "-s", "-o", "Debug::NoLocking=1", "upgrade"], timeout=180
    )
    if rc == 0:
        for line in out.splitlines():
            if not line.startswith("Inst "):
                continue
            parts = line.split()
            if len(parts) < 2:
                continue
            name = parts[1]
            current = ""
            candidate = ""
            m = re.search(r"\[([^\]]+)\]\s+\(([^\s)]+)", line)
            if m:
                current, candidate = m.group(1), m.group(2)
            is_sec = security.get(name, False)
            details.append(
                {
                    "name": name,
                    "current_version": current,
                    "candidate_version": candidate,
                    # collectPatchFacts() counts a package as security-relevant
                    # when vulnerability_count > 0.
                    "vulnerability_count": 1 if is_sec else 0,
                    "cve_ids": [],
                    "source": "apt",
                }
            )

    return {
        "apt_available": os.path.exists("/usr/bin/apt-get") or os.path.exists("/usr/bin/apt"),
        "distro_id": rel.get("ID", ""),
        "distro_version_id": rel.get("VERSION_ID", ""),
        "kernel_version": read_file("/proc/sys/kernel/osrelease") or platform.release(),
        "pending_reboot": os.path.exists("/var/run/reboot-required"),
        "reboot_required": os.path.exists("/var/run/reboot-required"),
        "reboot_required_packages": [
            p for p in read_file("/var/run/reboot-required.pkgs").splitlines() if p
        ],
        "available_packages": [d["name"] for d in details],
        "available_packages_count": len(details),
        "package_updates_available": len(details),
        "updates_available": len(details),
        "available_package_details": details,
        "security_updates_count": sum(1 for d in details if d["vulnerability_count"] > 0),
        # Not in the .NET agent's payload, but both matter for the reboot SLA:
        # livepatch decides whether 7 days or 48 hours applies, and uptime shows
        # whether the boot path has been exercised at all.
        "uptime_seconds": int(float(read_file("/proc/uptime", "0").split()[0] or 0)),
        "livepatch": collect_livepatch(),
    }


def collect_livepatch() -> dict:
    rc, out, _ = run(["canonical-livepatch", "status"], timeout=30)
    if rc != 0:
        return {"installed": False, "covered": False, "detail": ""}
    covered = "nothing-to-apply" in out or "applied" in out
    if "not covered" in out.lower():
        covered = False
    return {"installed": True, "covered": covered, "detail": out.strip()[:400]}


def collect_hardware() -> dict:
    total_kb = 0
    for line in read_file("/proc/meminfo").splitlines():
        if line.startswith("MemTotal:"):
            total_kb = int(line.split()[1])
            break
    free_mb = 0
    try:
        st = os.statvfs("/")
        free_mb = int(st.f_bavail * st.f_frsize / 1048576)
    except OSError:
        pass
    return {
        "cpu_count": os.cpu_count() or 0,
        "memory_mb": int(total_kb / 1024),
        "free_disk_mb": free_mb,
    }


def collect_applications() -> list[dict]:
    rc, out, _ = run(
        ["dpkg-query", "-W", "-f=${Package}\\t${Version}\\t${Status}\\n"], timeout=120
    )
    if rc != 0:
        return []
    apps = []
    for line in out.splitlines():
        parts = line.split("\t")
        if len(parts) >= 3 and parts[2].startswith("install ok installed"):
            apps.append({"name": parts[0], "version": parts[1], "source": "dpkg"})
    return apps


# ---------------------------------------------------------------- operations


def do_register(base_url: str, enrollment_key: str) -> dict:
    state = load_state()
    device_id, hostname = device_identity(state)
    payload = {
        "enrollment_key": enrollment_key,
        "device": {"device_id": device_id, "hostname": hostname, "domain": ""},
        "os": collect_os(),
        "agent": {"version": AGENT_VERSION, "channel": "linux-python"},
        "capabilities": ["apt", "inventory", "reboot_report"],
    }
    resp = post(base_url, "/v1/agents/register", payload)
    token = str(resp.get("agent_token") or "")
    if not token:
        raise RuntimeError(f"register returned no agent_token: {resp}")

    save_state(
        {
            "backend_url": base_url.rstrip("/"),
            "device_id": device_id,
            "hostname": hostname,
            "agent_token": token,
            "registered_at": utcnow(),
        }
    )
    log(f"registered {hostname} ({device_id}) -> {STATE_PATH}")
    return resp


def do_heartbeat(state: dict) -> None:
    post(
        state["backend_url"],
        "/v1/agents/heartbeat",
        {
            "device_id": state["device_id"],
            "agent_version": AGENT_VERSION,
            "service_state": "running",
            "sent_at": utcnow(),
            "system_state": {
                "pending_reboot": os.path.exists("/var/run/reboot-required"),
                "uptime_seconds": int(float(read_file("/proc/uptime", "0").split()[0] or 0)),
            },
            "current_job": None,
        },
        state["agent_token"],
    )


def do_inventory(state: dict) -> None:
    post(
        state["backend_url"],
        "/v1/agents/inventory",
        {
            "agent_id": state["device_id"],
            "device_id": state["device_id"],
            "mode": "full",
            "collected_at": utcnow(),
            "os": collect_os(),
            "linux": collect_linux(),
            "hardware": collect_hardware(),
            "applications": collect_applications(),
        },
        state["agent_token"],
    )
    log("inventory sent")


def run_job(state: dict, job: dict) -> None:
    job_id = str(job.get("job_id") or "")
    job_type = str(job.get("type") or job.get("job_type") or "")
    log(f"job {job_id} type={job_type}")

    events = [{"job_id": job_id, "state": "running", "at": utcnow(), "message": ""}]
    post(state["backend_url"], "/v1/agents/job-events",
         {"device_id": state["device_id"], "events": events}, state["agent_token"])

    if job_type in ("apt", "apt_upgrade", "patch"):
        env = os.environ.copy()
        env["DEBIAN_FRONTEND"] = "noninteractive"
        rc, out, err = run(["apt-get", "update"], timeout=600)
        if rc == 0:
            rc, out, err = run(
                ["apt-get", "-y", "-o", "Dpkg::Options::=--force-confold",
                 "upgrade"], timeout=3600
            )
        success = rc == 0
        output = (out or "")[-4000:] + (("\n" + err[-2000:]) if err else "")
    else:
        success = False
        output = f"unsupported job type: {job_type}"

    post(
        state["backend_url"],
        "/v1/agents/job-events",
        {
            "device_id": state["device_id"],
            "events": [
                {
                    "job_id": job_id,
                    "state": "succeeded" if success else "failed",
                    "final_state": "succeeded" if success else "failed",
                    "at": utcnow(),
                    "message": output,
                    "error_code": "" if success else "APT_COMMAND_FAILED",
                }
            ],
        },
        state["agent_token"],
    )
    log(f"job {job_id} {'succeeded' if success else 'failed'}")


def poll_jobs(state: dict) -> None:
    resp = post(
        state["backend_url"],
        "/v1/agents/jobs/next",
        {"device_id": state["device_id"]},
        state["agent_token"],
    )
    job = resp.get("job")
    if isinstance(job, dict) and job:
        run_job(state, job)


# ---------------------------------------------------------------- daemon


TICK_SECONDS = 15


def daemon(state: dict) -> int:
    log(f"patchagent-linux {AGENT_VERSION} starting for {state['hostname']}")

    # Tick faster than the shortest interval and track each task's own deadline.
    # Sleeping for the heartbeat interval would silently cap job pickup at that
    # interval too, regardless of JOB_POLL_SECONDS.
    last = {"heartbeat": 0.0, "inventory": 0.0, "jobs": 0.0}
    every = {
        "heartbeat": HEARTBEAT_SECONDS,
        "inventory": INVENTORY_SECONDS,
        "jobs": JOB_POLL_SECONDS,
    }
    tasks = {"heartbeat": do_heartbeat, "inventory": do_inventory, "jobs": poll_jobs}

    while True:
        now = time.monotonic()
        for name, fn in tasks.items():
            # last == 0.0 means "never run": fire immediately on start so a
            # freshly enrolled host appears in the Servers view within seconds
            # rather than after the first 6-hour inventory interval.
            if last[name] != 0.0 and (now - last[name]) < every[name]:
                continue
            try:
                fn(state)
            except Exception as exc:  # noqa: BLE001 - a transient API error must
                # never take the agent down; an agent that exits stops reporting,
                # and a host that stops reporting looks the same as one that is fine.
                log(f"WARN {name}: {exc}")
            # Recorded even on failure, so a persistent error backs off to the
            # normal interval instead of hammering the API every tick.
            last[name] = time.monotonic()

        time.sleep(TICK_SECONDS)


def main() -> int:
    ap = argparse.ArgumentParser(description="PatchAgent Linux agent")
    ap.add_argument("--register", action="store_true")
    ap.add_argument("--daemon", action="store_true")
    ap.add_argument("--once", action="store_true", help="one heartbeat + inventory, then exit")
    ap.add_argument("--backend-url", default="")
    ap.add_argument("--enrollment-key", default="")
    args = ap.parse_args()

    if os.geteuid() != 0:
        print("must run as root", file=sys.stderr)
        return 1

    if args.register:
        if not args.backend_url:
            print("--backend-url is required with --register", file=sys.stderr)
            return 2
        try:
            do_register(args.backend_url, args.enrollment_key)
        except Exception as exc:  # noqa: BLE001
            print(f"registration failed: {exc}", file=sys.stderr)
            return 1
        return 0

    state = load_state()
    for key in ("backend_url", "device_id", "agent_token"):
        if not state.get(key):
            print(f"not registered ({STATE_PATH} missing '{key}') - run --register first",
                  file=sys.stderr)
            return 1

    if args.once:
        do_heartbeat(state)
        do_inventory(state)
        log("one-shot complete")
        return 0

    if args.daemon:
        return daemon(state)

    ap.print_help()
    return 2


if __name__ == "__main__":
    sys.exit(main())
