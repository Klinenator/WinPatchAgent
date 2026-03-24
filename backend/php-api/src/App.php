<?php

declare(strict_types=1);

namespace PatchAgent\Api;

use PatchAgent\Api\Http\JsonResponse;
use PatchAgent\Api\Http\Request;
use PatchAgent\Api\Storage\AgentRepository;
use PatchAgent\Api\Storage\EnrollmentRepository;
use PatchAgent\Api\Storage\EventRepository;
use PatchAgent\Api\Storage\FileStore;
use PatchAgent\Api\Storage\InventoryRepository;
use PatchAgent\Api\Storage\JobRepository;
use Throwable;

final class App
{
    private const DEFAULT_ENROLLMENT_TTL_SECONDS = 604800;
    private const AGENT_REPO_URL = 'https://github.com/Klinenator/WinPatchAgent.git';
    private const AGENT_REPO_REF = 'main';

    private Config $config;
    private AgentRepository $agents;
    private EnrollmentRepository $enrollments;
    private InventoryRepository $inventory;
    private EventRepository $events;
    private JobRepository $jobs;

    public function __construct()
    {
        $this->config = Config::fromEnvironment();
        $store = new FileStore($this->config->storageRoot);

        $this->agents = new AgentRepository($store);
        $this->enrollments = new EnrollmentRepository($store);
        $this->inventory = new InventoryRepository($store);
        $this->events = new EventRepository($store);
        $this->jobs = new JobRepository($store);
    }

    public function run(): void
    {
        try {
            $request = Request::fromGlobals();
            $path = $request->path();
            $method = $request->method();

            if ($method === 'GET' && $path === '/healthz') {
                JsonResponse::ok([
                    'status' => 'ok',
                    'time' => gmdate(DATE_ATOM),
                ]);
                return;
            }

            if ($method === 'GET' && ($path === '/admin' || $path === '/admin/')) {
                $this->handleAdminView();
                return;
            }

            if ($method === 'GET' && $path === '/v1/admin/jobs') {
                $this->requireAdmin($request);
                $this->handleListJobs();
                return;
            }

            if ($method === 'GET' && $path === '/v1/admin/agents') {
                $this->requireAdmin($request);
                $this->handleListAgents();
                return;
            }

            if ($method === 'GET' && $path === '/install/linux.sh') {
                $this->handleLinuxInstallScript($request);
                return;
            }

            if ($method === 'GET' && $path === '/install/windows.ps1') {
                $this->handleWindowsInstallScript($request);
                return;
            }

            if ($method === 'POST' && preg_match('#^/v1/agents/jobs/([^/]+)/ack$#', $path, $matches) === 1) {
                $agent = $this->requireAgent($request);
                $this->handleAcknowledgeJob($request, $agent, rawurldecode($matches[1]));
                return;
            }

            if ($method === 'POST' && preg_match('#^/v1/agents/jobs/([^/]+)/complete$#', $path, $matches) === 1) {
                $agent = $this->requireAgent($request);
                $this->handleCompleteJob($request, $agent, rawurldecode($matches[1]));
                return;
            }

            if ($method !== 'POST') {
                JsonResponse::error(405, 'method_not_allowed', 'Only POST is supported for this API.');
                return;
            }

            switch ($path) {
                case '/v1/agents/register':
                    $this->handleRegister($request);
                    return;

                case '/v1/agents/heartbeat':
                    $agent = $this->requireAgent($request);
                    $this->handleHeartbeat($request, $agent);
                    return;

                case '/v1/agents/inventory':
                    $agent = $this->requireAgent($request);
                    $this->handleInventory($request, $agent);
                    return;

                case '/v1/agents/jobs/next':
                    $agent = $this->requireAgent($request);
                    $this->handleNextJob($request, $agent);
                    return;

                case '/v1/agents/job-events':
                    $agent = $this->requireAgent($request);
                    $this->handleJobEvents($request, $agent);
                    return;

                case '/v1/admin/jobs':
                    $this->requireAdmin($request);
                    $this->handleCreateJob($request);
                    return;

                case '/v1/admin/enrollments':
                    $this->requireAdmin($request);
                    $this->handleCreateEnrollment($request);
                    return;
            }

            JsonResponse::error(404, 'not_found', 'The requested API route was not found.');
        } catch (ApiException $exception) {
            JsonResponse::error(
                $exception->statusCode(),
                $exception->errorCode(),
                $exception->getMessage()
            );
        } catch (Throwable $exception) {
            JsonResponse::error(
                500,
                'server_error',
                'The server could not complete the request.',
                ['detail' => $exception->getMessage()]
            );
        }
    }

    private function handleRegister(Request $request): void
    {
        $body = $request->json();
        $incomingEnrollmentKey = (string) ($body['enrollment_key'] ?? '');

        $device = $body['device'] ?? [];
        $agentInfo = $body['agent'] ?? [];
        $os = $body['os'] ?? [];

        $deviceId = $this->requireString($device, 'device_id');
        $hostname = $this->requireString($device, 'hostname');

        if (!$this->isEnrollmentAuthorized($incomingEnrollmentKey, $deviceId)) {
            throw new ApiException(403, 'invalid_enrollment_key', 'The enrollment key is invalid.');
        }

        $record = $this->agents->upsertRegistration([
            'device_id' => $deviceId,
            'hostname' => $hostname,
            'domain' => (string) ($device['domain'] ?? ''),
            'os' => [
                'family' => (string) ($os['family'] ?? ''),
                'description' => (string) ($os['description'] ?? ''),
                'architecture' => (string) ($os['architecture'] ?? ''),
            ],
            'agent' => [
                'version' => (string) ($agentInfo['version'] ?? ''),
                'channel' => (string) ($agentInfo['channel'] ?? ''),
            ],
            'capabilities' => array_values(array_filter(
                is_array($body['capabilities'] ?? null) ? $body['capabilities'] : [],
                static fn ($value): bool => is_string($value) && $value !== ''
            )),
        ]);

        JsonResponse::ok([
            'agent_record_id' => $record['agent_record_id'],
            'agent_token' => $record['agent_token'],
            'poll' => $this->pollIntervals(),
        ]);
    }

    private function handleHeartbeat(Request $request, array $agent): void
    {
        $body = $request->json();

        $this->agents->recordHeartbeat($agent['agent_record_id'], [
            'device_id' => (string) ($body['device_id'] ?? ''),
            'agent_version' => (string) ($body['agent_version'] ?? ''),
            'service_state' => (string) ($body['service_state'] ?? 'unknown'),
            'sent_at' => (string) ($body['sent_at'] ?? ''),
            'system_state' => is_array($body['system_state'] ?? null) ? $body['system_state'] : [],
            'current_job' => is_array($body['current_job'] ?? null) ? $body['current_job'] : null,
        ]);

        JsonResponse::ok([
            'server_time' => gmdate(DATE_ATOM),
            'desired_poll_intervals' => $this->pollIntervals(),
        ]);
    }

    private function handleInventory(Request $request, array $agent): void
    {
        $body = $request->json();

        $this->inventory->storeSnapshot($agent['agent_record_id'], [
            'agent_id' => (string) ($body['agent_id'] ?? ''),
            'device_id' => (string) ($body['device_id'] ?? ''),
            'mode' => (string) ($body['mode'] ?? 'full'),
            'collected_at' => (string) ($body['collected_at'] ?? ''),
            'os' => is_array($body['os'] ?? null) ? $body['os'] : [],
            'windows_update' => is_array($body['windows_update'] ?? null) ? $body['windows_update'] : [],
            'hardware' => is_array($body['hardware'] ?? null) ? $body['hardware'] : [],
            'applications' => is_array($body['applications'] ?? null) ? $body['applications'] : [],
        ]);

        JsonResponse::ok([
            'accepted' => true,
        ]);
    }

    private function handleNextJob(Request $request, array $agent): void
    {
        $body = $request->json();
        $deviceId = (string) ($body['device_id'] ?? '');
        $job = $this->jobs->findNextJob($agent['agent_record_id'], $deviceId);

        JsonResponse::ok([
            'job' => $job,
        ]);
    }

    private function handleJobEvents(Request $request, array $agent): void
    {
        $body = $request->json();
        $events = is_array($body['events'] ?? null) ? $body['events'] : [];

        $acceptedCount = $this->events->appendBatch(
            $agent['agent_record_id'],
            (string) ($body['device_id'] ?? ''),
            $events
        );

        JsonResponse::ok([
            'accepted' => true,
            'accepted_count' => $acceptedCount,
        ]);
    }

    private function handleCreateJob(Request $request): void
    {
        $body = $request->json();

        $targetAgentId = $this->optionalString($body, 'target_agent_id');
        $targetDeviceId = $this->optionalString($body, 'target_device_id');

        if ($targetAgentId === null && $targetDeviceId === null) {
            throw new ApiException(
                422,
                'invalid_request',
                'Either "target_agent_id" or "target_device_id" is required.'
            );
        }

        $job = $this->jobs->createJob([
            'job_id' => $this->optionalString($body, 'job_id'),
            'type' => $this->optionalString($body, 'type'),
            'correlation_id' => $this->optionalString($body, 'correlation_id'),
            'status' => $this->optionalString($body, 'status'),
            'target_agent_id' => $targetAgentId,
            'target_device_id' => $targetDeviceId,
            'policy' => is_array($body['policy'] ?? null) ? $body['policy'] : [],
            'payload' => is_array($body['payload'] ?? null) ? $body['payload'] : [],
        ]);

        JsonResponse::created([
            'job' => $job,
        ]);
    }

    private function handleCreateEnrollment(Request $request): void
    {
        $body = $request->json();
        $platform = strtolower($this->requireString($body, 'platform'));

        if (!in_array($platform, ['linux', 'windows'], true)) {
            throw new ApiException(422, 'invalid_request', 'Field "platform" must be "linux" or "windows".');
        }

        $ttlSeconds = $this->readEnrollmentTtlSeconds($body);
        $enrollment = $this->enrollments->createEnrollment($platform, $ttlSeconds);

        $scriptPath = $platform === 'windows' ? '/install/windows.ps1' : '/install/linux.sh';
        $scriptUrl = sprintf(
            '%s%s?enrollment_key=%s',
            $request->baseUrl(),
            $scriptPath,
            rawurlencode((string) $enrollment['enrollment_key'])
        );

        $installCommand = $platform === 'windows'
            ? $this->buildWindowsInstallCommand($scriptUrl)
            : $this->buildLinuxInstallCommand($scriptUrl);

        JsonResponse::created([
            'enrollment' => $enrollment,
            'install' => [
                'platform' => $platform,
                'script_url' => $scriptUrl,
                'command' => $installCommand,
            ],
        ]);
    }

    private function handleAcknowledgeJob(Request $request, array $agent, string $jobId): void
    {
        $body = $request->json();
        $job = $this->jobs->acknowledgeJob($jobId, $agent['agent_record_id'], [
            'ack' => (string) ($body['ack'] ?? 'accepted'),
            'reason' => (string) ($body['reason'] ?? ''),
            'acknowledged_at' => (string) ($body['acknowledged_at'] ?? gmdate(DATE_ATOM)),
        ]);

        if ($job === null) {
            throw new ApiException(404, 'job_not_found', 'The requested job does not exist.');
        }

        JsonResponse::ok([
            'accepted' => true,
            'job' => $job,
        ]);
    }

    private function handleCompleteJob(Request $request, array $agent, string $jobId): void
    {
        $body = $request->json();
        $job = $this->jobs->completeJob($jobId, $agent['agent_record_id'], [
            'final_state' => (string) ($body['final_state'] ?? 'Succeeded'),
            'completed_at' => (string) ($body['completed_at'] ?? gmdate(DATE_ATOM)),
            'result' => is_array($body['result'] ?? null) ? $body['result'] : [],
            'error' => is_array($body['error'] ?? null) ? $body['error'] : null,
        ]);

        if ($job === null) {
            throw new ApiException(404, 'job_not_found', 'The requested job does not exist.');
        }

        JsonResponse::ok([
            'accepted' => true,
            'job' => $job,
        ]);
    }

    private function handleListJobs(): void
    {
        JsonResponse::ok([
            'jobs' => $this->jobs->listJobs(),
        ]);
    }

    private function handleListAgents(): void
    {
        JsonResponse::ok([
            'agents' => $this->agents->listAgents(),
        ]);
    }

    private function handleAdminView(): void
    {
        $adminPagePath = dirname(__DIR__) . '/public/admin.html';
        if (!is_file($adminPagePath)) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Admin page is missing.';
            return;
        }

        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        readfile($adminPagePath);
    }

    private function handleLinuxInstallScript(Request $request): void
    {
        $enrollmentKey = $request->queryParam('enrollment_key');
        if ($enrollmentKey === null || !$this->enrollments->isEnrollmentKeyActive($enrollmentKey)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Invalid or expired enrollment key.\n";
            return;
        }

        header('Content-Type: text/x-shellscript; charset=utf-8');
        header('Content-Disposition: inline; filename="install-winpatchagent-linux.sh"');
        echo $this->buildLinuxInstallScript($request->baseUrl(), $enrollmentKey);
    }

    private function handleWindowsInstallScript(Request $request): void
    {
        $enrollmentKey = $request->queryParam('enrollment_key');
        if ($enrollmentKey === null || !$this->enrollments->isEnrollmentKeyActive($enrollmentKey)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Invalid or expired enrollment key.\n";
            return;
        }

        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: inline; filename="install-winpatchagent-windows.ps1"');
        echo $this->buildWindowsInstallScript($request->baseUrl(), $enrollmentKey);
    }

    private function isEnrollmentAuthorized(string $incomingEnrollmentKey, string $deviceId): bool
    {
        if ($this->config->enrollmentKey !== '' && hash_equals($this->config->enrollmentKey, $incomingEnrollmentKey)) {
            return true;
        }

        if ($incomingEnrollmentKey === '') {
            return $this->config->enrollmentKey === '';
        }

        return $this->enrollments->consumeEnrollmentKey($incomingEnrollmentKey, $deviceId);
    }

    private function readEnrollmentTtlSeconds(array $body): int
    {
        $raw = $body['ttl_seconds'] ?? self::DEFAULT_ENROLLMENT_TTL_SECONDS;
        if (is_int($raw)) {
            $ttl = $raw;
        } elseif (is_numeric($raw)) {
            $ttl = (int) $raw;
        } else {
            throw new ApiException(422, 'invalid_request', 'Field "ttl_seconds" must be an integer.');
        }

        if ($ttl < 300 || $ttl > 2592000) {
            throw new ApiException(422, 'invalid_request', 'Field "ttl_seconds" must be between 300 and 2592000.');
        }

        return $ttl;
    }

    private function buildLinuxInstallScript(string $baseUrl, string $enrollmentKey): string
    {
        $backendLiteral = escapeshellarg($baseUrl);
        $keyLiteral = escapeshellarg($enrollmentKey);
        $repoUrlLiteral = escapeshellarg(self::AGENT_REPO_URL);
        $repoRefLiteral = escapeshellarg(self::AGENT_REPO_REF);

        return <<<BASH
#!/usr/bin/env bash
set -euo pipefail

BACKEND_URL={$backendLiteral}
ENROLLMENT_KEY={$keyLiteral}
REPO_URL={$repoUrlLiteral}
REPO_REF={$repoRefLiteral}
WORK_DIR="/opt/winpatchagent-src"

if [[ "\${EUID}" -ne 0 ]]; then
  echo "Run as root (or pipe into sudo bash)." >&2
  exit 1
fi

if ! command -v git >/dev/null 2>&1; then
  if ! command -v apt-get >/dev/null 2>&1; then
    echo "git is required and apt-get was not found. Install git, then rerun." >&2
    exit 1
  fi

  apt-get update
  DEBIAN_FRONTEND=noninteractive apt-get install -y git ca-certificates curl
fi

if [[ -d "\${WORK_DIR}/.git" ]]; then
  git -C "\${WORK_DIR}" fetch --depth 1 origin "\${REPO_REF}"
  git -C "\${WORK_DIR}" checkout -B "\${REPO_REF}" FETCH_HEAD
else
  git clone --depth 1 --branch "\${REPO_REF}" "\${REPO_URL}" "\${WORK_DIR}"
fi

bash "\${WORK_DIR}/scripts/setup_ubuntu_agent.sh" \\
  --backend-url "\${BACKEND_URL}" \\
  --enrollment-key "\${ENROLLMENT_KEY}"
BASH;
    }

    private function buildWindowsInstallScript(string $baseUrl, string $enrollmentKey): string
    {
        $backendUrlLiteral = $this->powershellLiteral($baseUrl);
        $enrollmentKeyLiteral = $this->powershellLiteral($enrollmentKey);
        $repoUrlLiteral = $this->powershellLiteral(self::AGENT_REPO_URL);
        $repoRefLiteral = $this->powershellLiteral(self::AGENT_REPO_REF);

        return <<<POWERSHELL
\$ErrorActionPreference = "Stop"

\$BackendUrl = {$backendUrlLiteral}
\$EnrollmentKey = {$enrollmentKeyLiteral}
\$RepoUrl = {$repoUrlLiteral}
\$RepoRef = {$repoRefLiteral}
\$WorkDir = "C:\\ProgramData\\WinPatchAgent\\src"
\$InstallDir = "C:\\Program Files\\WinPatchAgent"
\$ServiceName = "PatchAgentSvc"
\$StateDir = "C:\\ProgramData\\WinPatchAgent\\state"

function Require-Command {
    param([string]\$Name, [string]\$Hint)
    if (-not (Get-Command \$Name -ErrorAction SilentlyContinue)) {
        throw "Missing required command '\$Name'. \$Hint"
    }
}

Require-Command "git" "Install Git for Windows and rerun."
Require-Command "dotnet" "Install .NET SDK 8+ and rerun."

New-Item -ItemType Directory -Path \$InstallDir -Force | Out-Null
New-Item -ItemType Directory -Path \$StateDir -Force | Out-Null

if (Test-Path "\$WorkDir\\.git") {
    git -C \$WorkDir fetch --depth 1 origin \$RepoRef
    git -C \$WorkDir checkout -B \$RepoRef FETCH_HEAD
} else {
    New-Item -ItemType Directory -Path \$WorkDir -Force | Out-Null
    git clone --depth 1 --branch \$RepoRef \$RepoUrl \$WorkDir
}

\$ProjectPath = Join-Path \$WorkDir "src\\PatchAgent.Service\\PatchAgent.Service.csproj"
dotnet publish \$ProjectPath -c Release -r win-x64 --self-contained true -o \$InstallDir

\$ConfigPath = Join-Path \$InstallDir "appsettings.Production.json"
\$ConfigObject = @{
    Agent = @{
        ServiceName = "PatchAgentSvc"
        BackendBaseUrl = \$BackendUrl
        EnrollmentKey = \$EnrollmentKey
        AgentChannel = "stable"
        StorageRoot = \$StateDir
        RequestTimeoutSeconds = 30
        LoopDelaySeconds = 15
        HeartbeatIntervalSeconds = 300
        InventoryIntervalSeconds = 21600
        JobPollIntervalSeconds = 120
        EnableStubJobExecution = \$true
        StubJobDurationSeconds = 20
        EnableAptJobExecution = \$false
    }
}
\$ConfigObject | ConvertTo-Json -Depth 8 | Set-Content -Path \$ConfigPath -Encoding UTF8

if (Get-Service -Name \$ServiceName -ErrorAction SilentlyContinue) {
    Stop-Service -Name \$ServiceName -Force -ErrorAction SilentlyContinue
    sc.exe delete \$ServiceName | Out-Null
    Start-Sleep -Seconds 2
}

\$ExePath = Join-Path \$InstallDir "PatchAgent.Service.exe"
if (-not (Test-Path \$ExePath)) {
    throw "Expected service binary not found: \$ExePath"
}

sc.exe create \$ServiceName binPath= "`"\$ExePath`"" start= auto | Out-Null
sc.exe description \$ServiceName "WinPatchAgent endpoint service" | Out-Null
Start-Service -Name \$ServiceName

Write-Host "Install complete."
Write-Host "Service: \$ServiceName"
Write-Host "Status:  Get-Service -Name \$ServiceName"
POWERSHELL;
    }

    private function buildLinuxInstallCommand(string $scriptUrl): string
    {
        return sprintf('curl -fsSL %s | sudo bash', escapeshellarg($scriptUrl));
    }

    private function buildWindowsInstallCommand(string $scriptUrl): string
    {
        return sprintf(
            "powershell -ExecutionPolicy Bypass -NoProfile -Command \"iwr -UseBasicParsing '%s' | iex\"",
            str_replace("'", "''", $scriptUrl)
        );
    }

    private function powershellLiteral(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    private function requireAgent(Request $request): array
    {
        $token = $request->bearerToken();
        if ($token === null || $token === '') {
            throw new ApiException(401, 'missing_token', 'The Authorization bearer token is required.');
        }

        $agent = $this->agents->findByToken($token);
        if ($agent === null) {
            throw new ApiException(401, 'invalid_token', 'The bearer token is invalid.');
        }

        return $agent;
    }

    private function requireAdmin(Request $request): void
    {
        if ($this->config->adminKey === '') {
            return;
        }

        $token = $request->bearerToken();
        if ($token === null || $token === '') {
            throw new ApiException(401, 'missing_admin_token', 'The admin bearer token is required.');
        }

        if (!hash_equals($this->config->adminKey, $token)) {
            throw new ApiException(403, 'invalid_admin_token', 'The admin bearer token is invalid.');
        }
    }

    private function requireString(array $source, string $key): string
    {
        $value = $source[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new ApiException(422, 'invalid_request', sprintf('Field "%s" is required.', $key));
        }

        return trim($value);
    }

    private function optionalString(array $source, string $key): ?string
    {
        $value = $source[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    private function pollIntervals(): array
    {
        return [
            'heartbeat_seconds' => $this->config->heartbeatSeconds,
            'jobs_seconds' => $this->config->jobsSeconds,
            'inventory_seconds' => $this->config->inventorySeconds,
        ];
    }
}

final class ApiException extends \RuntimeException
{
    public function __construct(
        private readonly int $statusCode,
        private readonly string $errorCode,
        string $message
    ) {
        parent::__construct($message);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
