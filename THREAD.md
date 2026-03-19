# Windows Patch Management Design Thread

Date: 2026-03-19

## User

What would be involved in building a patch management system for windows desktop systems

## Assistant

Building a Windows desktop patch management system is very doable, but it is a real platform project, not just an installer runner. The hard parts are device trust, reliable inventory, safe rollout control, and accurate reporting.

Core pieces:
- An endpoint agent on each Windows machine to report inventory, scan patch status, download and install updates, reboot when allowed, and send results back.
- A backend service to track devices, patch catalog metadata, deployment rings, schedules, policies, retries, and compliance state.
- An admin UI and API for approvals, targeting, maintenance windows, reboot rules, dashboards, and audit history.
- A content and update source strategy for Microsoft OS updates and third-party applications.
- A secure messaging model so agents can poll or receive jobs safely, with signed instructions and strong authentication.

Major technical problems:
- Inventory
- Patch intelligence
- Deployment safety
- Reboots
- Reporting
- Security
- Scale
- Offline and remote endpoints

Windows-specific concerns:
- OS patching and Microsoft app patching usually rely on Windows Update APIs and ecosystem components.
- Third-party patching is much messier because vendors ship MSI, EXE, services, drivers, and custom updaters.
- Some patches require prerequisites, servicing stack dependencies, or multiple reboots.
- Local admin and UAC boundaries need careful handling.

Typical architecture:
- Endpoint service
- Central API
- Database
- Package storage
- Admin dashboard

Realistic MVP:
- Device registration
- Basic inventory
- Patch scan status
- Deploy a patch policy to a device group
- Install Microsoft updates through native mechanisms
- Reboot coordination
- Success and failure reporting
- Basic RBAC and audit logging

## User

Ok lets flesh out the steps of just the endpoint agent

## Assistant

The endpoint agent should be built in a practical sequence so the foundation is reliable before the install logic gets more sophisticated.

Recommended build order:
1. Core Windows service skeleton
2. Registration and trust establishment
3. Inventory collection
4. Policy and job polling
5. Windows Update scan
6. Download manager
7. Installation engine
8. Reboot orchestration
9. Status and telemetry
10. Recovery and resilience
11. Self-update
12. Security hardening

Endpoint MVP order:
1. Windows service, local state, and logging
2. Registration and secure heartbeat
3. Inventory collection
4. Policy and job polling
5. Windows Update scan
6. Install approved Microsoft updates
7. Reboot handling
8. Reliable status reporting

## User

Lets continue

## Assistant

The endpoint agent can be modeled as one privileged Windows service with well-bounded modules, plus an optional user-session helper later.

Proposed process layout:
- `PatchAgentSvc`: Windows service running as `LocalSystem`
- `PatchAgentUI`: optional user-session helper for notifications and reboot prompts
- `PatchAgentUpdater`: can begin as a service mode and split later if needed

Core modules:
- `Bootstrap`
- `IdentityManager`
- `InventoryCollector`
- `PolicyClient`
- `ScanEngine`
- `DownloadManager`
- `InstallCoordinator`
- `RebootManager`
- `TelemetryQueue`
- `LocalStateStore`
- `SelfUpdateManager`

Suggested local state layout:
- `C:\\ProgramData\\PatchAgent\\config\\`
- `C:\\ProgramData\\PatchAgent\\cache\\`
- `C:\\ProgramData\\PatchAgent\\logs\\`
- `C:\\ProgramData\\PatchAgent\\state\\agent.db`

Main service loop:
1. Startup
2. Load state
3. Resume interrupted work if needed
4. Heartbeat and registration
5. Run inventory on schedule
6. Poll for jobs and policy on schedule
7. Hand active jobs to executor
8. Flush telemetry queue
9. Sleep until next due action

Canonical job state machine:
- `Assigned`
- `EligibilityCheck`
- `WaitingForWindow`
- `Scanning`
- `DownloadPending`
- `Downloading`
- `ReadyToInstall`
- `Installing`
- `Installed`
- `PendingReboot`
- `PostRebootValidation`
- `Succeeded`
- `Failed`
- `Canceled`

Key resume behavior:
- On startup, inspect `current_job`
- If the prior state was `Installing` or `PendingReboot`, run validation
- Move through `PostRebootValidation`
- Mark `Succeeded` or `Failed`
- Flush queued events

Suggested interfaces:
- `IIdentityManager`
- `IInventoryCollector`
- `IPolicyClient`
- `IScanEngine`
- `IDownloadManager`
- `IInstallCoordinator`
- `IRebootManager`

Safety rules:
- Never execute unsigned or unverified payloads
- Never lose job state across crash or reboot
- Never report success before post-install validation
- Never install outside policy window unless explicitly allowed
- Never allow concurrent install execution
- Always queue telemetry locally before send

## User

Lets do it

## Assistant

Practical first-pass agent/backend contract set:

Core endpoints:
- `POST /v1/agents/register`
- `POST /v1/agents/heartbeat`
- `POST /v1/agents/inventory`
- `POST /v1/agents/scan-results`
- `POST /v1/agents/job-events`
- `GET /v1/agents/jobs/next`
- `POST /v1/agents/jobs/{jobId}/ack`
- `POST /v1/agents/jobs/{jobId}/complete`

Contract principles:
- Agent is mostly pull-based
- Server is source of truth for desired work
- Agent is source of truth for local observed state
- Every job and state transition is idempotent
- Reboot is modeled explicitly

Registration payload includes:
- Device identity
- OS version, build, and architecture
- Agent version and channel
- Capability list

Heartbeat payload includes:
- Agent version
- Service health
- Connectivity status
- Current system state
- Current job state
- Queue depths

Inventory payload includes:
- OS facts
- Windows Update health
- Installed KBs
- Installed applications
- Hardware facts

Job fetch response includes:
- Job metadata
- Correlation ID
- Maintenance window
- Reboot policy
- Download and power policy
- Update payload list
- Server signature

Job acknowledgement supports:
- `accepted`
- `invalid_signature`
- `unsupported_job_type`
- `policy_conflict`
- `agent_busy`

Scan result statuses:
- `applicable_missing`
- `downloaded`
- `installed`
- `not_applicable`
- `superseded`
- `failed_scan`

Event stream types:
- `job_state_changed`
- `download_progress`
- `install_started`
- `install_completed`
- `reboot_required`
- `reboot_initiated`
- `post_reboot_resumed`
- `validation_completed`
- `job_failed`

Job completion fields:
- Final state
- Install result
- Reboot required and performed
- Post-reboot validation status
- HRESULT and exit code artifacts

Eligibility check reasons:
- `outside_maintenance_window`
- `battery_blocked`
- `insufficient_disk`
- `pending_reboot_blocked`
- `wu_service_unhealthy`
- `another_job_active`

Normalized error model:
- `code`
- `message`
- `source`
- `retryable`
- `raw_code`

First contracts to lock down:
1. `register`
2. `heartbeat`
3. `jobs/next`
4. `job-events` and `complete`
