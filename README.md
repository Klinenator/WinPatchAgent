# Windows Patch Management Agent

This folder captures the design thread for a Windows desktop patch management endpoint agent.

Files:
- `THREAD.md`: Conversation transcript and design discussion.
- `PatchAgent.sln`: Solution scaffold for the endpoint agent service.
- `src/PatchAgent.Service/`: Initial Windows service boilerplate.
- `docs/php-backend-api.md`: API notes for a PHP backend behind nginx.
- `backend/php-api/`: Minimal PHP backend scaffold for nginx + PHP-FPM.

Current focus:
- Endpoint agent responsibilities
- Internal agent architecture
- Agent/backend API contracts
- MVP sequencing for implementation

Suggested next steps:
- Define C# data models for the API payloads
- Define the agent state machine and persistence model
- Choose Windows-native integration points for scan, download, install, and reboot handling

Current scaffold status:
- .NET worker service configured to run as `PatchAgentSvc`
- Config-driven polling intervals and storage paths
- Local JSON-backed state store and telemetry queue
- HTTP client for registration, heartbeat, inventory upload, job polling, and event publishing
- Main service loop with registration, heartbeat, inventory, job polling, and telemetry flush hooks
- State now stores issued agent tokens and server-provided poll intervals
- Plain PHP backend scaffold with file-backed agent registration, inventory, job polling, and event ingestion

Build note:
- The scaffold has been built successfully with .NET 8 in this environment.
- PHP is not installed in this environment yet, so the backend scaffold has not been executed here.
