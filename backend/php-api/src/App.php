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
use PatchAgent\Api\Storage\AutomationProfileRepository;
use PatchAgent\Api\Storage\AdminPasskeyRepository;
use PatchAgent\Api\Support\Json;
use RuntimeException;
use Throwable;

final class App
{
    private const DEFAULT_ENROLLMENT_TTL_SECONDS = 604800;
    private const AGENT_REPO_URL = 'https://github.com/Klinenator/WinPatchAgent.git';
    private const AGENT_REPO_REF = 'main';
    private const GOOGLE_AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const GOOGLE_TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const GOOGLE_TOKENINFO_URL = 'https://oauth2.googleapis.com/tokeninfo';
    private const OSV_QUERY_BATCH_URL = 'https://api.osv.dev/v1/querybatch';
    private const OAUTH_SESSION_STATE_KEY = 'google_oauth_state';
    private const OAUTH_SESSION_NONCE_KEY = 'google_oauth_nonce';
    private const OAUTH_SESSION_STARTED_AT_KEY = 'google_oauth_started_at';
    private const ADMIN_SESSION_USER_KEY = 'admin_user';
    private const ADMIN_SESSION_TOTP_PENDING_KEY = 'admin_totp_pending';
    private const ADMIN_SESSION_PASSKEY_ASSERTION_KEY = 'admin_passkey_assertion_pending';
    private const ADMIN_SESSION_PASSKEY_REGISTRATION_KEY = 'admin_passkey_registration_pending';

    private Config $config;
    private FileStore $store;
    private AgentRepository $agents;
    private EnrollmentRepository $enrollments;
    private InventoryRepository $inventory;
    private EventRepository $events;
    private JobRepository $jobs;
    private AutomationProfileRepository $automations;
    private AdminPasskeyRepository $passkeys;

    public function __construct()
    {
        $this->config = Config::fromEnvironment();
        $store = new FileStore($this->config->storageRoot);
        $this->store = $store;

        $this->agents = new AgentRepository($store);
        $this->enrollments = new EnrollmentRepository($store);
        $this->inventory = new InventoryRepository($store);
        $this->events = new EventRepository($store);
        $this->jobs = new JobRepository($store);
        $this->automations = new AutomationProfileRepository($store);
        $this->passkeys = new AdminPasskeyRepository($store);
    }

    public function run(): void
    {
        try {
            $request = Request::fromGlobals();
            $path = $request->path();
            $method = $request->method();

            if ($method === 'GET' && ($path === '/' || $path === '')) {
                $this->redirect($this->isGoogleOAuthEnabled() ? '/admin/login' : '/admin');
                return;
            }

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

            if ($method === 'GET' && ($path === '/admin/automation' || $path === '/admin/automation/')) {
                $this->handleAdminAutomationView();
                return;
            }

            if ($method === 'GET' && ($path === '/admin/seed-jobs' || $path === '/admin/seed-jobs/')) {
                $this->handleAdminSeedJobsView();
                return;
            }

            if ($method === 'GET' && ($path === '/admin/install-agent' || $path === '/admin/install-agent/')) {
                $this->handleAdminInstallAgentView();
                return;
            }

            if ($method === 'GET' && ($path === '/admin/settings' || $path === '/admin/settings/')) {
                $this->handleAdminSettingsView();
                return;
            }

            if ($method === 'GET' && ($path === '/admin/evidence' || $path === '/admin/evidence/')) {
                $this->handleAdminEvidenceView();
                return;
            }

            if ($method === 'GET' && ($path === '/admin/login' || $path === '/admin/login/')) {
                $this->handleAdminLoginView();
                return;
            }

            if ($method === 'GET' && $path === '/v1/admin/auth/status') {
                $this->handleAdminAuthStatus();
                return;
            }

            if ($method === 'GET' && $path === '/v1/admin/auth/passkeys') {
                $this->requireAdmin($request);
                $this->handleListAdminPasskeys();
                return;
            }

            if ($method === 'GET' && $path === '/v1/admin/auth/google/start') {
                $this->handleGoogleAuthStart();
                return;
            }

            if ($method === 'GET' && $path === '/v1/admin/auth/google/callback') {
                $this->handleGoogleAuthCallback();
                return;
            }

            if ($method === 'GET' && $path === '/v1/admin/jobs') {
                $this->requireAdmin($request);
                $this->handleListJobs();
                return;
            }

            if ($method === 'GET' && $path === '/v1/admin/automations') {
                $this->requireAdmin($request);
                $this->handleListAutomations();
                return;
            }

            if ($method === 'GET' && $path === '/v1/admin/agents') {
                $this->requireAdmin($request);
                $this->handleListAgents();
                return;
            }

            if ($method === 'GET' && $path === '/v1/admin/evidence/soc2') {
                $this->requireAdmin($request);
                $this->handleSoc2EvidenceJson();
                return;
            }

            if ($method === 'GET' && $path === '/v1/admin/evidence/soc2.csv') {
                $this->requireAdmin($request);
                $this->handleSoc2EvidenceCsv();
                return;
            }

            if ($method === 'GET' && $path === '/v1/admin/evidence/soc2.html') {
                $this->requireAdmin($request);
                $this->handleSoc2EvidenceHtml();
                return;
            }

            if ($method === 'GET' && preg_match('#^/v1/admin/agents/([^/]+)/inventory$#', $path, $matches) === 1) {
                $this->requireAdmin($request);
                $this->handleGetAgentInventory(rawurldecode($matches[1]));
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

            if ($method === 'GET' && $path === '/install/macos.sh') {
                $this->handleMacOsInstallScript($request);
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

            if ($method === 'POST' && preg_match('#^/v1/admin/agents/([^/]+)/rename$#', $path, $matches) === 1) {
                $this->requireAdmin($request);
                $this->handleRenameAgent($request, rawurldecode($matches[1]));
                return;
            }

            if ($method === 'POST' && $path === '/v1/admin/auth/logout') {
                $this->handleAdminLogout();
                return;
            }

            if ($method === 'POST' && $path === '/v1/admin/auth/totp/verify') {
                $this->handleAdminTotpVerify($request);
                return;
            }

            if ($method === 'POST' && $path === '/v1/admin/auth/passkey/challenge') {
                $this->handlePasskeyAssertionBegin();
                return;
            }

            if ($method === 'POST' && $path === '/v1/admin/auth/passkey/verify') {
                $this->handlePasskeyAssertionVerify($request);
                return;
            }

            if ($method === 'POST' && $path === '/v1/admin/auth/passkey/register/options') {
                $this->requireAdmin($request);
                $this->handlePasskeyRegistrationOptions($request);
                return;
            }

            if ($method === 'POST' && $path === '/v1/admin/auth/passkey/register/complete') {
                $this->requireAdmin($request);
                $this->handlePasskeyRegistrationComplete($request);
                return;
            }

            if ($method === 'POST' && preg_match('#^/v1/admin/auth/passkeys/([^/]+)/delete$#', $path, $matches) === 1) {
                $this->requireAdmin($request);
                $this->handleDeleteAdminPasskey(rawurldecode($matches[1]));
                return;
            }

            if ($method === 'POST' && preg_match('#^/v1/admin/automations/([^/]+)/run$#', $path, $matches) === 1) {
                $this->requireAdmin($request);
                $this->handleRunAutomation(rawurldecode($matches[1]));
                return;
            }

            if ($method === 'POST' && preg_match('#^/v1/admin/automations/([^/]+)/delete$#', $path, $matches) === 1) {
                $this->requireAdmin($request);
                $this->handleDeleteAutomation(rawurldecode($matches[1]));
                return;
            }

            if ($method === 'POST' && preg_match('#^/v1/admin/jobs/([^/]+)/cancel$#', $path, $matches) === 1) {
                $this->requireAdmin($request);
                $this->handleCancelJob($request, rawurldecode($matches[1]));
                return;
            }

            if ($method !== 'POST') {
                JsonResponse::error(405, 'method_not_allowed', 'The requested route does not support this HTTP method.');
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

                case '/v1/admin/automations':
                    $this->requireAdmin($request);
                    $this->handleUpsertAutomation($request);
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

        try {
            $this->queueAutomationsForNewAgent($record);
        } catch (\Throwable) {
            // Do not block registration if automation fan-out fails.
        }

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

        $this->processDueAutomations();

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
            'windows_security' => is_array($body['windows_security'] ?? null) ? $body['windows_security'] : [],
            'linux' => is_array($body['linux'] ?? null) ? $body['linux'] : [],
            'mac_os' => is_array($body['mac_os'] ?? null)
                ? $body['mac_os']
                : (is_array($body['macos'] ?? null) ? $body['macos'] : []),
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
        $type = $this->optionalString($body, 'type') ?? 'windows_update_install';
        $payload = is_array($body['payload'] ?? null) ? $body['payload'] : [];
        $policy = is_array($body['policy'] ?? null) ? $body['policy'] : [];
        $typeNormalized = strtolower(trim($type));

        if (in_array($typeNormalized, ['application_install', 'package_install'], true)) {
            $type = 'software_install';
            $typeNormalized = 'software_install';
        }

        if (in_array($typeNormalized, ['application_search', 'package_search'], true)) {
            $type = 'software_search';
            $typeNormalized = 'software_search';
        }

        if ($typeNormalized === 'software_install') {
            $payload = $this->normalizeSoftwareInstallPayload($payload);
        }

        if ($typeNormalized === 'software_search') {
            $payload = $this->normalizeSoftwareSearchPayload($payload);
        }

        if ($targetAgentId === null && $targetDeviceId === null) {
            throw new ApiException(
                422,
                'invalid_request',
                'Either "target_agent_id" or "target_device_id" is required.'
            );
        }

        $duplicate = $this->jobs->findActiveDuplicate(
            $type,
            $targetAgentId ?? '',
            $targetDeviceId ?? '',
            $payload
        );

        if ($duplicate !== null) {
            JsonResponse::ok([
                'job' => $duplicate,
                'duplicate' => true,
                'message' => 'A matching active job already exists. Returning existing job.',
            ]);
            return;
        }

        $job = $this->jobs->createJob([
            'job_id' => $this->optionalString($body, 'job_id'),
            'type' => $type,
            'correlation_id' => $this->optionalString($body, 'correlation_id'),
            'status' => $this->optionalString($body, 'status'),
            'target_agent_id' => $targetAgentId,
            'target_device_id' => $targetDeviceId,
            'policy' => $policy,
            'payload' => $payload,
        ]);

        JsonResponse::created([
            'job' => $job,
            'duplicate' => false,
        ]);
    }

    private function handleCancelJob(Request $request, string $jobId): void
    {
        $body = $request->json();
        $reason = trim((string) ($body['reason'] ?? ''));
        $job = $this->jobs->cancelJob($jobId, $reason);
        if ($job === null) {
            throw new ApiException(404, 'job_not_found', 'The requested job does not exist.');
        }

        JsonResponse::ok([
            'canceled' => true,
            'job' => $job,
        ]);
    }

    private function handleCreateEnrollment(Request $request): void
    {
        $body = $request->json();
        $platformRaw = strtolower($this->requireString($body, 'platform'));
        $platform = $platformRaw === 'macos' ? 'mac' : $platformRaw;

        if (!in_array($platform, ['linux', 'windows', 'mac'], true)) {
            throw new ApiException(422, 'invalid_request', 'Field "platform" must be "linux", "windows", or "mac".');
        }

        $ttlSeconds = $this->readEnrollmentTtlSeconds($body);
        $enrollment = $this->enrollments->createEnrollment($platform, $ttlSeconds);
        $windowsInstallMode = $platform === 'windows'
            ? $this->normalizeWindowsInstallMode($body['windows_install_mode'] ?? $body['install_mode'] ?? null)
            : 'prebuilt';

        $scriptPath = match ($platform) {
            'windows' => '/install/windows.ps1',
            'mac' => '/install/macos.sh',
            default => '/install/linux.sh',
        };
        $query = [
            'enrollment_key' => (string) $enrollment['enrollment_key'],
        ];
        if ($platform === 'windows') {
            $query['mode'] = $windowsInstallMode;
        }

        $scriptUrl = sprintf('%s%s?%s', $request->baseUrl(), $scriptPath, http_build_query($query, '', '&', PHP_QUERY_RFC3986));

        $installCommand = match ($platform) {
            'windows' => $this->buildWindowsInstallCommand($scriptUrl),
            'mac' => $this->buildMacInstallCommand($scriptUrl),
            default => $this->buildLinuxInstallCommand($scriptUrl),
        };

        JsonResponse::created([
            'enrollment' => $enrollment,
            'install' => [
                'platform' => $platform,
                'script_url' => $scriptUrl,
                'command' => $installCommand,
                'windows_install_mode' => $platform === 'windows' ? $windowsInstallMode : null,
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
        $this->processDueAutomations();

        JsonResponse::ok([
            'jobs' => $this->jobs->listJobs(),
        ]);
    }

    private function handleListAutomations(): void
    {
        $this->processDueAutomations();

        JsonResponse::ok([
            'profiles' => $this->automations->listProfiles(),
        ]);
    }

    private function handleUpsertAutomation(Request $request): void
    {
        $body = $request->json();
        $profileInput = is_array($body['profile'] ?? null) ? $body['profile'] : $body;
        if (!is_array($profileInput)) {
            throw new ApiException(422, 'invalid_request', 'Automation payload must be a JSON object.');
        }

        $saved = $this->automations->saveProfile($profileInput);
        JsonResponse::ok([
            'profile' => $saved,
        ]);
    }

    private function handleRunAutomation(string $profileId): void
    {
        $profile = $this->automations->findProfile($profileId);
        if ($profile === null) {
            throw new ApiException(404, 'automation_not_found', 'The requested automation profile was not found.');
        }

        $summary = $this->enqueueAutomationJobs($profile, $this->agents->listAgents(), 'manual');
        $updated = $this->automations->recordExecution($profileId, 'manual', $summary) ?? $profile;

        JsonResponse::ok([
            'ran' => true,
            'profile' => $updated,
            'summary' => $summary,
        ]);
    }

    private function handleDeleteAutomation(string $profileId): void
    {
        if (!$this->automations->deleteProfile($profileId)) {
            throw new ApiException(404, 'automation_not_found', 'The requested automation profile was not found.');
        }

        JsonResponse::ok([
            'deleted' => true,
            'profile_id' => $profileId,
        ]);
    }

    private function processDueAutomations(): void
    {
        $dueProfiles = $this->automations->claimDueProfiles(gmdate(DATE_ATOM));
        if (count($dueProfiles) === 0) {
            return;
        }

        $agents = $this->agents->listAgents();
        foreach ($dueProfiles as $profile) {
            if (!is_array($profile)) {
                continue;
            }

            $profileId = trim((string) ($profile['profile_id'] ?? ''));
            if ($profileId === '') {
                continue;
            }

            $summary = $this->enqueueAutomationJobs($profile, $agents, 'scheduled');
            $this->automations->recordExecution($profileId, 'scheduled', $summary);
        }
    }

    private function queueAutomationsForNewAgent(array $agent): void
    {
        $agentRecordId = trim((string) ($agent['agent_record_id'] ?? ''));
        if ($agentRecordId === '') {
            return;
        }

        $platform = $this->detectAgentPlatform($agent);
        if ($platform === 'unknown') {
            return;
        }

        $profiles = $this->automations->listProfiles();
        foreach ($profiles as $profile) {
            if (!is_array($profile)) {
                continue;
            }

            if (!$this->toBool($profile['active'] ?? false) || !$this->toBool($profile['run_on_new_agents'] ?? false)) {
                continue;
            }

            if (!$this->profileTargetsPlatform($profile, $platform)) {
                continue;
            }

            $profileId = trim((string) ($profile['profile_id'] ?? ''));
            if ($profileId === '') {
                continue;
            }

            if (!$this->automations->markAppliedToAgent($profileId, $agentRecordId)) {
                continue;
            }

            $summary = $this->enqueueAutomationJobs($profile, [$agent], 'new_agent');
            if ((int) ($summary['jobs_queued'] ?? 0) > 0) {
                $this->automations->recordExecution($profileId, 'new_agent', $summary);
            }
        }
    }

    private function enqueueAutomationJobs(array $profile, array $agents, string $trigger): array
    {
        $profileId = trim((string) ($profile['profile_id'] ?? ''));
        $profileName = trim((string) ($profile['name'] ?? 'automation'));
        $tasks = is_array($profile['tasks'] ?? null) ? $profile['tasks'] : [];
        $windowsTask = is_array($tasks['windows'] ?? null) ? $tasks['windows'] : [];
        $linuxTask = is_array($tasks['linux'] ?? null) ? $tasks['linux'] : [];
        $macTask = is_array($tasks['mac'] ?? null) ? $tasks['mac'] : [];
        $windowsScripts = is_array($tasks['windows_scripts'] ?? null) ? $tasks['windows_scripts'] : [];

        $jobsQueued = 0;
        $agentsTargeted = 0;

        foreach ($agents as $agent) {
            if (!is_array($agent)) {
                continue;
            }

            $targetAgentId = trim((string) ($agent['agent_record_id'] ?? ''));
            if ($targetAgentId === '') {
                continue;
            }

            $targetDeviceId = trim((string) ($agent['device_id'] ?? ''));
            $platform = $this->detectAgentPlatform($agent);
            if (!$this->profileTargetsPlatform($profile, $platform)) {
                continue;
            }

            $agentJobsQueued = 0;
            $correlationPrefix = sprintf(
                'auto-%s-%s-%s-%s',
                $profileId !== '' ? $profileId : 'profile',
                trim($trigger) === '' ? 'manual' : trim($trigger),
                $targetAgentId,
                gmdate('YmdHis')
            );

            if ($platform === 'windows' && $this->toBool($windowsTask['install_all_updates'] ?? false)) {
                $this->jobs->createJob([
                    'type' => 'windows_update_install',
                    'correlation_id' => $correlationPrefix . '-win-patch',
                    'target_agent_id' => $targetAgentId,
                    'target_device_id' => $targetDeviceId,
                    'payload' => [
                        'windows_update' => [
                            'install_all' => true,
                            'kbs' => [],
                        ],
                    ],
                    'policy' => [],
                ]);
                $jobsQueued++;
                $agentJobsQueued++;
            }

            if ($platform === 'linux' && $this->toBool($linuxTask['upgrade_all'] ?? false)) {
                $this->jobs->createJob([
                    'type' => 'ubuntu_apt_upgrade',
                    'correlation_id' => $correlationPrefix . '-linux-apt',
                    'target_agent_id' => $targetAgentId,
                    'target_device_id' => $targetDeviceId,
                    'payload' => [
                        'apt' => [
                            'upgrade_all' => true,
                            'packages' => [],
                        ],
                    ],
                    'policy' => [],
                ]);
                $jobsQueued++;
                $agentJobsQueued++;
            }

            if ($platform === 'mac' && $this->toBool($macTask['install_all_updates'] ?? false)) {
                $this->jobs->createJob([
                    'type' => 'macos_software_update',
                    'correlation_id' => $correlationPrefix . '-mac-update',
                    'target_agent_id' => $targetAgentId,
                    'target_device_id' => $targetDeviceId,
                    'payload' => [
                        'macos_update' => [
                            'install_all' => true,
                            'labels' => [],
                        ],
                    ],
                    'policy' => [],
                ]);
                $jobsQueued++;
                $agentJobsQueued++;
            }

            if ($platform === 'windows') {
                foreach ($windowsScripts as $index => $script) {
                    if (!is_array($script)) {
                        continue;
                    }

                    if (!$this->toBool($script['enabled'] ?? true)) {
                        continue;
                    }

                    $scriptBody = trim((string) ($script['script'] ?? ''));
                    $scriptUrl = trim((string) ($script['script_url'] ?? ''));
                    if ($scriptBody === '' && $scriptUrl === '') {
                        continue;
                    }

                    $payload = [
                        'windows_script' => [],
                    ];

                    if ($scriptBody !== '') {
                        $payload['windows_script']['script'] = $scriptBody;
                    }

                    if ($scriptBody === '' && $scriptUrl !== '') {
                        $payload['windows_script']['script_url'] = $scriptUrl;
                    }

                    $this->jobs->createJob([
                        'type' => 'windows_powershell_script',
                        'correlation_id' => sprintf('%s-win-script-%d', $correlationPrefix, $index + 1),
                        'target_agent_id' => $targetAgentId,
                        'target_device_id' => $targetDeviceId,
                        'payload' => $payload,
                        'policy' => [],
                    ]);
                    $jobsQueued++;
                    $agentJobsQueued++;
                }
            }

            if ($agentJobsQueued > 0) {
                $agentsTargeted++;
            }
        }

        return [
            'profile_id' => $profileId,
            'profile_name' => $profileName,
            'trigger' => trim($trigger) === '' ? 'manual' : trim($trigger),
            'jobs_queued' => $jobsQueued,
            'agents_targeted' => $agentsTargeted,
        ];
    }

    private function profileTargetsPlatform(array $profile, string $platform): bool
    {
        $targets = is_array($profile['targets'] ?? null) ? $profile['targets'] : [];

        return match ($platform) {
            'windows' => $this->toBool($targets['windows'] ?? true),
            'linux' => $this->toBool($targets['linux'] ?? true),
            'mac' => $this->toBool($targets['mac'] ?? true),
            default => false,
        };
    }

    private function detectAgentPlatform(array $agent): string
    {
        $os = is_array($agent['os'] ?? null) ? $agent['os'] : [];
        return $this->detectOsFamily($os);
    }

    private function detectOsFamily(array $os): string
    {
        $family = strtolower(trim((string) ($os['family'] ?? '')));
        $description = strtolower(trim((string) ($os['description'] ?? '')));
        $combined = trim($family . ' ' . $description);

        if ($family === 'windows' || str_contains($combined, 'windows')) {
            return 'windows';
        }

        if (
            $family === 'linux'
            || str_contains($combined, 'linux')
            || str_contains($combined, 'ubuntu')
            || str_contains($combined, 'debian')
        ) {
            return 'linux';
        }

        if (
            $family === 'mac'
            || $family === 'darwin'
            || $family === 'osx'
            || str_contains($combined, 'mac')
            || str_contains($combined, 'darwin')
        ) {
            return 'mac';
        }

        return 'unknown';
    }

    private function handleListAgents(): void
    {
        $this->processDueAutomations();

        $agents = $this->agents->listAgents();
        foreach ($agents as $index => $agent) {
            $inventory = $this->inventory->loadSnapshot((string) ($agent['agent_record_id'] ?? ''));
            if ($inventory !== null) {
                $inventory['windows_update'] = $this->normalizeWindowsUpdateInventory(
                    is_array($inventory['windows_update'] ?? null) ? $inventory['windows_update'] : []
                );
                $inventory['windows_security'] = $this->normalizeWindowsSecurityInventory(
                    is_array($inventory['windows_security'] ?? null) ? $inventory['windows_security'] : []
                );
                $inventory['linux'] = $this->normalizeLinuxInventory(
                    is_array($inventory['linux'] ?? null) ? $inventory['linux'] : []
                );
                $inventory['mac_os'] = $this->normalizeMacOsInventory(
                    is_array($inventory['mac_os'] ?? null)
                        ? $inventory['mac_os']
                        : (is_array($inventory['macos'] ?? null) ? $inventory['macos'] : [])
                );
                $inventory['applications'] = $this->normalizeApplicationsInventory(
                    is_array($inventory['applications'] ?? null) ? $inventory['applications'] : []
                );
            }

            $agents[$index]['inventory'] = $inventory;
        }

        JsonResponse::ok([
            'agents' => $agents,
        ]);
    }

    private function handleGetAgentInventory(string $agentRecordId): void
    {
        $inventory = $this->inventory->loadSnapshot($agentRecordId);
        if ($inventory === null) {
            throw new ApiException(404, 'inventory_not_found', 'No inventory snapshot was found for that agent.');
        }

        $inventory['windows_update'] = $this->normalizeWindowsUpdateInventory(
            is_array($inventory['windows_update'] ?? null) ? $inventory['windows_update'] : []
        );
        $inventory['windows_security'] = $this->normalizeWindowsSecurityInventory(
            is_array($inventory['windows_security'] ?? null) ? $inventory['windows_security'] : []
        );
        $inventory['linux'] = $this->normalizeLinuxInventory(
            is_array($inventory['linux'] ?? null) ? $inventory['linux'] : []
        );
        $inventory['mac_os'] = $this->normalizeMacOsInventory(
            is_array($inventory['mac_os'] ?? null)
                ? $inventory['mac_os']
                : (is_array($inventory['macos'] ?? null) ? $inventory['macos'] : [])
        );
        $inventory['applications'] = $this->normalizeApplicationsInventory(
            is_array($inventory['applications'] ?? null) ? $inventory['applications'] : []
        );

        JsonResponse::ok([
            'agent_record_id' => $agentRecordId,
            'inventory' => $inventory,
        ]);
    }

    private function handleSoc2EvidenceJson(): void
    {
        JsonResponse::ok([
            'evidence' => $this->buildSoc2EvidenceReport(),
        ]);
    }

    private function handleSoc2EvidenceCsv(): void
    {
        $report = $this->buildSoc2EvidenceReport();
        $csv = $this->buildSoc2EvidenceCsv($report);
        $filename = 'soc2_evidence_' . preg_replace('/[^A-Za-z0-9_.-]/', '_', (string) ($report['evidence_id'] ?? 'snapshot')) . '.csv';

        http_response_code(200);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo $csv;
    }

    private function handleSoc2EvidenceHtml(): void
    {
        $report = $this->buildSoc2EvidenceReport();
        $html = $this->buildSoc2EvidenceHtml($report);
        $filename = 'soc2_evidence_' . preg_replace('/[^A-Za-z0-9_.-]/', '_', (string) ($report['evidence_id'] ?? 'snapshot')) . '.html';

        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: inline; filename="' . $filename . '"');
        echo $html;
    }

    private function buildSoc2EvidenceReport(): array
    {
        $generatedAt = gmdate(DATE_ATOM);
        $evidenceId = 'soc2_' . gmdate('Ymd\THis\Z');
        $adminUser = $this->currentAdminUser();
        $generatedBy = is_array($adminUser) ? trim((string) ($adminUser['email'] ?? '')) : '';
        if ($generatedBy === '') {
            $generatedBy = 'authenticated_admin';
        }

        $agents = $this->agents->listAgents();
        $rows = [];
        $counts = [
            'total_agents' => count($agents),
            'windows_agents' => 0,
            'evaluated_windows_agents' => 0,
            'compliant' => 0,
            'warning' => 0,
            'failing' => 0,
            'not_applicable' => 0,
        ];

        foreach ($agents as $agent) {
            $agentRecordId = (string) ($agent['agent_record_id'] ?? '');
            $inventory = $this->inventory->loadSnapshot($agentRecordId);
            $inventoryStoredAt = '';
            if (is_array($inventory)) {
                $inventory['windows_security'] = $this->normalizeWindowsSecurityInventory(
                    is_array($inventory['windows_security'] ?? null) ? $inventory['windows_security'] : []
                );
                $inventoryStoredAt = (string) ($inventory['stored_at'] ?? '');
            }

            $os = is_array($agent['os'] ?? null) ? $agent['os'] : [];
            $osFamily = $this->detectOsFamily($os);
            $base = [
                'agent_record_id' => $agentRecordId,
                'device_id' => (string) ($agent['device_id'] ?? ''),
                'display_name' => (string) ($agent['display_name'] ?? ''),
                'hostname' => (string) ($agent['hostname'] ?? ''),
                'domain' => (string) ($agent['domain'] ?? ''),
                'os_family' => $osFamily,
                'os_description' => trim((string) ($os['description'] ?? $os['Description'] ?? '')),
                'last_seen_at' => (string) ($agent['last_seen_at'] ?? ''),
                'inventory_stored_at' => $inventoryStoredAt,
            ];

            if ($osFamily !== 'windows') {
                $counts['not_applicable']++;
                $rows[] = array_merge($base, [
                    'baseline_status' => 'na',
                    'edition' => '',
                    'defender_service_present' => null,
                    'defender_service_state' => 'unknown',
                    'defender_realtime_enabled' => null,
                    'firewall_domain_enabled' => null,
                    'firewall_private_enabled' => null,
                    'firewall_public_enabled' => null,
                    'removable_storage_deny_all' => null,
                    'bitlocker_support' => 'not_supported',
                    'bitlocker_os_volume_protection' => 'not_supported',
                    'controls' => [
                        'defender_service' => ['status' => 'na', 'detail' => 'Windows-only control.'],
                        'defender_realtime' => ['status' => 'na', 'detail' => 'Windows-only control.'],
                        'firewall_profiles' => ['status' => 'na', 'detail' => 'Windows-only control.'],
                        'removable_storage_policy' => ['status' => 'na', 'detail' => 'Windows-only control.'],
                        'bitlocker_os_volume' => ['status' => 'na', 'detail' => 'Windows-only control.'],
                    ],
                ]);
                continue;
            }

            $counts['windows_agents']++;
            if ($inventoryStoredAt !== '') {
                $counts['evaluated_windows_agents']++;
            }

            $security = is_array($inventory['windows_security'] ?? null)
                ? $inventory['windows_security']
                : $this->normalizeWindowsSecurityInventory([]);

            $evaluation = $this->evaluateWindowsSoc2Baseline($security);
            $baselineStatus = (string) ($evaluation['overall_status'] ?? 'unknown');

            if ($baselineStatus === 'fail') {
                $counts['failing']++;
            } elseif ($baselineStatus === 'warn') {
                $counts['warning']++;
            } elseif ($baselineStatus === 'pass') {
                $counts['compliant']++;
            } else {
                $counts['not_applicable']++;
            }

            $rows[] = array_merge($base, [
                'baseline_status' => $baselineStatus,
                'edition' => (string) ($security['edition'] ?? ''),
                'defender_service_present' => $security['defender_service_present'] ?? null,
                'defender_service_state' => (string) ($security['defender_service_state'] ?? 'unknown'),
                'defender_realtime_enabled' => $security['defender_realtime_enabled'] ?? null,
                'firewall_domain_enabled' => $security['firewall_domain_enabled'] ?? null,
                'firewall_private_enabled' => $security['firewall_private_enabled'] ?? null,
                'firewall_public_enabled' => $security['firewall_public_enabled'] ?? null,
                'removable_storage_deny_all' => $security['removable_storage_deny_all'] ?? null,
                'bitlocker_support' => (string) ($security['bitlocker_support'] ?? 'unknown'),
                'bitlocker_os_volume_protection' => (string) ($security['bitlocker_os_volume_protection'] ?? 'unknown'),
                'controls' => is_array($evaluation['controls'] ?? null) ? $evaluation['controls'] : [],
            ]);
        }

        $overallStatus = 'unknown';
        if ($counts['failing'] > 0) {
            $overallStatus = 'fail';
        } elseif ($counts['warning'] > 0) {
            $overallStatus = 'warn';
        } elseif ($counts['compliant'] > 0) {
            $overallStatus = 'pass';
        }

        $report = [
            'evidence_id' => $evidenceId,
            'schema_version' => 1,
            'generated_at' => $generatedAt,
            'generated_by' => $generatedBy,
            'overall_status' => $overallStatus,
            'counts' => $counts,
            'controls_catalog' => [
                ['id' => 'defender_service', 'description' => 'Windows Defender service present and running'],
                ['id' => 'defender_realtime', 'description' => 'Defender real-time protection enabled'],
                ['id' => 'firewall_profiles', 'description' => 'Windows firewall enabled on Domain/Private/Public'],
                ['id' => 'removable_storage_policy', 'description' => 'Removable storage deny policy enforced'],
                ['id' => 'bitlocker_os_volume', 'description' => 'BitLocker OS volume protection status'],
            ],
            'agents' => $rows,
        ];

        $report['sha256'] = hash('sha256', (string) json_encode($report, JSON_UNESCAPED_SLASHES));
        return $report;
    }

    private function evaluateWindowsSoc2Baseline(array $security): array
    {
        $defenderServicePresent = $this->toBool($security['defender_service_present'] ?? false);
        $defenderServiceState = strtolower(trim((string) ($security['defender_service_state'] ?? 'unknown')));
        if (!in_array($defenderServiceState, ['running', 'stopped', 'not_found'], true)) {
            $defenderServiceState = 'unknown';
        }

        $defenderRealtimeEnabled = array_key_exists('defender_realtime_enabled', $security) && is_bool($security['defender_realtime_enabled'])
            ? $security['defender_realtime_enabled']
            : null;
        $firewallDomainEnabled = array_key_exists('firewall_domain_enabled', $security) && is_bool($security['firewall_domain_enabled'])
            ? $security['firewall_domain_enabled']
            : null;
        $firewallPrivateEnabled = array_key_exists('firewall_private_enabled', $security) && is_bool($security['firewall_private_enabled'])
            ? $security['firewall_private_enabled']
            : null;
        $firewallPublicEnabled = array_key_exists('firewall_public_enabled', $security) && is_bool($security['firewall_public_enabled'])
            ? $security['firewall_public_enabled']
            : null;

        $removableStorageDenyAll = $this->toBool($security['removable_storage_deny_all'] ?? false);
        $bitlockerSupport = strtolower(trim((string) ($security['bitlocker_support'] ?? 'unknown')));
        if (!in_array($bitlockerSupport, ['supported', 'not_supported'], true)) {
            $bitlockerSupport = 'unknown';
        }

        $bitlockerProtection = strtolower(trim((string) ($security['bitlocker_os_volume_protection'] ?? 'unknown')));
        if (!in_array($bitlockerProtection, ['on', 'off', 'suspended', 'not_supported'], true)) {
            $bitlockerProtection = 'unknown';
        }

        $controls = [];
        $controls['defender_service'] = [
            'status' => $defenderServicePresent && $defenderServiceState === 'running' ? 'pass' : 'fail',
            'detail' => $defenderServicePresent
                ? ('Service state: ' . $defenderServiceState)
                : 'WinDefend service not found.',
        ];

        if ($defenderRealtimeEnabled === true) {
            $controls['defender_realtime'] = ['status' => 'pass', 'detail' => 'Real-time protection is enabled.'];
        } elseif ($defenderRealtimeEnabled === false) {
            $controls['defender_realtime'] = ['status' => 'fail', 'detail' => 'Real-time protection is disabled.'];
        } else {
            $controls['defender_realtime'] = ['status' => 'warn', 'detail' => 'Real-time protection status is unknown.'];
        }

        if ($firewallDomainEnabled === false || $firewallPrivateEnabled === false || $firewallPublicEnabled === false) {
            $controls['firewall_profiles'] = [
                'status' => 'fail',
                'detail' => sprintf(
                    'Domain=%s, Private=%s, Public=%s',
                    $this->formatNullableBool($firewallDomainEnabled),
                    $this->formatNullableBool($firewallPrivateEnabled),
                    $this->formatNullableBool($firewallPublicEnabled)
                ),
            ];
        } elseif ($firewallDomainEnabled === true && $firewallPrivateEnabled === true && $firewallPublicEnabled === true) {
            $controls['firewall_profiles'] = ['status' => 'pass', 'detail' => 'All firewall profiles are enabled.'];
        } else {
            $controls['firewall_profiles'] = ['status' => 'warn', 'detail' => 'One or more firewall profile states are unknown.'];
        }

        $controls['removable_storage_policy'] = [
            'status' => $removableStorageDenyAll ? 'pass' : 'fail',
            'detail' => $removableStorageDenyAll
                ? 'Deny_All removable storage policy is enabled.'
                : 'Deny_All removable storage policy is not enabled.',
        ];

        if ($bitlockerSupport === 'not_supported' || $bitlockerProtection === 'not_supported') {
            $controls['bitlocker_os_volume'] = ['status' => 'na', 'detail' => 'BitLocker not supported on this endpoint edition.'];
        } elseif ($bitlockerProtection === 'on') {
            $controls['bitlocker_os_volume'] = ['status' => 'pass', 'detail' => 'OS volume BitLocker protection is ON.'];
        } elseif ($bitlockerProtection === 'off') {
            $controls['bitlocker_os_volume'] = ['status' => 'fail', 'detail' => 'OS volume BitLocker protection is OFF.'];
        } elseif ($bitlockerProtection === 'suspended') {
            $controls['bitlocker_os_volume'] = ['status' => 'warn', 'detail' => 'OS volume BitLocker protection is suspended.'];
        } else {
            $controls['bitlocker_os_volume'] = ['status' => 'warn', 'detail' => 'OS volume BitLocker protection status is unknown.'];
        }

        $overallStatus = 'unknown';
        $actionableStatuses = array_values(array_filter(
            array_map(
                static fn (array $control): string => (string) ($control['status'] ?? 'unknown'),
                $controls
            ),
            static fn (string $status): bool => $status !== 'na'
        ));

        if (in_array('fail', $actionableStatuses, true)) {
            $overallStatus = 'fail';
        } elseif (in_array('warn', $actionableStatuses, true)) {
            $overallStatus = 'warn';
        } elseif (in_array('pass', $actionableStatuses, true)) {
            $overallStatus = 'pass';
        }

        return [
            'overall_status' => $overallStatus,
            'controls' => $controls,
        ];
    }

    private function buildSoc2EvidenceCsv(array $report): string
    {
        $handle = fopen('php://temp', 'w+');
        if ($handle === false) {
            return '';
        }

        fputcsv($handle, [
            'evidence_id',
            'generated_at',
            'overall_status',
            'agent_record_id',
            'device_id',
            'display_name',
            'hostname',
            'domain',
            'os_family',
            'last_seen_at',
            'inventory_stored_at',
            'baseline_status',
            'edition',
            'defender_service_present',
            'defender_service_state',
            'defender_realtime_enabled',
            'firewall_domain_enabled',
            'firewall_private_enabled',
            'firewall_public_enabled',
            'removable_storage_deny_all',
            'bitlocker_support',
            'bitlocker_os_volume_protection',
            'control_defender_service_status',
            'control_defender_service_detail',
            'control_defender_realtime_status',
            'control_defender_realtime_detail',
            'control_firewall_profiles_status',
            'control_firewall_profiles_detail',
            'control_removable_storage_policy_status',
            'control_removable_storage_policy_detail',
            'control_bitlocker_os_volume_status',
            'control_bitlocker_os_volume_detail',
        ]);

        $evidenceId = (string) ($report['evidence_id'] ?? '');
        $generatedAt = (string) ($report['generated_at'] ?? '');
        $overallStatus = (string) ($report['overall_status'] ?? '');
        $agents = is_array($report['agents'] ?? null) ? $report['agents'] : [];

        foreach ($agents as $row) {
            if (!is_array($row)) {
                continue;
            }

            $controls = is_array($row['controls'] ?? null) ? $row['controls'] : [];
            $defenderServiceControl = is_array($controls['defender_service'] ?? null) ? $controls['defender_service'] : [];
            $defenderRealtimeControl = is_array($controls['defender_realtime'] ?? null) ? $controls['defender_realtime'] : [];
            $firewallControl = is_array($controls['firewall_profiles'] ?? null) ? $controls['firewall_profiles'] : [];
            $removableControl = is_array($controls['removable_storage_policy'] ?? null) ? $controls['removable_storage_policy'] : [];
            $bitlockerControl = is_array($controls['bitlocker_os_volume'] ?? null) ? $controls['bitlocker_os_volume'] : [];

            fputcsv($handle, [
                $evidenceId,
                $generatedAt,
                $overallStatus,
                (string) ($row['agent_record_id'] ?? ''),
                (string) ($row['device_id'] ?? ''),
                (string) ($row['display_name'] ?? ''),
                (string) ($row['hostname'] ?? ''),
                (string) ($row['domain'] ?? ''),
                (string) ($row['os_family'] ?? ''),
                (string) ($row['last_seen_at'] ?? ''),
                (string) ($row['inventory_stored_at'] ?? ''),
                (string) ($row['baseline_status'] ?? ''),
                (string) ($row['edition'] ?? ''),
                $this->formatCsvBool($row['defender_service_present'] ?? null),
                (string) ($row['defender_service_state'] ?? ''),
                $this->formatCsvBool($row['defender_realtime_enabled'] ?? null),
                $this->formatCsvBool($row['firewall_domain_enabled'] ?? null),
                $this->formatCsvBool($row['firewall_private_enabled'] ?? null),
                $this->formatCsvBool($row['firewall_public_enabled'] ?? null),
                $this->formatCsvBool($row['removable_storage_deny_all'] ?? null),
                (string) ($row['bitlocker_support'] ?? ''),
                (string) ($row['bitlocker_os_volume_protection'] ?? ''),
                (string) ($defenderServiceControl['status'] ?? ''),
                (string) ($defenderServiceControl['detail'] ?? ''),
                (string) ($defenderRealtimeControl['status'] ?? ''),
                (string) ($defenderRealtimeControl['detail'] ?? ''),
                (string) ($firewallControl['status'] ?? ''),
                (string) ($firewallControl['detail'] ?? ''),
                (string) ($removableControl['status'] ?? ''),
                (string) ($removableControl['detail'] ?? ''),
                (string) ($bitlockerControl['status'] ?? ''),
                (string) ($bitlockerControl['detail'] ?? ''),
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return is_string($csv) ? $csv : '';
    }

    private function buildSoc2EvidenceHtml(array $report): string
    {
        $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $statusClass = static fn (string $status): string => match ($status) {
            'pass' => 'status-pass',
            'fail' => 'status-fail',
            'warn' => 'status-warn',
            'na' => 'status-na',
            default => 'status-unknown',
        };
        $statusLabel = static fn (string $status): string => strtoupper($status !== '' ? $status : 'unknown');

        $counts = is_array($report['counts'] ?? null) ? $report['counts'] : [];
        $agents = is_array($report['agents'] ?? null) ? $report['agents'] : [];
        $controlColumns = [
            'defender_service' => 'Defender Service',
            'defender_realtime' => 'Realtime',
            'firewall_profiles' => 'Firewall',
            'removable_storage_policy' => 'Removable Storage',
            'bitlocker_os_volume' => 'BitLocker',
        ];

        $rowsHtml = '';
        foreach ($agents as $row) {
            if (!is_array($row)) {
                continue;
            }

            $controls = is_array($row['controls'] ?? null) ? $row['controls'] : [];
            $baselineStatus = strtolower(trim((string) ($row['baseline_status'] ?? 'unknown')));
            $displayName = trim((string) ($row['display_name'] ?? ''));
            $hostname = trim((string) ($row['hostname'] ?? ''));
            $agentLabel = $displayName !== '' ? $displayName : ($hostname !== '' ? $hostname : (string) ($row['agent_record_id'] ?? ''));

            $controlCells = '';
            foreach ($controlColumns as $controlId => $label) {
                $control = is_array($controls[$controlId] ?? null) ? $controls[$controlId] : [];
                $controlStatus = strtolower(trim((string) ($control['status'] ?? 'unknown')));
                $controlDetail = (string) ($control['detail'] ?? '');
                $controlCells .= '<td><span class="badge ' . $escape($statusClass($controlStatus)) . '" title="'
                    . $escape($controlDetail) . '">' . $escape($statusLabel($controlStatus)) . '</span></td>';
            }

            $rowsHtml .= '<tr>'
                . '<td>' . $escape($agentLabel) . '</td>'
                . '<td>' . $escape((string) ($row['agent_record_id'] ?? '')) . '</td>'
                . '<td>' . $escape((string) ($row['hostname'] ?? '')) . '</td>'
                . '<td>' . $escape((string) ($row['os_family'] ?? '')) . '</td>'
                . '<td><span class="badge ' . $escape($statusClass($baselineStatus)) . '">'
                . $escape($statusLabel($baselineStatus)) . '</span></td>'
                . '<td>' . $escape((string) ($row['last_seen_at'] ?? '')) . '</td>'
                . '<td>' . $escape((string) ($row['inventory_stored_at'] ?? '')) . '</td>'
                . $controlCells
                . '</tr>';
        }

        if ($rowsHtml === '') {
            $rowsHtml = '<tr><td colspan="12">No agents found in the evidence snapshot.</td></tr>';
        }

        $overallStatus = strtolower(trim((string) ($report['overall_status'] ?? 'unknown')));
        $evidenceId = (string) ($report['evidence_id'] ?? '');
        $generatedAt = (string) ($report['generated_at'] ?? '');
        $generatedBy = (string) ($report['generated_by'] ?? '');
        $sha256 = (string) ($report['sha256'] ?? '');

        $totalAgents = (int) ($counts['total_agents'] ?? 0);
        $windowsAgents = (int) ($counts['windows_agents'] ?? 0);
        $evaluatedWindowsAgents = (int) ($counts['evaluated_windows_agents'] ?? 0);
        $compliant = (int) ($counts['compliant'] ?? 0);
        $warning = (int) ($counts['warning'] ?? 0);
        $failing = (int) ($counts['failing'] ?? 0);
        $notApplicable = (int) ($counts['not_applicable'] ?? 0);

        return '<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SOC2 Evidence Report</title>
    <style>
        :root { color-scheme: light; }
        body { margin: 18px; font-family: "IBM Plex Sans", "Segoe UI", Arial, sans-serif; color: #132236; background: #f4f8fb; }
        h1 { margin: 0 0 8px; font-size: 30px; font-weight: 700; color: #11375f; }
        p { margin: 0; }
        .meta { margin-bottom: 14px; color: #415472; font-size: 14px; }
        .cards { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; margin: 14px 0 16px; }
        .card { background: #fff; border: 1px solid #d6e5f2; border-radius: 12px; padding: 10px; }
        .card-label { font-size: 12px; color: #60758f; text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 4px; }
        .card-value { font-size: 22px; font-weight: 700; color: #15324f; }
        .table-wrap { background: #fff; border: 1px solid #d6e5f2; border-radius: 12px; overflow: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 1200px; }
        th, td { padding: 10px 12px; border-bottom: 1px solid #e3edf6; text-align: left; vertical-align: top; font-size: 13px; }
        th { background: #eef5fb; color: #334e6b; font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em; }
        .badge { display: inline-flex; align-items: center; justify-content: center; border-radius: 999px; padding: 3px 10px; font-size: 11px; font-weight: 700; letter-spacing: 0.03em; }
        .status-pass { background: #dff8eb; color: #0c6b3d; }
        .status-fail { background: #fde2e5; color: #9c1c2d; }
        .status-warn { background: #fff1d6; color: #8b5600; }
        .status-na { background: #eceef3; color: #526178; }
        .status-unknown { background: #e8eef7; color: #2d4a6d; }
        .hash { margin-top: 12px; font-family: Menlo, Consolas, monospace; font-size: 12px; color: #3a4d66; word-break: break-all; }
        @media (max-width: 1000px) {
            .cards { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
    </style>
</head>
<body>
    <h1>SOC2 Evidence Report</h1>
    <p class="meta">Evidence ID: ' . $escape($evidenceId) . ' | Generated at: ' . $escape($generatedAt) . ' | Generated by: ' . $escape($generatedBy) . '</p>
    <p class="meta">Overall status: <span class="badge ' . $escape($statusClass($overallStatus)) . '">' . $escape($statusLabel($overallStatus)) . '</span></p>
    <div class="cards">
        <div class="card"><p class="card-label">Total Agents</p><p class="card-value">' . $escape((string) $totalAgents) . '</p></div>
        <div class="card"><p class="card-label">Windows Agents</p><p class="card-value">' . $escape((string) $windowsAgents) . '</p></div>
        <div class="card"><p class="card-label">Evaluated Windows</p><p class="card-value">' . $escape((string) $evaluatedWindowsAgents) . '</p></div>
        <div class="card"><p class="card-label">Compliant</p><p class="card-value">' . $escape((string) $compliant) . '</p></div>
        <div class="card"><p class="card-label">Warning</p><p class="card-value">' . $escape((string) $warning) . '</p></div>
        <div class="card"><p class="card-label">Failing</p><p class="card-value">' . $escape((string) $failing) . '</p></div>
        <div class="card"><p class="card-label">Not Applicable</p><p class="card-value">' . $escape((string) $notApplicable) . '</p></div>
        <div class="card"><p class="card-label">Snapshot Hash</p><p class="card-value" style="font-size:16px;">SHA-256</p></div>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Device</th>
                    <th>Agent ID</th>
                    <th>Hostname</th>
                    <th>OS</th>
                    <th>Baseline</th>
                    <th>Last Seen</th>
                    <th>Inventory At</th>
                    <th>Defender Service</th>
                    <th>Realtime</th>
                    <th>Firewall</th>
                    <th>Removable Storage</th>
                    <th>BitLocker</th>
                </tr>
            </thead>
            <tbody>' . $rowsHtml . '</tbody>
        </table>
    </div>
    <p class="hash">sha256: ' . $escape($sha256) . '</p>
</body>
</html>';
    }

    private function formatNullableBool(?bool $value): string
    {
        if ($value === true) {
            return 'true';
        }

        if ($value === false) {
            return 'false';
        }

        return 'unknown';
    }

    private function formatCsvBool(mixed $value): string
    {
        if ($value === true) {
            return 'true';
        }

        if ($value === false) {
            return 'false';
        }

        return '';
    }

    private function handleRenameAgent(Request $request, string $agentRecordId): void
    {
        $body = $request->json();
        $displayName = $this->requireString($body, 'display_name');

        $updated = $this->agents->updateDisplayName($agentRecordId, $displayName);
        if ($updated === null) {
            throw new ApiException(404, 'agent_not_found', 'The requested agent was not found.');
        }

        JsonResponse::ok([
            'agent' => [
                'agent_record_id' => (string) ($updated['agent_record_id'] ?? ''),
                'display_name' => (string) ($updated['display_name'] ?? ''),
                'hostname' => (string) ($updated['hostname'] ?? ''),
            ],
        ]);
    }

    private function handleAdminView(): void
    {
        $this->handleAdminProtectedView('admin.html', 'Admin page is missing.');
    }

    private function handleAdminAutomationView(): void
    {
        $this->handleAdminProtectedView('admin-automation.html', 'Admin automation page is missing.');
    }

    private function handleAdminSeedJobsView(): void
    {
        $this->handleAdminProtectedView('admin-seed-jobs.html', 'Admin seed jobs page is missing.');
    }

    private function handleAdminInstallAgentView(): void
    {
        $this->handleAdminProtectedView('admin-install-agent.html', 'Admin install agent page is missing.');
    }

    private function handleAdminSettingsView(): void
    {
        $this->handleAdminProtectedView('admin-settings.html', 'Admin settings page is missing.');
    }

    private function handleAdminEvidenceView(): void
    {
        $this->handleAdminProtectedView('admin-evidence.html', 'Admin evidence page is missing.');
    }

    private function handleAdminProtectedView(string $filename, string $missingMessage): void
    {
        if ($this->isGoogleOAuthEnabled() && !$this->isAdminSessionAuthenticated()) {
            $this->redirect('/admin/login');
            return;
        }

        $this->servePublicHtmlFile($filename, $missingMessage, true);
    }

    private function handleAdminLoginView(): void
    {
        if (!$this->isGoogleOAuthEnabled()) {
            $this->redirect('/admin');
            return;
        }

        if ($this->isAdminSessionAuthenticated()) {
            $this->redirect('/admin');
            return;
        }

        $this->servePublicHtmlFile('admin-login.html', 'Admin login page is missing.', true);
    }

    private function handleAdminAuthStatus(): void
    {
        $user = $this->currentAdminUser();
        $pendingTotpUser = $this->currentPendingTotpUser();
        $totpEnabled = $this->isAdminTotpEnabled();
        $secondFactorRequired = $pendingTotpUser !== null;
        $pendingPasskeyCount = 0;
        if ($pendingTotpUser !== null) {
            $pendingEmail = strtolower(trim((string) ($pendingTotpUser['email'] ?? '')));
            if ($pendingEmail !== '') {
                $pendingPasskeyCount = $this->passkeys->countForUser($pendingEmail);
            }
        }

        $activePasskeyCount = 0;
        if ($user !== null) {
            $activePasskeyCount = $this->passkeys->countForUser((string) ($user['email'] ?? ''));
        }

        $effectivePasskeyCount = $user !== null ? $activePasskeyCount : $pendingPasskeyCount;

        JsonResponse::ok([
            'oauth_enabled' => $this->isGoogleOAuthEnabled(),
            'logged_in' => $user !== null,
            'user' => $user,
            'login_url' => '/v1/admin/auth/google/start',
            'logout_url' => '/v1/admin/auth/logout',
            'totp_enabled' => $totpEnabled,
            'totp_required' => $secondFactorRequired && $totpEnabled,
            'totp_user' => $totpEnabled ? $pendingTotpUser : null,
            'totp_verify_url' => '/v1/admin/auth/totp/verify',
            'totp_issuer' => $this->config->adminTotpIssuer,
            'second_factor_required' => $secondFactorRequired,
            'second_factor_user' => $pendingTotpUser,
            'passkey_supported' => $this->isPasskeySupported(),
            'passkey_required' => $secondFactorRequired && $effectivePasskeyCount > 0,
            'passkey_available' => $effectivePasskeyCount > 0,
            'passkey_count' => $effectivePasskeyCount,
            'passkey_challenge_url' => '/v1/admin/auth/passkey/challenge',
            'passkey_verify_url' => '/v1/admin/auth/passkey/verify',
            'passkey_register_options_url' => '/v1/admin/auth/passkey/register/options',
            'passkey_register_complete_url' => '/v1/admin/auth/passkey/register/complete',
            'passkey_list_url' => '/v1/admin/auth/passkeys',
            'redirect_uri' => $this->googleRedirectUri(),
            'hosted_domain' => $this->config->googleHostedDomain,
        ]);
    }

    private function handleGoogleAuthStart(): void
    {
        if (!$this->isGoogleOAuthEnabled()) {
            throw new ApiException(503, 'oauth_not_configured', 'Google OAuth is not configured on this server.');
        }

        $this->startAdminSession();
        unset(
            $_SESSION[self::ADMIN_SESSION_TOTP_PENDING_KEY],
            $_SESSION[self::ADMIN_SESSION_PASSKEY_ASSERTION_KEY],
            $_SESSION[self::ADMIN_SESSION_PASSKEY_REGISTRATION_KEY]
        );

        $state = bin2hex(random_bytes(24));
        $nonce = bin2hex(random_bytes(24));

        $_SESSION[self::OAUTH_SESSION_STATE_KEY] = $state;
        $_SESSION[self::OAUTH_SESSION_NONCE_KEY] = $nonce;
        $_SESSION[self::OAUTH_SESSION_STARTED_AT_KEY] = time();

        $query = [
            'client_id' => $this->config->googleClientId,
            'redirect_uri' => $this->googleRedirectUri(),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'nonce' => $nonce,
            'access_type' => 'online',
            'prompt' => 'select_account',
        ];

        if ($this->config->googleHostedDomain !== '') {
            $query['hd'] = $this->config->googleHostedDomain;
        }

        $authUrl = self::GOOGLE_AUTH_URL . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $this->redirect($authUrl);
    }

    private function handleGoogleAuthCallback(): void
    {
        if (!$this->isGoogleOAuthEnabled()) {
            throw new ApiException(503, 'oauth_not_configured', 'Google OAuth is not configured on this server.');
        }

        $error = trim((string) ($_GET['error'] ?? ''));
        if ($error !== '') {
            $this->redirect('/admin/login?error=' . rawurlencode($error));
            return;
        }

        $this->startAdminSession();

        $expectedState = (string) ($_SESSION[self::OAUTH_SESSION_STATE_KEY] ?? '');
        $expectedNonce = (string) ($_SESSION[self::OAUTH_SESSION_NONCE_KEY] ?? '');

        $receivedState = trim((string) ($_GET['state'] ?? ''));
        if ($expectedState === '' || $receivedState === '' || !hash_equals($expectedState, $receivedState)) {
            unset(
                $_SESSION[self::OAUTH_SESSION_STATE_KEY],
                $_SESSION[self::OAUTH_SESSION_NONCE_KEY],
                $_SESSION[self::OAUTH_SESSION_STARTED_AT_KEY],
                $_SESSION[self::ADMIN_SESSION_PASSKEY_ASSERTION_KEY]
            );
            $this->redirect('/admin/login?error=invalid_state');
            return;
        }

        $code = trim((string) ($_GET['code'] ?? ''));
        if ($code === '') {
            $this->redirect('/admin/login?error=missing_code');
            return;
        }

        try {
            $tokenPayload = $this->exchangeGoogleAuthorizationCode($code);
            $idToken = trim((string) ($tokenPayload['id_token'] ?? ''));
            if ($idToken === '') {
                throw new RuntimeException('Google response did not include an id_token.');
            }

            $claims = $this->validateGoogleIdToken($idToken, $expectedNonce);
        } catch (RuntimeException $exception) {
            unset(
                $_SESSION[self::OAUTH_SESSION_STATE_KEY],
                $_SESSION[self::OAUTH_SESSION_NONCE_KEY],
                $_SESSION[self::OAUTH_SESSION_STARTED_AT_KEY],
                $_SESSION[self::ADMIN_SESSION_PASSKEY_ASSERTION_KEY]
            );
            $this->redirect('/admin/login?error=oauth_failed&reason=' . rawurlencode($exception->getMessage()));
            return;
        }

        unset(
            $_SESSION[self::OAUTH_SESSION_STATE_KEY],
            $_SESSION[self::OAUTH_SESSION_NONCE_KEY],
            $_SESSION[self::OAUTH_SESSION_STARTED_AT_KEY],
            $_SESSION[self::ADMIN_SESSION_PASSKEY_ASSERTION_KEY]
        );

        $email = strtolower(trim((string) ($claims['email'] ?? '')));
        $hasPasskey = $email !== '' && $this->passkeys->countForUser($email) > 0;
        if ($hasPasskey || $this->isAdminTotpEnabled()) {
            $this->startPendingTotpChallenge($claims);
            $this->redirect($hasPasskey ? '/admin/login?passkey=required' : '/admin/login?totp=required');
            return;
        }

        $this->authenticateAdminUser($claims);
        $this->redirect('/admin');
    }

    private function handleAdminLogout(): void
    {
        $this->startAdminSession();

        unset(
            $_SESSION[self::ADMIN_SESSION_USER_KEY],
            $_SESSION[self::ADMIN_SESSION_TOTP_PENDING_KEY],
            $_SESSION[self::ADMIN_SESSION_PASSKEY_ASSERTION_KEY],
            $_SESSION[self::ADMIN_SESSION_PASSKEY_REGISTRATION_KEY],
            $_SESSION[self::OAUTH_SESSION_STATE_KEY],
            $_SESSION[self::OAUTH_SESSION_NONCE_KEY],
            $_SESSION[self::OAUTH_SESSION_STARTED_AT_KEY]
        );
        session_regenerate_id(true);

        JsonResponse::ok([
            'logged_out' => true,
        ]);
    }

    private function handleAdminTotpVerify(Request $request): void
    {
        if (!$this->isAdminTotpEnabled()) {
            throw new ApiException(409, 'totp_not_enabled', 'TOTP is not enabled on this server.');
        }

        $pendingClaims = $this->currentPendingTotpClaims();
        if ($pendingClaims === null) {
            throw new ApiException(401, 'totp_not_pending', 'No pending TOTP challenge was found. Start login again.');
        }

        $body = $request->json();
        $code = preg_replace('/\D+/', '', (string) ($body['code'] ?? ''));
        if (!is_string($code) || strlen($code) !== 6) {
            throw new ApiException(422, 'invalid_request', 'Field "code" must be a 6-digit number.');
        }

        if (!$this->verifyAdminTotpCode($code)) {
            throw new ApiException(401, 'invalid_totp_code', 'The authentication code is invalid.');
        }

        $this->authenticateAdminUser($pendingClaims);

        JsonResponse::ok([
            'verified' => true,
            'logged_in' => true,
            'user' => $this->currentAdminUser(),
            'redirect' => '/admin',
        ]);
    }

    private function handleListAdminPasskeys(): void
    {
        $user = $this->currentAdminUser();
        if ($user === null) {
            throw new ApiException(401, 'admin_session_required', 'An authenticated admin session is required.');
        }

        $email = (string) ($user['email'] ?? '');
        $passkeys = $this->passkeys->listForUser($email);

        JsonResponse::ok([
            'passkeys' => array_map(static function (array $passkey): array {
                unset($passkey['public_key_pem']);
                return $passkey;
            }, $passkeys),
        ]);
    }

    private function handleDeleteAdminPasskey(string $credentialId): void
    {
        $user = $this->currentAdminUser();
        if ($user === null) {
            throw new ApiException(401, 'admin_session_required', 'An authenticated admin session is required.');
        }

        $email = (string) ($user['email'] ?? '');
        if (!$this->passkeys->deleteForUser($email, $credentialId)) {
            throw new ApiException(404, 'passkey_not_found', 'The requested passkey was not found.');
        }

        JsonResponse::ok([
            'deleted' => true,
            'credential_id' => $credentialId,
        ]);
    }

    private function handlePasskeyRegistrationOptions(Request $request): void
    {
        if (!$this->isPasskeySupported()) {
            throw new ApiException(409, 'passkey_not_supported', 'Passkeys are not supported on this server.');
        }

        $user = $this->currentAdminUser();
        if ($user === null) {
            throw new ApiException(401, 'admin_session_required', 'An authenticated admin session is required.');
        }

        $body = $request->json();
        $label = trim((string) ($body['name'] ?? ''));
        if ($label === '') {
            $label = 'Passkey';
        }
        if (strlen($label) > 80) {
            $label = substr($label, 0, 80);
        }

        $email = strtolower(trim((string) ($user['email'] ?? '')));
        $name = trim((string) ($user['name'] ?? ''));
        $challenge = $this->base64UrlEncode(random_bytes(32));
        $rpId = $this->webAuthnRpId();
        $origin = $this->webAuthnOrigin();
        $userHandle = $this->base64UrlEncode(substr(hash('sha256', $email, true), 0, 16));

        $exclude = [];
        foreach ($this->passkeys->listForUser($email) as $passkey) {
            $credentialId = trim((string) ($passkey['credential_id'] ?? ''));
            if ($credentialId === '') {
                continue;
            }

            $exclude[] = [
                'type' => 'public-key',
                'id' => $credentialId,
                'transports' => is_array($passkey['transports'] ?? null) ? $passkey['transports'] : [],
            ];
        }

        $this->startAdminSession();
        $_SESSION[self::ADMIN_SESSION_PASSKEY_REGISTRATION_KEY] = [
            'email' => $email,
            'challenge' => $challenge,
            'rp_id' => $rpId,
            'origin' => $origin,
            'label' => $label,
            'expires_at' => time() + 300,
        ];

        JsonResponse::ok([
            'publicKey' => [
                'challenge' => $challenge,
                'rp' => [
                    'name' => 'PatchAgent Admin',
                    'id' => $rpId,
                ],
                'user' => [
                    'id' => $userHandle,
                    'name' => $email,
                    'displayName' => $name !== '' ? $name : $email,
                ],
                'pubKeyCredParams' => [
                    ['type' => 'public-key', 'alg' => -7],
                    ['type' => 'public-key', 'alg' => -257],
                ],
                'timeout' => 60000,
                'attestation' => 'none',
                'authenticatorSelection' => [
                    'userVerification' => 'required',
                    'residentKey' => 'preferred',
                ],
                'excludeCredentials' => $exclude,
            ],
        ]);
    }

    private function handlePasskeyRegistrationComplete(Request $request): void
    {
        if (!$this->isPasskeySupported()) {
            throw new ApiException(409, 'passkey_not_supported', 'Passkeys are not supported on this server.');
        }

        $user = $this->currentAdminUser();
        if ($user === null) {
            throw new ApiException(401, 'admin_session_required', 'An authenticated admin session is required.');
        }

        $this->startAdminSession();
        $pending = $_SESSION[self::ADMIN_SESSION_PASSKEY_REGISTRATION_KEY] ?? null;
        if (!is_array($pending)) {
            throw new ApiException(401, 'passkey_registration_not_pending', 'No pending passkey registration was found.');
        }

        $expiresAt = (int) ($pending['expires_at'] ?? 0);
        if ($expiresAt <= time()) {
            unset($_SESSION[self::ADMIN_SESSION_PASSKEY_REGISTRATION_KEY]);
            throw new ApiException(401, 'passkey_registration_expired', 'Passkey registration has expired. Start again.');
        }

        $email = strtolower(trim((string) ($user['email'] ?? '')));
        $pendingEmail = strtolower(trim((string) ($pending['email'] ?? '')));
        if ($email === '' || $pendingEmail === '' || !hash_equals($pendingEmail, $email)) {
            unset($_SESSION[self::ADMIN_SESSION_PASSKEY_REGISTRATION_KEY]);
            throw new ApiException(401, 'passkey_registration_user_mismatch', 'Passkey registration user mismatch.');
        }

        $body = $request->json();
        $credentialId = trim((string) ($body['credential_id'] ?? $body['id'] ?? ''));
        if ($credentialId === '' || preg_match('/^[A-Za-z0-9_-]+$/', $credentialId) !== 1) {
            throw new ApiException(422, 'invalid_request', 'Field "credential_id" is required.');
        }
        $clientDataJsonRaw = $this->base64UrlDecode((string) ($body['client_data_json'] ?? ''));
        if ($clientDataJsonRaw === null) {
            throw new ApiException(422, 'invalid_request', 'Field "client_data_json" is required.');
        }

        $clientData = json_decode($clientDataJsonRaw, true);
        if (!is_array($clientData)) {
            throw new ApiException(422, 'invalid_request', 'Invalid WebAuthn client_data_json payload.');
        }

        $clientType = trim((string) ($clientData['type'] ?? ''));
        if ($clientType !== 'webauthn.create') {
            throw new ApiException(422, 'invalid_request', 'Unexpected WebAuthn client data type for registration.');
        }

        $expectedChallenge = trim((string) ($pending['challenge'] ?? ''));
        $receivedChallenge = trim((string) ($clientData['challenge'] ?? ''));
        if ($expectedChallenge === '' || $receivedChallenge === '' || !hash_equals($expectedChallenge, $receivedChallenge)) {
            throw new ApiException(401, 'invalid_passkey_challenge', 'Passkey challenge validation failed.');
        }

        $expectedOrigin = trim((string) ($pending['origin'] ?? ''));
        $receivedOrigin = trim((string) ($clientData['origin'] ?? ''));
        if ($expectedOrigin === '' || $receivedOrigin === '' || !hash_equals($expectedOrigin, $receivedOrigin)) {
            throw new ApiException(401, 'invalid_passkey_origin', 'Passkey origin validation failed.');
        }

        $publicKeySpkiRaw = $this->base64UrlDecode((string) ($body['public_key_spki'] ?? ''));
        if ($publicKeySpkiRaw === null || $publicKeySpkiRaw === '') {
            throw new ApiException(422, 'invalid_request', 'Field "public_key_spki" is required.');
        }

        $publicKeyPem = $this->spkiBinaryToPem($publicKeySpkiRaw);
        if (openssl_pkey_get_public($publicKeyPem) === false) {
            throw new ApiException(422, 'invalid_request', 'Field "public_key_spki" is not a valid public key.');
        }

        $authenticatorDataRaw = $this->base64UrlDecode((string) ($body['authenticator_data'] ?? ''));
        $rpId = trim((string) ($pending['rp_id'] ?? ''));
        if ($authenticatorDataRaw !== null && $authenticatorDataRaw !== '') {
            $parsedAuth = $this->parseAuthenticatorData($authenticatorDataRaw, $rpId);
            if (($parsedAuth['user_present'] ?? false) !== true) {
                throw new ApiException(401, 'passkey_user_presence_required', 'Passkey user presence is required.');
            }
            if (($parsedAuth['user_verified'] ?? false) !== true) {
                throw new ApiException(401, 'passkey_user_verification_required', 'Passkey user verification is required.');
            }
        }

        $label = trim((string) ($body['name'] ?? (string) ($pending['label'] ?? 'Passkey')));
        $transports = is_array($body['transports'] ?? null) ? $body['transports'] : [];

        try {
            $saved = $this->passkeys->saveForUser($email, [
                'credential_id' => $credentialId,
                'name' => $label,
                'public_key_pem' => $publicKeyPem,
                'counter' => 0,
                'transports' => $transports,
            ]);
        } catch (\RuntimeException $exception) {
            throw new ApiException(422, 'invalid_request', $exception->getMessage());
        }

        unset($_SESSION[self::ADMIN_SESSION_PASSKEY_REGISTRATION_KEY]);

        $publicView = $saved;
        unset($publicView['public_key_pem']);
        JsonResponse::ok([
            'registered' => true,
            'passkey' => $publicView,
        ]);
    }

    private function handlePasskeyAssertionBegin(): void
    {
        if (!$this->isPasskeySupported()) {
            throw new ApiException(409, 'passkey_not_supported', 'Passkeys are not supported on this server.');
        }

        $pendingClaims = $this->currentPendingTotpClaims();
        if ($pendingClaims === null) {
            throw new ApiException(401, 'passkey_not_pending', 'No pending passkey challenge was found. Start login again.');
        }

        $email = strtolower(trim((string) ($pendingClaims['email'] ?? '')));
        $passkeys = $this->passkeys->listForUser($email);
        if (count($passkeys) === 0) {
            throw new ApiException(404, 'passkey_not_enrolled', 'No passkeys are registered for this user.');
        }

        $challenge = $this->base64UrlEncode(random_bytes(32));
        $rpId = $this->webAuthnRpId();
        $origin = $this->webAuthnOrigin();
        $allowCredentials = [];

        foreach ($passkeys as $passkey) {
            $credentialId = trim((string) ($passkey['credential_id'] ?? ''));
            if ($credentialId === '') {
                continue;
            }

            $allowCredentials[] = [
                'type' => 'public-key',
                'id' => $credentialId,
                'transports' => is_array($passkey['transports'] ?? null) ? $passkey['transports'] : [],
            ];
        }

        $this->startAdminSession();
        $_SESSION[self::ADMIN_SESSION_PASSKEY_ASSERTION_KEY] = [
            'email' => $email,
            'challenge' => $challenge,
            'rp_id' => $rpId,
            'origin' => $origin,
            'expires_at' => time() + 300,
        ];

        JsonResponse::ok([
            'publicKey' => [
                'challenge' => $challenge,
                'rpId' => $rpId,
                'allowCredentials' => $allowCredentials,
                'timeout' => 60000,
                'userVerification' => 'required',
            ],
        ]);
    }

    private function handlePasskeyAssertionVerify(Request $request): void
    {
        if (!$this->isPasskeySupported()) {
            throw new ApiException(409, 'passkey_not_supported', 'Passkeys are not supported on this server.');
        }

        $pendingClaims = $this->currentPendingTotpClaims();
        if ($pendingClaims === null) {
            throw new ApiException(401, 'passkey_not_pending', 'No pending passkey challenge was found. Start login again.');
        }

        $this->startAdminSession();
        $pending = $_SESSION[self::ADMIN_SESSION_PASSKEY_ASSERTION_KEY] ?? null;
        if (!is_array($pending)) {
            throw new ApiException(401, 'passkey_not_pending', 'No pending passkey challenge was found. Start login again.');
        }

        $expiresAt = (int) ($pending['expires_at'] ?? 0);
        if ($expiresAt <= time()) {
            unset($_SESSION[self::ADMIN_SESSION_PASSKEY_ASSERTION_KEY]);
            throw new ApiException(401, 'passkey_challenge_expired', 'Passkey challenge has expired. Start login again.');
        }

        $email = strtolower(trim((string) ($pendingClaims['email'] ?? '')));
        $pendingEmail = strtolower(trim((string) ($pending['email'] ?? '')));
        if ($email === '' || $pendingEmail === '' || !hash_equals($pendingEmail, $email)) {
            unset($_SESSION[self::ADMIN_SESSION_PASSKEY_ASSERTION_KEY]);
            throw new ApiException(401, 'passkey_user_mismatch', 'Passkey user mismatch.');
        }

        $body = $request->json();
        $credentialId = trim((string) ($body['credential_id'] ?? $body['id'] ?? ''));
        if ($credentialId === '' || preg_match('/^[A-Za-z0-9_-]+$/', $credentialId) !== 1) {
            throw new ApiException(422, 'invalid_request', 'Field "credential_id" is required.');
        }

        $credential = $this->passkeys->findForUserByCredentialId($email, $credentialId);
        if ($credential === null) {
            throw new ApiException(404, 'passkey_not_found', 'The passkey credential was not found.');
        }

        $clientDataJsonRaw = $this->base64UrlDecode((string) ($body['client_data_json'] ?? ''));
        $authenticatorDataRaw = $this->base64UrlDecode((string) ($body['authenticator_data'] ?? ''));
        $signatureRaw = $this->base64UrlDecode((string) ($body['signature'] ?? ''));
        if ($clientDataJsonRaw === null || $authenticatorDataRaw === null || $signatureRaw === null) {
            throw new ApiException(422, 'invalid_request', 'Passkey verification payload is incomplete.');
        }

        $clientData = json_decode($clientDataJsonRaw, true);
        if (!is_array($clientData)) {
            throw new ApiException(422, 'invalid_request', 'Invalid WebAuthn client_data_json payload.');
        }

        $clientType = trim((string) ($clientData['type'] ?? ''));
        if ($clientType !== 'webauthn.get') {
            throw new ApiException(422, 'invalid_request', 'Unexpected WebAuthn client data type for assertion.');
        }

        $expectedChallenge = trim((string) ($pending['challenge'] ?? ''));
        $receivedChallenge = trim((string) ($clientData['challenge'] ?? ''));
        if ($expectedChallenge === '' || $receivedChallenge === '' || !hash_equals($expectedChallenge, $receivedChallenge)) {
            throw new ApiException(401, 'invalid_passkey_challenge', 'Passkey challenge validation failed.');
        }

        $expectedOrigin = trim((string) ($pending['origin'] ?? ''));
        $receivedOrigin = trim((string) ($clientData['origin'] ?? ''));
        if ($expectedOrigin === '' || $receivedOrigin === '' || !hash_equals($expectedOrigin, $receivedOrigin)) {
            throw new ApiException(401, 'invalid_passkey_origin', 'Passkey origin validation failed.');
        }

        $rpId = trim((string) ($pending['rp_id'] ?? ''));
        $parsedAuth = $this->parseAuthenticatorData($authenticatorDataRaw, $rpId);
        if (($parsedAuth['user_present'] ?? false) !== true) {
            throw new ApiException(401, 'passkey_user_presence_required', 'Passkey user presence is required.');
        }
        if (($parsedAuth['user_verified'] ?? false) !== true) {
            throw new ApiException(401, 'passkey_user_verification_required', 'Passkey user verification is required.');
        }

        $clientHash = hash('sha256', $clientDataJsonRaw, true);
        $signedData = $authenticatorDataRaw . $clientHash;
        $publicKeyPem = (string) ($credential['public_key_pem'] ?? '');
        $verify = openssl_verify($signedData, $signatureRaw, $publicKeyPem, OPENSSL_ALGO_SHA256);
        if ($verify !== 1) {
            throw new ApiException(401, 'invalid_passkey_signature', 'Passkey signature validation failed.');
        }

        $previousCounter = max(0, (int) ($credential['counter'] ?? 0));
        $currentCounter = max(0, (int) ($parsedAuth['counter'] ?? 0));
        if ($previousCounter > 0 && $currentCounter > 0 && $currentCounter <= $previousCounter) {
            throw new ApiException(401, 'invalid_passkey_counter', 'Passkey sign counter did not advance.');
        }

        $this->passkeys->updateCounterAndLastUsed($email, $credentialId, $currentCounter);
        unset($_SESSION[self::ADMIN_SESSION_PASSKEY_ASSERTION_KEY]);

        $this->authenticateAdminUser($pendingClaims);

        JsonResponse::ok([
            'verified' => true,
            'logged_in' => true,
            'user' => $this->currentAdminUser(),
            'redirect' => '/admin',
        ]);
    }

    private function authenticateAdminUser(array $claims): void
    {
        $sessionTtl = max(300, $this->config->adminSessionTtlSeconds);
        $_SESSION[self::ADMIN_SESSION_USER_KEY] = [
            'email' => strtolower(trim((string) ($claims['email'] ?? ''))),
            'name' => trim((string) ($claims['name'] ?? '')),
            'picture' => trim((string) ($claims['picture'] ?? '')),
            'sub' => trim((string) ($claims['sub'] ?? '')),
            'hd' => trim((string) ($claims['hd'] ?? '')),
            'authenticated_at' => gmdate(DATE_ATOM),
            'expires_at' => time() + $sessionTtl,
        ];
        unset(
            $_SESSION[self::ADMIN_SESSION_TOTP_PENDING_KEY],
            $_SESSION[self::ADMIN_SESSION_PASSKEY_ASSERTION_KEY]
        );
        session_regenerate_id(true);
    }

    private function startPendingTotpChallenge(array $claims): void
    {
        $challengeTtl = max(60, $this->config->adminTotpChallengeTtlSeconds);
        $_SESSION[self::ADMIN_SESSION_TOTP_PENDING_KEY] = [
            'email' => strtolower(trim((string) ($claims['email'] ?? ''))),
            'name' => trim((string) ($claims['name'] ?? '')),
            'picture' => trim((string) ($claims['picture'] ?? '')),
            'sub' => trim((string) ($claims['sub'] ?? '')),
            'hd' => trim((string) ($claims['hd'] ?? '')),
            'started_at' => time(),
            'challenge_expires_at' => time() + $challengeTtl,
        ];
        unset(
            $_SESSION[self::ADMIN_SESSION_USER_KEY],
            $_SESSION[self::ADMIN_SESSION_PASSKEY_ASSERTION_KEY]
        );
        session_regenerate_id(true);
    }

    private function currentPendingTotpUser(): ?array
    {
        $pending = $this->currentPendingTotpClaims();
        if ($pending === null) {
            return null;
        }

        return [
            'email' => (string) ($pending['email'] ?? ''),
            'name' => (string) ($pending['name'] ?? ''),
            'challenge_expires_at' => (int) ($pending['challenge_expires_at'] ?? 0),
        ];
    }

    private function currentPendingTotpClaims(): ?array
    {
        $this->startAdminSession();

        $pending = $_SESSION[self::ADMIN_SESSION_TOTP_PENDING_KEY] ?? null;
        if (!is_array($pending)) {
            return null;
        }

        $email = strtolower(trim((string) ($pending['email'] ?? '')));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            unset(
                $_SESSION[self::ADMIN_SESSION_TOTP_PENDING_KEY],
                $_SESSION[self::ADMIN_SESSION_PASSKEY_ASSERTION_KEY]
            );
            return null;
        }

        $expiresAt = (int) ($pending['challenge_expires_at'] ?? 0);
        if ($expiresAt <= time()) {
            unset(
                $_SESSION[self::ADMIN_SESSION_TOTP_PENDING_KEY],
                $_SESSION[self::ADMIN_SESSION_PASSKEY_ASSERTION_KEY]
            );
            return null;
        }

        return [
            'email' => $email,
            'name' => trim((string) ($pending['name'] ?? '')),
            'picture' => trim((string) ($pending['picture'] ?? '')),
            'sub' => trim((string) ($pending['sub'] ?? '')),
            'hd' => trim((string) ($pending['hd'] ?? '')),
            'challenge_expires_at' => $expiresAt,
        ];
    }

    private function isAdminTotpEnabled(): bool
    {
        return $this->normalizeTotpSecret($this->config->adminTotpSecret) !== '';
    }

    private function verifyAdminTotpCode(string $code): bool
    {
        $normalizedCode = preg_replace('/\D+/', '', trim($code));
        if (!is_string($normalizedCode) || strlen($normalizedCode) !== 6) {
            return false;
        }

        $secret = $this->decodeBase32Secret($this->normalizeTotpSecret($this->config->adminTotpSecret));
        if ($secret === '') {
            return false;
        }

        $counter = intdiv(time(), 30);
        $window = max(0, $this->config->adminTotpWindow);
        for ($offset = -$window; $offset <= $window; $offset++) {
            $expectedCode = $this->computeTotpCodeForCounter($secret, $counter + $offset);
            if (hash_equals($expectedCode, $normalizedCode)) {
                return true;
            }
        }

        return false;
    }

    private function computeTotpCodeForCounter(string $secret, int $counter): string
    {
        $high = ($counter >> 32) & 0xFFFFFFFF;
        $low = $counter & 0xFFFFFFFF;
        $counterBytes = pack('N2', $high, $low);

        $hash = hash_hmac('sha1', $counterBytes, $secret, true);
        if (!is_string($hash) || strlen($hash) < 20) {
            return '000000';
        }

        $offset = ord($hash[19]) & 0x0F;
        $binaryCode = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($binaryCode % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private function normalizeTotpSecret(string $secret): string
    {
        $normalized = strtoupper(trim($secret));
        $normalized = preg_replace('/[^A-Z2-7]/', '', $normalized);
        return is_string($normalized) ? $normalized : '';
    }

    private function decodeBase32Secret(string $secret): string
    {
        if ($secret === '') {
            return '';
        }

        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        $length = strlen($secret);

        for ($index = 0; $index < $length; $index++) {
            $position = strpos($alphabet, $secret[$index]);
            if ($position === false) {
                return '';
            }

            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }

        $output = '';
        for ($index = 0; $index + 8 <= strlen($bits); $index += 8) {
            $output .= chr(bindec(substr($bits, $index, 8)));
        }

        return $output;
    }

    private function isPasskeySupported(): bool
    {
        return function_exists('openssl_verify')
            && function_exists('openssl_pkey_get_public')
            && function_exists('random_bytes');
    }

    private function webAuthnOrigin(): string
    {
        return $this->baseUrlFromServer();
    }

    private function webAuthnRpId(): string
    {
        $host = (string) parse_url($this->baseUrlFromServer(), PHP_URL_HOST);
        $host = strtolower(trim($host));
        if ($host === '') {
            throw new ApiException(500, 'server_error', 'Unable to resolve WebAuthn RP ID for this request.');
        }

        return $host;
    }

    private function parseAuthenticatorData(string $authenticatorData, string $rpId): array
    {
        if (strlen($authenticatorData) < 37) {
            throw new ApiException(422, 'invalid_request', 'Authenticator data is invalid.');
        }

        $rpIdHash = substr($authenticatorData, 0, 32);
        $expectedRpIdHash = hash('sha256', $rpId, true);
        if (!hash_equals($expectedRpIdHash, $rpIdHash)) {
            throw new ApiException(401, 'invalid_passkey_rp_id', 'Passkey RP ID validation failed.');
        }

        $flags = ord($authenticatorData[32]);
        $counterData = substr($authenticatorData, 33, 4);
        $counterParts = unpack('Ncounter', $counterData);
        $counter = is_array($counterParts) ? (int) ($counterParts['counter'] ?? 0) : 0;

        return [
            'flags' => $flags,
            'counter' => max(0, $counter),
            'user_present' => ($flags & 0x01) === 0x01,
            'user_verified' => ($flags & 0x04) === 0x04,
        ];
    }

    private function spkiBinaryToPem(string $binary): string
    {
        $encoded = chunk_split(base64_encode($binary), 64, "\n");
        return "-----BEGIN PUBLIC KEY-----\n" . $encoded . "-----END PUBLIC KEY-----\n";
    }

    private function base64UrlEncode(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): ?string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/^[A-Za-z0-9+\/_=-]+$/', $trimmed) !== 1) {
            return null;
        }

        $normalized = str_replace(['-', '_'], ['+', '/'], $trimmed);
        $padding = strlen($normalized) % 4;
        if ($padding > 0) {
            $normalized .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($normalized, true);
        return is_string($decoded) ? $decoded : null;
    }

    private function servePublicHtmlFile(string $filename, string $missingMessage, bool $injectScriptNonce = false): void
    {
        $path = dirname(__DIR__) . '/public/' . $filename;
        if (!is_file($path)) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo $missingMessage;
            return;
        }

        if (!$injectScriptNonce) {
            http_response_code(200);
            header('Content-Type: text/html; charset=utf-8');
            readfile($path);
            return;
        }

        $html = file_get_contents($path);
        if (!is_string($html)) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo $missingMessage;
            return;
        }

        $nonce = $this->generateCspNonce();
        $html = $this->injectScriptNonceIntoHtml($html, $nonce);

        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        header('X-CSP-Nonce: ' . $nonce);
        echo $html;
    }

    private function generateCspNonce(): string
    {
        try {
            $bytes = random_bytes(18);
        } catch (Throwable) {
            $bytes = hash('sha256', uniqid('patchagent_nonce_', true), true);
        }

        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function injectScriptNonceIntoHtml(string $html, string $nonce): string
    {
        $result = preg_replace_callback('/<script\b([^>]*)>/i', static function (array $matches) use ($nonce): string {
            $attributes = $matches[1] ?? '';
            if (preg_match('/\bnonce\s*=/i', $attributes) === 1) {
                return $matches[0];
            }

            return '<script' . $attributes . ' nonce="' . htmlspecialchars($nonce, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
        }, $html);

        return is_string($result) ? $result : $html;
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
        $installMode = $this->normalizeWindowsInstallMode($request->queryParam('mode'));
        echo $this->buildWindowsInstallScript($request->baseUrl(), $enrollmentKey, $installMode);
    }

    private function handleMacOsInstallScript(Request $request): void
    {
        $enrollmentKey = $request->queryParam('enrollment_key');
        if ($enrollmentKey === null || !$this->enrollments->isEnrollmentKeyActive($enrollmentKey)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Invalid or expired enrollment key.\n";
            return;
        }

        header('Content-Type: text/x-shellscript; charset=utf-8');
        header('Content-Disposition: inline; filename="install-winpatchagent-macos.sh"');
        echo $this->buildMacOsInstallScript($request->baseUrl(), $enrollmentKey);
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

    private function normalizeWindowsInstallMode(mixed $value): string
    {
        if (!is_string($value)) {
            return 'prebuilt';
        }

        $normalized = strtolower(trim($value));
        return match ($normalized) {
            'source', 'compile', 'build' => 'source',
            default => 'prebuilt',
        };
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

if ! command -v apt-get >/dev/null 2>&1; then
  echo "apt-get was not found. This installer currently supports Ubuntu/Debian." >&2
  exit 1
fi

if ! command -v tar >/dev/null 2>&1 || (! command -v curl >/dev/null 2>&1 && ! command -v wget >/dev/null 2>&1); then
  apt-get update
  DEBIAN_FRONTEND=noninteractive apt-get install -y ca-certificates curl wget tar
fi

normalize_repo_url() {
  local raw="\$1"
  raw="\${raw%/}"
  if [[ "\${raw}" == git@github.com:* ]]; then
    raw="https://github.com/\${raw#git@github.com:}"
  fi
  raw="\${raw%.git}"
  printf '%s' "\${raw}"
}

build_archive_url() {
  local repo_http
  repo_http="$(normalize_repo_url "\$1")"
  printf '%s/archive/%s.tar.gz' "\${repo_http}" "\$2"
}

download_file() {
  local url="\$1"
  local output="\$2"
  if command -v wget >/dev/null 2>&1; then
    wget -qO "\${output}" "\${url}"
    return 0
  fi
  if command -v curl >/dev/null 2>&1; then
    curl -fsSL "\${url}" -o "\${output}"
    return 0
  fi
  return 1
}

ARCHIVE_URL="$(build_archive_url "\${REPO_URL}" "\${REPO_REF}")"
TMP_ARCHIVE="$(mktemp /tmp/winpatchagent-src.XXXXXX.tar.gz)"
TMP_EXTRACT="$(mktemp -d /tmp/winpatchagent-src.XXXXXX)"

cleanup() {
  rm -f "\${TMP_ARCHIVE}" || true
  rm -rf "\${TMP_EXTRACT}" || true
}
trap cleanup EXIT

if ! download_file "\${ARCHIVE_URL}" "\${TMP_ARCHIVE}"; then
  echo "Failed to download source archive: \${ARCHIVE_URL}" >&2
  exit 1
fi

tar -xzf "\${TMP_ARCHIVE}" -C "\${TMP_EXTRACT}"
SOURCE_DIR="$(find "\${TMP_EXTRACT}" -mindepth 1 -maxdepth 1 -type d | head -n 1)"
if [[ -z "\${SOURCE_DIR}" || ! -x "\${SOURCE_DIR}/scripts/setup_ubuntu_agent.sh" ]]; then
  echo "Downloaded archive did not contain scripts/setup_ubuntu_agent.sh" >&2
  exit 1
fi

rm -rf "\${WORK_DIR}"
mv "\${SOURCE_DIR}" "\${WORK_DIR}"

bash "\${WORK_DIR}/scripts/setup_ubuntu_agent.sh" \\
  --backend-url "\${BACKEND_URL}" \\
  --enrollment-key "\${ENROLLMENT_KEY}"
BASH;
    }

    private function buildWindowsInstallScript(string $baseUrl, string $enrollmentKey, string $installMode): string
    {
        $backendUrlLiteral = $this->powershellLiteral($baseUrl);
        $enrollmentKeyLiteral = $this->powershellLiteral($enrollmentKey);
        $installModeLiteral = $this->powershellLiteral($installMode);
        $repoUrlLiteral = $this->powershellLiteral(self::AGENT_REPO_URL);
        $repoRefLiteral = $this->powershellLiteral(self::AGENT_REPO_REF);
        $windowsPackageUrlLiteral = $this->powershellLiteral($this->config->windowsAgentPackageUrl);
        $splashtopMsiUrlLiteral = $this->powershellLiteral($this->config->windowsSplashtopMsiUrl);
        $splashtopDeploymentCodeLiteral = $this->powershellLiteral($this->config->windowsSplashtopDeploymentCode);
        $windowsDisableRemovableStorageLiteral = $this->config->windowsDisableRemovableStorageOnInstall ? '$true' : '$false';
        $windowsEnsureDefenderLiteral = $this->config->windowsEnsureDefenderOnInstall ? '$true' : '$false';

        return <<<POWERSHELL
\$ErrorActionPreference = "Stop"

\$BackendUrl = {$backendUrlLiteral}
\$EnrollmentKey = {$enrollmentKeyLiteral}
\$InstallMode = {$installModeLiteral}
\$RepoUrl = {$repoUrlLiteral}
\$RepoRef = {$repoRefLiteral}
\$WindowsAgentPackageUrl = {$windowsPackageUrlLiteral}
\$SplashtopMsiUrl = {$splashtopMsiUrlLiteral}
\$SplashtopDeploymentCode = {$splashtopDeploymentCodeLiteral}
\$DisableRemovableStorageOnInstall = {$windowsDisableRemovableStorageLiteral}
\$EnsureDefenderOnInstall = {$windowsEnsureDefenderLiteral}
\$WorkDir = "C:\\ProgramData\\WinPatchAgent\\src"
\$InstallDir = "C:\\Program Files\\WinPatchAgent"
\$ServiceName = "PatchAgentSvc"
\$StateDir = "C:\\ProgramData\\WinPatchAgent\\state"

if ([string]::IsNullOrWhiteSpace(\$InstallMode)) {
    \$InstallMode = "prebuilt"
}
\$InstallMode = \$InstallMode.Trim().ToLowerInvariant()
if (\$InstallMode -ne "source" -and \$InstallMode -ne "prebuilt") {
    \$InstallMode = "prebuilt"
}

function Resolve-PackageInstallRoot {
    param(
        [Parameter(Mandatory = \$true)]
        [string]\$ExtractRoot
    )

    \$candidateDirs = @(\$ExtractRoot)
    \$topLevel = Get-ChildItem -Path \$ExtractRoot -Directory -ErrorAction SilentlyContinue
    foreach (\$dir in \$topLevel) {
        \$candidateDirs += \$dir.FullName
    }

    foreach (\$candidate in \$candidateDirs) {
        if (Test-Path (Join-Path \$candidate "PatchAgent.Service.exe")) {
            return \$candidate
        }

        \$match = Get-ChildItem -Path \$candidate -Recurse -File -Filter "PatchAgent.Service.exe" -ErrorAction SilentlyContinue | Select-Object -First 1
        if (\$match) {
            return (Split-Path -Parent \$match.FullName)
        }
    }

    throw "Package did not contain PatchAgent.Service.exe."
}

function Stop-And-RemoveService {
    \$existing = Get-Service -Name \$ServiceName -ErrorAction SilentlyContinue
    if (\$existing) {
        Stop-Service -Name \$ServiceName -Force -ErrorAction SilentlyContinue
        sc.exe delete \$ServiceName | Out-Null
        Start-Sleep -Seconds 2
    }
}

function Get-DotnetCommand {
    \$cmd = Get-Command dotnet -ErrorAction SilentlyContinue
    if (\$cmd) {
        return \$cmd.Source
    }

    \$fallback = "C:\\Program Files\\dotnet\\dotnet.exe"
    if (Test-Path \$fallback) {
        return \$fallback
    }

    return \$null
}

function Get-DotnetSdkMajorVersions {
    param([string]\$DotnetExe)

    if ([string]::IsNullOrWhiteSpace(\$DotnetExe)) {
        return @()
    }

    try {
        \$lines = & \$DotnetExe --list-sdks 2>\$null
    } catch {
        return @()
    }

    \$majors = @()
    foreach (\$line in \$lines) {
        if (\$line -match "^\\s*(\\d+)\\.") {
            \$majors += [int]\$Matches[1]
        }
    }
    return \$majors
}

function Ensure-DotnetSdk8 {
    \$dotnetExe = Get-DotnetCommand
    \$majors = Get-DotnetSdkMajorVersions -DotnetExe \$dotnetExe
    if (\$majors | Where-Object { \$_ -ge 8 }) {
        return \$dotnetExe
    }

    Write-Host ".NET SDK 8+ not found. Installing .NET SDK 8..."
    [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
    \$installerPath = Join-Path \$env:TEMP ("dotnet-install-" + [guid]::NewGuid().ToString("N") + ".ps1")
    try {
        Invoke-WebRequest -UseBasicParsing -Uri "https://dot.net/v1/dotnet-install.ps1" -OutFile \$installerPath
        & powershell -NoProfile -ExecutionPolicy Bypass -File \$installerPath -Channel "8.0" -InstallDir "C:\\Program Files\\dotnet" -Architecture "x64" | Out-Null
    } finally {
        Remove-Item -Path \$installerPath -Force -ErrorAction SilentlyContinue
    }

    \$env:PATH = "C:\\Program Files\\dotnet;" + \$env:PATH
    \$dotnetExe = Get-DotnetCommand
    \$majors = Get-DotnetSdkMajorVersions -DotnetExe \$dotnetExe
    if (-not (\$majors | Where-Object { \$_ -ge 8 })) {
        throw "Failed to install .NET SDK 8."
    }

    return \$dotnetExe
}

function Normalize-RepoHttpUrl {
    param([string]\$RawUrl)

    if (\$null -eq \$RawUrl) {
        \$url = ""
    } else {
        \$url = [string]\$RawUrl
    }
    \$url = \$url.Trim()
    if ([string]::IsNullOrWhiteSpace(\$url)) {
        throw "Repo URL is empty."
    }

    \$url = \$url.TrimEnd("/")
    if (\$url -match "^git@github\\.com:(.+?)(?:\\.git)?\$") {
        return "https://github.com/\$($Matches[1])"
    }

    if (\$url.EndsWith(".git", [System.StringComparison]::OrdinalIgnoreCase)) {
        \$url = \$url.Substring(0, \$url.Length - 4)
    }

    if (\$url -notmatch "^https?://") {
        throw "Unsupported repo URL format: \$RawUrl"
    }

    return \$url
}

function Build-ArchiveUrl {
    param([string]\$RawRepoUrl, [string]\$RawRepoRef)

    \$repoHttpUrl = Normalize-RepoHttpUrl -RawUrl \$RawRepoUrl
    return "\$repoHttpUrl/archive/\$RawRepoRef.zip"
}

function Clear-InstallDir {
    if (Test-Path \$InstallDir) {
        Get-ChildItem -Path \$InstallDir -Force -ErrorAction SilentlyContinue | Remove-Item -Recurse -Force -ErrorAction SilentlyContinue
    } else {
        New-Item -ItemType Directory -Path \$InstallDir -Force | Out-Null
    }
}

function Install-FromPrebuilt {
    if ([string]::IsNullOrWhiteSpace(\$WindowsAgentPackageUrl)) {
        throw "Windows prebuilt package URL is empty. Set PATCH_API_WINDOWS_AGENT_PACKAGE_URL on the API server."
    }

    \$archivePath = Join-Path \$env:TEMP ("winpatchagent-prebuilt-" + [guid]::NewGuid().ToString("N") + ".zip")
    \$extractRoot = Join-Path \$env:TEMP ("winpatchagent-prebuilt-" + [guid]::NewGuid().ToString("N"))

    try {
        Invoke-WebRequest -UseBasicParsing -Uri \$WindowsAgentPackageUrl -OutFile \$archivePath
        New-Item -ItemType Directory -Path \$extractRoot -Force | Out-Null
        Expand-Archive -Path \$archivePath -DestinationPath \$extractRoot -Force
        \$packageRoot = Resolve-PackageInstallRoot -ExtractRoot \$extractRoot

        Clear-InstallDir
        Get-ChildItem -Path \$packageRoot -Force | ForEach-Object {
            Copy-Item -Path \$_.FullName -Destination \$InstallDir -Recurse -Force
        }
    } finally {
        Remove-Item -Path \$archivePath -Force -ErrorAction SilentlyContinue
        Remove-Item -Path \$extractRoot -Recurse -Force -ErrorAction SilentlyContinue
    }
}

function Install-FromSource {
    \$archiveUrl = Build-ArchiveUrl -RawRepoUrl \$RepoUrl -RawRepoRef \$RepoRef
    \$archivePath = Join-Path \$env:TEMP ("winpatchagent-src-" + [guid]::NewGuid().ToString("N") + ".zip")
    \$extractRoot = Join-Path \$env:TEMP ("winpatchagent-src-" + [guid]::NewGuid().ToString("N"))

    try {
        Invoke-WebRequest -UseBasicParsing -Uri \$archiveUrl -OutFile \$archivePath
        New-Item -ItemType Directory -Path \$extractRoot -Force | Out-Null
        Expand-Archive -Path \$archivePath -DestinationPath \$extractRoot -Force

        \$extractedDir = Get-ChildItem -Path \$extractRoot -Directory | Select-Object -First 1
        if (-not \$extractedDir) {
            throw "Downloaded archive did not contain source files."
        }

        if (Test-Path \$WorkDir) {
            Remove-Item -Path \$WorkDir -Recurse -Force -ErrorAction SilentlyContinue
        }

        \$workParent = Split-Path -Parent \$WorkDir
        if (-not [string]::IsNullOrWhiteSpace(\$workParent)) {
            New-Item -ItemType Directory -Path \$workParent -Force | Out-Null
        }

        Move-Item -Path \$extractedDir.FullName -Destination \$WorkDir
    } finally {
        Remove-Item -Path \$archivePath -Force -ErrorAction SilentlyContinue
        Remove-Item -Path \$extractRoot -Recurse -Force -ErrorAction SilentlyContinue
    }

    \$projectPath = Join-Path \$WorkDir "src\\PatchAgent.Service\\PatchAgent.Service.csproj"
    if (-not (Test-Path \$projectPath)) {
        throw "Project file not found after source download: \$projectPath"
    }

    Clear-InstallDir
    \$dotnetExe = Ensure-DotnetSdk8
    & \$dotnetExe publish \$projectPath -c Release -r win-x64 --self-contained true -o \$InstallDir
    if (\$LASTEXITCODE -eq 0) {
        return
    }

    \$publishExitCode = \$LASTEXITCODE
    if (-not [string]::IsNullOrWhiteSpace(\$WindowsAgentPackageUrl)) {
        Write-Warning ("Source build failed with exit code {0}. Falling back to prebuilt package URL." -f \$publishExitCode)
        Install-FromPrebuilt
        return
    }

    throw ("dotnet publish failed with exit code {0} and no prebuilt package fallback URL is configured." -f \$publishExitCode)
}

function Write-AgentConfig {
    \$configPath = Join-Path \$InstallDir "appsettings.Production.json"
    \$configObject = @{
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
            EnableWindowsUpdateJobExecution = \$true
            WindowsUpdateCommandTimeoutSeconds = 5400
            EnableWindowsPowerShellScriptExecution = \$true
            WindowsPowerShellScriptCommandTimeoutSeconds = 3600
            EnableMacSoftwareUpdateJobExecution = \$false
            MacSoftwareUpdateCommandTimeoutSeconds = 5400
            WindowsSelfUpdatePackageUrl = \$WindowsAgentPackageUrl
        }
    }
    \$configObject | ConvertTo-Json -Depth 8 | Set-Content -Path \$configPath -Encoding UTF8
}

function Install-Splashtop {
    if ([string]::IsNullOrWhiteSpace(\$SplashtopMsiUrl)) {
        Write-Host "Splashtop auto-install: skipped (PATCH_API_WINDOWS_SPLASHTOP_MSI_URL is not set on server)."
        return
    }

    if (\$SplashtopMsiUrl -notmatch "^https?://") {
        throw "Splashtop auto-install URL must start with http:// or https://. Current value: \$SplashtopMsiUrl"
    }

    \$splashtopExtension = ".msi"
    try {
        \$splashtopUri = [System.Uri]\$SplashtopMsiUrl
        \$candidateExtension = [System.IO.Path]::GetExtension(\$splashtopUri.AbsolutePath)
        if (-not [string]::IsNullOrWhiteSpace(\$candidateExtension)) {
            \$splashtopExtension = \$candidateExtension.ToLowerInvariant()
        }
    } catch {
        \$splashtopExtension = ".msi"
    }

    if (\$splashtopExtension -ne ".msi" -and \$splashtopExtension -ne ".exe") {
        Write-Host "Splashtop auto-install: unknown extension '\$splashtopExtension', defaulting to MSI mode."
        \$splashtopExtension = ".msi"
    }

    \$splashtopInstallerPath = Join-Path \$env:TEMP ("splashtop-streamer-" + [guid]::NewGuid().ToString("N") + \$splashtopExtension)
    Write-Host "Splashtop auto-install: downloading installer..."
    Invoke-WebRequest -Uri \$SplashtopMsiUrl -OutFile \$splashtopInstallerPath

    try {
        \$splashtopUserInfoParts = @("hidewindow=1", "confirm_d=0")
        if (-not [string]::IsNullOrWhiteSpace(\$SplashtopDeploymentCode)) {
            \$splashtopUserInfoParts = @("dcode=\$SplashtopDeploymentCode") + \$splashtopUserInfoParts
        } else {
            Write-Host "Splashtop auto-install: no deploy code configured, assuming installer has embedded code."
        }

        \$splashtopUserInfo = [string]::Join(",", \$splashtopUserInfoParts)
        if (\$splashtopExtension -eq ".exe") {
            \$splashtopInstallArgs = "prevercheck /s /i \$splashtopUserInfo"
            \$splashtopProc = Start-Process -FilePath \$splashtopInstallerPath -ArgumentList \$splashtopInstallArgs -Wait -PassThru
            if (\$splashtopProc.ExitCode -ne 0) {
                throw ("Splashtop EXE auto-install failed with exit code {0}." -f \$splashtopProc.ExitCode)
            }
        } else {
            \$splashtopInstallArgs = "/i `"\$splashtopInstallerPath`" /qn /norestart USERINFO=`"\$splashtopUserInfo`""
            \$splashtopProc = Start-Process -FilePath "msiexec.exe" -ArgumentList \$splashtopInstallArgs -Wait -PassThru
            if (\$splashtopProc.ExitCode -ne 0) {
                throw ("Splashtop MSI auto-install failed with exit code {0}." -f \$splashtopProc.ExitCode)
            }
        }

        Write-Host "Splashtop auto-install: complete."
    } finally {
        Remove-Item -Path \$splashtopInstallerPath -Force -ErrorAction SilentlyContinue
    }
}

function Apply-RemovableStoragePolicy {
    if (-not \$DisableRemovableStorageOnInstall) {
        Write-Host "SOC2 hardening: removable storage policy skipped by configuration."
        return
    }

    try {
        \$policyPath = "HKLM:\\SOFTWARE\\Policies\\Microsoft\\Windows\\RemovableStorageDevices"
        New-Item -Path \$policyPath -Force | Out-Null
        New-ItemProperty -Path \$policyPath -Name "Deny_All" -PropertyType DWord -Value 1 -Force | Out-Null
        Write-Host "SOC2 hardening: removable storage access disabled (Deny_All=1)."
    } catch {
        Write-Warning ("SOC2 hardening: failed to apply removable storage policy: {0}" -f \$_.Exception.Message)
    }
}

function Ensure-WindowsDefender {
    if (-not \$EnsureDefenderOnInstall) {
        Write-Host "SOC2 hardening: Windows Defender enforcement skipped by configuration."
        return
    }

    \$defenderService = Get-Service -Name "WinDefend" -ErrorAction SilentlyContinue

    if (-not \$defenderService) {
        \$featureInstalled = \$false

        if (Get-Command Get-WindowsFeature -ErrorAction SilentlyContinue) {
            \$featureCandidates = @("Windows-Defender", "Windows-Defender-Features")
            foreach (\$featureName in \$featureCandidates) {
                try {
                    \$feature = Get-WindowsFeature -Name \$featureName -ErrorAction Stop
                    if (\$feature -and -not \$feature.Installed) {
                        Install-WindowsFeature -Name \$featureName -IncludeManagementTools -ErrorAction Stop | Out-Null
                    }
                    \$featureInstalled = \$true
                    break
                } catch {
                }
            }
        } elseif (Get-Command Add-WindowsFeature -ErrorAction SilentlyContinue) {
            \$featureCandidates = @("Windows-Defender", "Windows-Defender-Features")
            foreach (\$featureName in \$featureCandidates) {
                try {
                    Add-WindowsFeature -Name \$featureName -ErrorAction Stop | Out-Null
                    \$featureInstalled = \$true
                    break
                } catch {
                }
            }
        }

        if (\$featureInstalled) {
            Start-Sleep -Seconds 2
            \$defenderService = Get-Service -Name "WinDefend" -ErrorAction SilentlyContinue
        }
    }

    if (-not \$defenderService) {
        Write-Warning "SOC2 hardening: Windows Defender service (WinDefend) was not found after install attempts."
        return
    }

    try {
        Set-Service -Name "WinDefend" -StartupType Automatic -ErrorAction Stop
    } catch {
        Write-Warning ("SOC2 hardening: failed to set WinDefend startup type: {0}" -f \$_.Exception.Message)
    }

    try {
        if ((Get-Service -Name "WinDefend").Status -ne "Running") {
            Start-Service -Name "WinDefend" -ErrorAction Stop
        }
    } catch {
        Write-Warning ("SOC2 hardening: failed to start WinDefend: {0}" -f \$_.Exception.Message)
    }

    if (Get-Command Set-MpPreference -ErrorAction SilentlyContinue) {
        try {
            Set-MpPreference -DisableRealtimeMonitoring \$false -ErrorAction Stop
        } catch {
            Write-Warning ("SOC2 hardening: could not set Defender real-time monitoring preference: {0}" -f \$_.Exception.Message)
        }
    }

    Write-Host "SOC2 hardening: Windows Defender service verified."
}

function Ensure-ServiceAndStart {
    \$exePath = Join-Path \$InstallDir "PatchAgent.Service.exe"
    if (-not (Test-Path \$exePath)) {
        throw "Expected service binary not found: \$exePath"
    }

    sc.exe create \$ServiceName binPath= "`"\$exePath`"" start= auto | Out-Null
    sc.exe description \$ServiceName "WinPatchAgent endpoint service" | Out-Null
    Start-Service -Name \$ServiceName
}

New-Item -ItemType Directory -Path \$InstallDir -Force | Out-Null
New-Item -ItemType Directory -Path \$StateDir -Force | Out-Null

Stop-And-RemoveService
if (\$InstallMode -eq "source") {
    Install-FromSource
} else {
    Install-FromPrebuilt
}

Write-AgentConfig
Install-Splashtop
Apply-RemovableStoragePolicy
Ensure-WindowsDefender
Ensure-ServiceAndStart

Write-Host "Install complete."
Write-Host "Install mode: \$InstallMode"
Write-Host "Service: \$ServiceName"
Write-Host "Status:  Get-Service -Name \$ServiceName"
POWERSHELL;
    }

    private function buildMacOsInstallScript(string $baseUrl, string $enrollmentKey): string
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

for cmd in launchctl tar; do
  if ! command -v "\${cmd}" >/dev/null 2>&1; then
    echo "Missing required command: \${cmd}" >&2
    exit 1
  fi
done

if ! command -v curl >/dev/null 2>&1 && ! command -v wget >/dev/null 2>&1; then
  echo "Either curl or wget is required on macOS." >&2
  exit 1
fi

if ! command -v dotnet >/dev/null 2>&1; then
  for candidate in /opt/homebrew/bin/dotnet /usr/local/bin/dotnet /usr/local/share/dotnet/dotnet; do
    if [[ -x "\${candidate}" ]]; then
      export PATH="$(dirname "\${candidate}"):\${PATH}"
      break
    fi
  done
fi

if ! command -v dotnet >/dev/null 2>&1; then
  echo "dotnet SDK 8+ is required on macOS. Install it (for example via Homebrew), then rerun." >&2
  exit 1
fi

normalize_repo_url() {
  local raw="\$1"
  raw="\${raw%/}"
  if [[ "\${raw}" == git@github.com:* ]]; then
    raw="https://github.com/\${raw#git@github.com:}"
  fi
  raw="\${raw%.git}"
  printf '%s' "\${raw}"
}

build_archive_url() {
  local repo_http
  repo_http="$(normalize_repo_url "\$1")"
  printf '%s/archive/%s.tar.gz' "\${repo_http}" "\$2"
}

download_file() {
  local url="\$1"
  local output="\$2"
  if command -v wget >/dev/null 2>&1; then
    wget -qO "\${output}" "\${url}"
    return 0
  fi
  curl -fsSL "\${url}" -o "\${output}"
}

ARCHIVE_URL="$(build_archive_url "\${REPO_URL}" "\${REPO_REF}")"
TMP_ARCHIVE="$(mktemp /tmp/winpatchagent-src.XXXXXX.tar.gz)"
TMP_EXTRACT="$(mktemp -d /tmp/winpatchagent-src.XXXXXX)"

cleanup() {
  rm -f "\${TMP_ARCHIVE}" || true
  rm -rf "\${TMP_EXTRACT}" || true
}
trap cleanup EXIT

download_file "\${ARCHIVE_URL}" "\${TMP_ARCHIVE}"
tar -xzf "\${TMP_ARCHIVE}" -C "\${TMP_EXTRACT}"
SOURCE_DIR="$(find "\${TMP_EXTRACT}" -mindepth 1 -maxdepth 1 -type d | head -n 1)"
if [[ -z "\${SOURCE_DIR}" || ! -x "\${SOURCE_DIR}/scripts/setup_macos_agent.sh" ]]; then
  echo "Downloaded archive did not contain scripts/setup_macos_agent.sh" >&2
  exit 1
fi

rm -rf "\${WORK_DIR}"
mv "\${SOURCE_DIR}" "\${WORK_DIR}"

bash "\${WORK_DIR}/scripts/setup_macos_agent.sh" \\
  --backend-url "\${BACKEND_URL}" \\
  --enrollment-key "\${ENROLLMENT_KEY}"
BASH;
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

    private function buildMacInstallCommand(string $scriptUrl): string
    {
        return sprintf('curl -fsSL %s | sudo bash', escapeshellarg($scriptUrl));
    }

    private function normalizeWindowsUpdateInventory(array $windowsUpdate): array
    {
        $installedPatches = is_array($windowsUpdate['installed_patches'] ?? null)
            ? $windowsUpdate['installed_patches']
            : [];
        $availablePatches = is_array($windowsUpdate['available_patches'] ?? null)
            ? $windowsUpdate['available_patches']
            : [];

        $normalizedPatches = [];
        foreach ($installedPatches as $patch) {
            if (!is_array($patch)) {
                continue;
            }

            $kb = strtoupper(trim((string) ($patch['kb'] ?? $patch['hotfix_id'] ?? '')));
            if ($kb !== '' && !str_starts_with($kb, 'KB')) {
                $kb = 'KB' . $kb;
            }

            $normalizedPatches[] = [
                'kb' => $kb,
                'title' => trim((string) ($patch['title'] ?? $patch['description'] ?? '')),
                'installed_at' => trim((string) ($patch['installed_at'] ?? $patch['installed_on'] ?? '')),
            ];
        }

        usort($normalizedPatches, static function (array $left, array $right): int {
            return strcmp((string) ($right['installed_at'] ?? ''), (string) ($left['installed_at'] ?? ''));
        });

        $normalizedAvailable = [];
        foreach ($availablePatches as $patch) {
            if (!is_array($patch)) {
                continue;
            }

            $updateId = trim((string) ($patch['update_id'] ?? $patch['kb'] ?? $patch['id'] ?? ''));
            $title = trim((string) ($patch['title'] ?? $patch['description'] ?? ''));

            if ($updateId !== '') {
                $upper = strtoupper($updateId);
                if (preg_match('/^\d+$/', $upper) === 1) {
                    $upper = 'KB' . $upper;
                } elseif (preg_match('/^KB\d+$/', $upper) === 1) {
                    $upper = strtoupper($upper);
                }
                $updateId = $upper;
            }

            if ($updateId === '' && $title === '') {
                continue;
            }

            if ($updateId === '') {
                $updateId = $title;
            }

            $normalizedAvailable[] = [
                'update_id' => $updateId,
                'title' => $title,
            ];
        }

        $windowsUpdate['installed_patches'] = array_slice($normalizedPatches, 0, 300);
        $windowsUpdate['installed_patches_count'] = count($normalizedPatches);
        $windowsUpdate['available_patches'] = array_slice($normalizedAvailable, 0, 300);
        $windowsUpdate['available_patches_count'] = count($normalizedAvailable);
        return $windowsUpdate;
    }

    private function normalizeWindowsSecurityInventory(array $windowsSecurity): array
    {
        $edition = trim((string) (
            $windowsSecurity['edition']
            ?? $windowsSecurity['Edition']
            ?? ''
        ));

        $defenderServiceState = strtolower(trim((string) (
            $windowsSecurity['defender_service_state']
            ?? $windowsSecurity['defenderServiceState']
            ?? $windowsSecurity['DefenderServiceState']
            ?? ''
        )));
        if (!in_array($defenderServiceState, ['running', 'stopped', 'not_found'], true)) {
            $defenderServiceState = 'unknown';
        }

        $bitlockerSupport = strtolower(trim((string) (
            $windowsSecurity['bitlocker_support']
            ?? $windowsSecurity['bitlockerSupport']
            ?? $windowsSecurity['BitlockerSupport']
            ?? ''
        )));
        if (!in_array($bitlockerSupport, ['supported', 'not_supported'], true)) {
            $bitlockerSupport = 'unknown';
        }

        $bitlockerOsVolumeProtection = strtolower(trim((string) (
            $windowsSecurity['bitlocker_os_volume_protection']
            ?? $windowsSecurity['bitlockerOsVolumeProtection']
            ?? $windowsSecurity['BitlockerOsVolumeProtection']
            ?? ''
        )));
        if (!in_array($bitlockerOsVolumeProtection, ['on', 'off', 'suspended', 'not_supported'], true)) {
            $bitlockerOsVolumeProtection = 'unknown';
        }

        $defenderRealtimeEnabled = null;
        foreach (['defender_realtime_enabled', 'defenderRealtimeEnabled', 'DefenderRealtimeEnabled'] as $key) {
            if (!array_key_exists($key, $windowsSecurity)) {
                continue;
            }

            $value = $windowsSecurity[$key];
            if ($value === null) {
                $defenderRealtimeEnabled = null;
                break;
            }

            $defenderRealtimeEnabled = $this->toBool($value);
            break;
        }

        $firewallDomainEnabled = null;
        foreach (['firewall_domain_enabled', 'firewallDomainEnabled', 'FirewallDomainEnabled'] as $key) {
            if (!array_key_exists($key, $windowsSecurity)) {
                continue;
            }

            $value = $windowsSecurity[$key];
            if ($value === null) {
                $firewallDomainEnabled = null;
                break;
            }

            $firewallDomainEnabled = $this->toBool($value);
            break;
        }

        $firewallPrivateEnabled = null;
        foreach (['firewall_private_enabled', 'firewallPrivateEnabled', 'FirewallPrivateEnabled'] as $key) {
            if (!array_key_exists($key, $windowsSecurity)) {
                continue;
            }

            $value = $windowsSecurity[$key];
            if ($value === null) {
                $firewallPrivateEnabled = null;
                break;
            }

            $firewallPrivateEnabled = $this->toBool($value);
            break;
        }

        $firewallPublicEnabled = null;
        foreach (['firewall_public_enabled', 'firewallPublicEnabled', 'FirewallPublicEnabled'] as $key) {
            if (!array_key_exists($key, $windowsSecurity)) {
                continue;
            }

            $value = $windowsSecurity[$key];
            if ($value === null) {
                $firewallPublicEnabled = null;
                break;
            }

            $firewallPublicEnabled = $this->toBool($value);
            break;
        }

        return [
            'edition' => $edition,
            'defender_service_present' => $this->toBool(
                $windowsSecurity['defender_service_present']
                ?? $windowsSecurity['defenderServicePresent']
                ?? $windowsSecurity['DefenderServicePresent']
                ?? false
            ),
            'defender_service_state' => $defenderServiceState,
            'defender_realtime_enabled' => $defenderRealtimeEnabled,
            'firewall_domain_enabled' => $firewallDomainEnabled,
            'firewall_private_enabled' => $firewallPrivateEnabled,
            'firewall_public_enabled' => $firewallPublicEnabled,
            'removable_storage_deny_all' => $this->toBool(
                $windowsSecurity['removable_storage_deny_all']
                ?? $windowsSecurity['removableStorageDenyAll']
                ?? $windowsSecurity['RemovableStorageDenyAll']
                ?? false
            ),
            'bitlocker_support' => $bitlockerSupport,
            'bitlocker_os_volume_protection' => $bitlockerOsVolumeProtection,
        ];
    }

    private function normalizeLinuxInventory(array $linux): array
    {
        $distroId = trim((string) (
            $linux['distro_id']
            ?? $linux['distroId']
            ?? $linux['DistroId']
            ?? ''
        ));
        $distroVersionId = trim((string) (
            $linux['distro_version_id']
            ?? $linux['distroVersionId']
            ?? $linux['DistroVersionId']
            ?? ''
        ));
        $kernelVersion = trim((string) (
            $linux['kernel_version']
            ?? $linux['kernelVersion']
            ?? $linux['KernelVersion']
            ?? ''
        ));

        $availablePackagesSource = null;
        foreach (['available_packages', 'availablePackages', 'AvailablePackages', 'packages', 'Packages'] as $key) {
            if (is_array($linux[$key] ?? null)) {
                $availablePackagesSource = $linux[$key];
                break;
            }
        }

        $normalizedPackages = [];
        if (is_array($availablePackagesSource)) {
            foreach ($availablePackagesSource as $package) {
                if (!is_string($package)) {
                    continue;
                }

                $trimmed = trim($package);
                if ($trimmed === '') {
                    continue;
                }

                $normalizedPackages[$trimmed] = true;
            }
        }

        $packageDetailsSource = null;
        foreach (['available_package_details', 'availablePackageDetails', 'AvailablePackageDetails'] as $key) {
            if (is_array($linux[$key] ?? null)) {
                $packageDetailsSource = $linux[$key];
                break;
            }
        }

        $normalizedPackageDetails = [];
        $detailSeen = [];
        if (is_array($packageDetailsSource)) {
            foreach ($packageDetailsSource as $detail) {
                $normalizedDetail = $this->normalizeLinuxPackageDetailEntry($detail);
                if ($normalizedDetail === null) {
                    continue;
                }

                $dedupeKey = strtolower($normalizedDetail['name'])
                    . '|'
                    . strtolower((string) ($normalizedDetail['current_version'] ?? ''))
                    . '|'
                    . strtolower((string) ($normalizedDetail['candidate_version'] ?? ''));

                if (isset($detailSeen[$dedupeKey])) {
                    continue;
                }

                $detailSeen[$dedupeKey] = true;
                $normalizedPackageDetails[] = $normalizedDetail;
                $normalizedPackages[$normalizedDetail['name']] = true;
            }
        }

        $normalizedPackageList = array_values(array_keys($normalizedPackages));
        sort($normalizedPackageList, SORT_STRING);

        if (count($normalizedPackageDetails) === 0) {
            foreach ($normalizedPackageList as $packageName) {
                $normalizedPackageDetails[] = [
                    'name' => $packageName,
                    'current_version' => '',
                    'candidate_version' => '',
                    'architecture' => '',
                    'source' => '',
                    'raw_line' => '',
                ];
            }
        } else {
            usort($normalizedPackageDetails, static function (array $left, array $right): int {
                return strcmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
            });
        }

        $count = null;
        foreach ([
            'available_packages_count',
            'availablePackagesCount',
            'AvailablePackagesCount',
            'package_updates_count',
            'packageUpdatesCount',
            'PackageUpdatesCount',
        ] as $key) {
            if (!array_key_exists($key, $linux)) {
                continue;
            }

            $parsed = filter_var($linux[$key], FILTER_VALIDATE_INT);
            if ($parsed !== false && (int) $parsed >= 0) {
                $count = (int) $parsed;
                break;
            }
        }

        if ($count === null) {
            $count = count($normalizedPackageList);
        }

        $updatesAvailable = $this->toBool($linux['package_updates_available'] ?? false)
            || $this->toBool($linux['packageUpdatesAvailable'] ?? false)
            || $this->toBool($linux['PackageUpdatesAvailable'] ?? false)
            || $this->toBool($linux['updates_available'] ?? false)
            || $this->toBool($linux['updatesAvailable'] ?? false)
            || $count > 0;

        if ($count <= 0 && $updatesAvailable) {
            $count = count($normalizedPackageList) > 0 ? count($normalizedPackageList) : 1;
        }

        [$enrichedPackageDetails, $cveSummary] = $this->enrichLinuxPackageDetailsWithCves(
            $distroId,
            $distroVersionId,
            $normalizedPackageDetails
        );

        return [
            'distro_id' => $distroId,
            'distro_version_id' => $distroVersionId,
            'kernel_version' => $kernelVersion,
            'apt_available' => $this->toBool($linux['apt_available'] ?? false)
                || $this->toBool($linux['aptAvailable'] ?? false)
                || $this->toBool($linux['AptAvailable'] ?? false),
            'package_updates_available' => $updatesAvailable,
            'available_packages' => array_slice($normalizedPackageList, 0, 500),
            'available_packages_count' => max(0, $count),
            'available_package_details' => array_slice($enrichedPackageDetails, 0, 500),
            'cve_summary' => $cveSummary,
        ];
    }

    private function normalizeLinuxPackageDetailEntry(mixed $detail): ?array
    {
        if (is_string($detail)) {
            return $this->parseLinuxPackageDetailLine($detail);
        }

        if (!is_array($detail)) {
            return null;
        }

        $name = trim((string) (
            $detail['name']
            ?? $detail['package']
            ?? $detail['package_name']
            ?? $detail['packageName']
            ?? $detail['id']
            ?? ''
        ));
        if ($name === '') {
            return null;
        }

        $currentVersion = trim((string) (
            $detail['current_version']
            ?? $detail['currentVersion']
            ?? $detail['installed_version']
            ?? $detail['installedVersion']
            ?? $detail['installed']
            ?? ''
        ));
        $candidateVersion = trim((string) (
            $detail['candidate_version']
            ?? $detail['candidateVersion']
            ?? $detail['new_version']
            ?? $detail['newVersion']
            ?? $detail['target_version']
            ?? $detail['targetVersion']
            ?? ''
        ));
        $architecture = trim((string) (
            $detail['architecture']
            ?? $detail['arch']
            ?? ''
        ));
        $source = trim((string) (
            $detail['source']
            ?? $detail['repository']
            ?? $detail['repo']
            ?? ''
        ));
        $rawLine = trim((string) (
            $detail['raw_line']
            ?? $detail['rawLine']
            ?? ''
        ));

        if ($rawLine === '') {
            $rawLine = trim(
                $name
                . ($source !== '' ? '/' . $source : '')
                . ($candidateVersion !== '' ? ' ' . $candidateVersion : '')
                . ($architecture !== '' ? ' ' . $architecture : '')
                . ($currentVersion !== '' ? ' [upgradable from: ' . $currentVersion . ']' : '')
            );
        }

        return [
            'name' => $name,
            'current_version' => $currentVersion,
            'candidate_version' => $candidateVersion,
            'architecture' => $architecture,
            'source' => $source,
            'raw_line' => $rawLine,
        ];
    }

    private function parseLinuxPackageDetailLine(string $rawLine): ?array
    {
        $line = trim($rawLine);
        if ($line === '') {
            return null;
        }

        $firstSpaceIndex = strpos($line, ' ');
        if ($firstSpaceIndex === false || $firstSpaceIndex <= 0) {
            return null;
        }

        $packageToken = trim(substr($line, 0, $firstSpaceIndex));
        $remainder = trim(substr($line, $firstSpaceIndex + 1));
        if ($packageToken === '' || $remainder === '') {
            return null;
        }

        $slashIndex = strpos($packageToken, '/');
        $name = trim($slashIndex !== false ? substr($packageToken, 0, $slashIndex) : $packageToken);
        if ($name === '') {
            return null;
        }

        $source = trim($slashIndex !== false ? substr($packageToken, $slashIndex + 1) : '');

        $candidateVersion = '';
        $architecture = '';
        $tokens = preg_split('/\s+/', $remainder);
        if (is_array($tokens) && count($tokens) > 0) {
            $candidateVersion = trim((string) ($tokens[0] ?? ''));
            $architecture = trim((string) ($tokens[1] ?? ''));
        }

        $currentVersion = '';
        if (preg_match('/\[\s*upgradable from:\s*([^\]]+)\]/i', $line, $matches) === 1) {
            $currentVersion = trim((string) ($matches[1] ?? ''));
        }

        return [
            'name' => $name,
            'current_version' => $currentVersion,
            'candidate_version' => $candidateVersion,
            'architecture' => $architecture,
            'source' => $source,
            'raw_line' => $line,
        ];
    }

    private function enrichLinuxPackageDetailsWithCves(
        string $distroId,
        string $distroVersionId,
        array $packageDetails
    ): array {
        $summary = [
            'enabled' => $this->config->linuxCveLookupEnabled,
            'supported' => false,
            'ecosystem' => '',
            'distro_id' => $distroId,
            'distro_version_id' => $distroVersionId,
            'checked_packages' => 0,
            'packages_with_cves' => 0,
            'total_cves' => 0,
            'cached_entries' => 0,
            'queried_entries' => 0,
            'truncated' => false,
            'generated_at' => gmdate(DATE_ATOM),
        ];

        if (!$this->config->linuxCveLookupEnabled) {
            return [$packageDetails, $summary];
        }

        $ecosystem = $this->mapLinuxDistroToOsvEcosystem($distroId);
        if ($ecosystem === null) {
            $summary['reason'] = 'unsupported_distro';
            return [$packageDetails, $summary];
        }

        $summary['supported'] = true;
        $summary['ecosystem'] = $ecosystem;

        $cacheTtlSeconds = max(300, $this->config->linuxCveCacheTtlSeconds);
        $maxPackageLookups = max(1, $this->config->linuxCveMaxPackageLookups);
        $lookupResults = [];
        $lookupTargets = [];

        foreach ($packageDetails as $detail) {
            if (!is_array($detail)) {
                continue;
            }

            $name = trim((string) ($detail['name'] ?? ''));
            $currentVersion = trim((string) ($detail['current_version'] ?? ''));
            if ($name === '' || $currentVersion === '') {
                continue;
            }

            $lookupKey = $this->linuxCveCacheKey($ecosystem, $name, $currentVersion);
            if (isset($lookupResults[$lookupKey]) || isset($lookupTargets[$lookupKey])) {
                continue;
            }

            $cachedEntry = $this->loadLinuxCveCacheEntry($lookupKey);
            if ($cachedEntry !== null && $this->isLinuxCveCacheFresh($cachedEntry, $cacheTtlSeconds)) {
                $lookupResults[$lookupKey] = $cachedEntry;
                $summary['cached_entries'] = (int) $summary['cached_entries'] + 1;
                continue;
            }

            if (count($lookupTargets) < $maxPackageLookups) {
                $lookupTargets[$lookupKey] = [
                    'name' => $name,
                    'version' => $currentVersion,
                ];
            } else {
                $summary['truncated'] = true;
            }
        }

        if (count($lookupTargets) > 0) {
            $queryKeys = array_keys($lookupTargets);
            $queries = [];
            foreach ($lookupTargets as $target) {
                $queries[] = [
                    'package' => [
                        'name' => (string) ($target['name'] ?? ''),
                        'ecosystem' => $ecosystem,
                    ],
                    'version' => (string) ($target['version'] ?? ''),
                ];
            }

            try {
                $results = $this->queryOsvBatch($queries);
                $summary['queried_entries'] = count($queries);
                foreach ($queryKeys as $index => $lookupKey) {
                    $result = is_array($results[$index] ?? null) ? $results[$index] : [];
                    $vulnerabilitiesRaw = is_array($result['vulns'] ?? null) ? $result['vulns'] : [];
                    $normalizedVulnerabilities = $this->normalizeOsvVulnerabilities(
                        $vulnerabilitiesRaw,
                        $this->config->linuxCveMaxVulnsPerPackage
                    );

                    $entry = [
                        'fetched_at' => gmdate(DATE_ATOM),
                        'vulnerability_count' => count($vulnerabilitiesRaw),
                        'vulnerabilities' => $normalizedVulnerabilities,
                    ];
                    $lookupResults[$lookupKey] = $entry;
                    $this->saveLinuxCveCacheEntry($lookupKey, $entry);
                }
            } catch (\Throwable $exception) {
                $summary['lookup_error'] = $exception->getMessage();
            }
        }

        $enriched = [];
        foreach ($packageDetails as $detail) {
            if (!is_array($detail)) {
                continue;
            }

            $name = trim((string) ($detail['name'] ?? ''));
            $currentVersion = trim((string) ($detail['current_version'] ?? ''));
            $detail['vulnerability_count'] = null;
            $detail['vulnerabilities'] = [];
            $detail['cve_ids'] = [];
            $detail['cve_lookup_state'] = $currentVersion === '' ? 'missing_version' : 'skipped';

            if ($name !== '' && $currentVersion !== '') {
                $lookupKey = $this->linuxCveCacheKey($ecosystem, $name, $currentVersion);
                if (is_array($lookupResults[$lookupKey] ?? null)) {
                    $entry = $lookupResults[$lookupKey];
                    $vulnerabilities = is_array($entry['vulnerabilities'] ?? null)
                        ? $entry['vulnerabilities']
                        : [];
                    $count = filter_var($entry['vulnerability_count'] ?? null, FILTER_VALIDATE_INT);
                    $count = $count === false ? count($vulnerabilities) : max(0, (int) $count);

                    $detail['vulnerability_count'] = $count;
                    $detail['vulnerabilities'] = $vulnerabilities;
                    $detail['cve_ids'] = array_values(array_filter(array_map(
                        static fn (mixed $item): string => is_array($item) ? trim((string) ($item['id'] ?? '')) : '',
                        $vulnerabilities
                    )));
                    $detail['cve_lookup_state'] = 'ok';

                    $summary['checked_packages'] = (int) $summary['checked_packages'] + 1;
                    if ($count > 0) {
                        $summary['packages_with_cves'] = (int) $summary['packages_with_cves'] + 1;
                        $summary['total_cves'] = (int) $summary['total_cves'] + $count;
                    }
                }
            }

            $enriched[] = $detail;
        }

        return [$enriched, $summary];
    }

    private function mapLinuxDistroToOsvEcosystem(string $distroId): ?string
    {
        return match (strtolower(trim($distroId))) {
            'ubuntu' => 'Ubuntu',
            'debian', 'raspbian' => 'Debian',
            default => null,
        };
    }

    private function linuxCveCacheKey(string $ecosystem, string $name, string $version): string
    {
        return hash('sha256', strtolower($ecosystem) . "\n" . strtolower($name) . "\n" . $version);
    }

    private function linuxCveCachePath(string $lookupKey): string
    {
        return sprintf('cve/osv/%s.json', $lookupKey);
    }

    private function loadLinuxCveCacheEntry(string $lookupKey): ?array
    {
        $path = $this->linuxCveCachePath($lookupKey);
        if (!$this->store->exists($path)) {
            return null;
        }

        $entry = $this->store->readJson($path, []);
        return is_array($entry) ? $entry : null;
    }

    private function saveLinuxCveCacheEntry(string $lookupKey, array $entry): void
    {
        try {
            $this->store->writeJson($this->linuxCveCachePath($lookupKey), $entry);
        } catch (\Throwable) {
            // Non-fatal: cache write failures should not break inventory rendering.
        }
    }

    private function isLinuxCveCacheFresh(array $entry, int $ttlSeconds): bool
    {
        $fetchedAt = strtotime((string) ($entry['fetched_at'] ?? ''));
        if ($fetchedAt === false || $fetchedAt <= 0) {
            return false;
        }

        return ($fetchedAt + max(60, $ttlSeconds)) >= time();
    }

    private function queryOsvBatch(array $queries): array
    {
        if (count($queries) === 0) {
            return [];
        }

        $payload = Json::encode([
            'queries' => array_values($queries),
        ]);

        $response = $this->requestRaw(
            'POST',
            self::OSV_QUERY_BATCH_URL,
            $payload,
            ['Content-Type: application/json']
        );

        if (($response['status'] ?? 0) < 200 || ($response['status'] ?? 0) >= 300) {
            throw new RuntimeException(sprintf(
                'OSV lookup failed with HTTP status %d.',
                (int) ($response['status'] ?? 0)
            ));
        }

        $decoded = json_decode((string) ($response['body'] ?? ''), true);
        if (!is_array($decoded)) {
            throw new RuntimeException('OSV lookup returned an invalid JSON payload.');
        }

        $results = $decoded['results'] ?? [];
        return is_array($results) ? $results : [];
    }

    private function normalizeOsvVulnerabilities(array $vulnerabilities, int $maxItems): array
    {
        $normalized = [];
        foreach ($vulnerabilities as $vulnerability) {
            if (!is_array($vulnerability)) {
                continue;
            }

            $id = trim((string) ($vulnerability['id'] ?? ''));
            $aliases = [];
            foreach ((array) ($vulnerability['aliases'] ?? []) as $alias) {
                if (!is_string($alias)) {
                    continue;
                }
                $trimmedAlias = trim($alias);
                if ($trimmedAlias !== '') {
                    $aliases[$trimmedAlias] = true;
                }
            }

            if ($id === '' && count($aliases) === 0) {
                continue;
            }

            $primaryId = $id !== '' ? $id : (array_key_first($aliases) ?? '');
            if ($primaryId === '') {
                continue;
            }

            $normalized[] = [
                'id' => $primaryId,
                'summary' => trim((string) ($vulnerability['summary'] ?? '')),
                'severity' => $this->extractOsvSeveritySummary($vulnerability),
                'aliases' => array_slice(array_values(array_keys($aliases)), 0, 10),
            ];
        }

        usort($normalized, static function (array $left, array $right): int {
            return strcmp((string) ($left['id'] ?? ''), (string) ($right['id'] ?? ''));
        });

        return array_slice($normalized, 0, max(1, $maxItems));
    }

    private function extractOsvSeveritySummary(array $vulnerability): string
    {
        $severityEntries = is_array($vulnerability['severity'] ?? null)
            ? $vulnerability['severity']
            : [];
        foreach ($severityEntries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $score = trim((string) ($entry['score'] ?? ''));
            if ($score !== '') {
                return $score;
            }
        }

        $databaseSpecific = is_array($vulnerability['database_specific'] ?? null)
            ? $vulnerability['database_specific']
            : [];
        $legacy = trim((string) ($databaseSpecific['severity'] ?? ''));
        return $legacy;
    }

    private function normalizeMacOsInventory(array $macOs): array
    {
        $labelsSource = null;
        foreach (['available_update_labels', 'availableUpdateLabels', 'AvailableUpdateLabels', 'labels', 'Labels'] as $key) {
            if (is_array($macOs[$key] ?? null)) {
                $labelsSource = $macOs[$key];
                break;
            }
        }

        $normalizedLabels = [];
        if (is_array($labelsSource)) {
            foreach ($labelsSource as $label) {
                if (!is_string($label)) {
                    continue;
                }

                $trimmed = trim($label);
                if ($trimmed === '') {
                    continue;
                }

                $normalizedLabels[$trimmed] = true;
            }
        }

        $labels = array_values(array_keys($normalizedLabels));
        sort($labels, SORT_STRING);

        $count = null;
        foreach (['available_updates_count', 'availableUpdatesCount', 'AvailableUpdatesCount'] as $key) {
            if (!array_key_exists($key, $macOs)) {
                continue;
            }

            $parsed = filter_var($macOs[$key], FILTER_VALIDATE_INT);
            if ($parsed !== false && (int) $parsed >= 0) {
                $count = (int) $parsed;
                break;
            }
        }

        if ($count === null) {
            $count = count($labels);
        }

        $updateAvailable = $this->toBool($macOs['software_update_available'] ?? false)
            || $this->toBool($macOs['softwareUpdateAvailable'] ?? false)
            || $this->toBool($macOs['SoftwareUpdateAvailable'] ?? false)
            || $count > 0;

        if ($count <= 0 && $updateAvailable) {
            $count = count($labels) > 0 ? count($labels) : 1;
        }

        return [
            'product_version' => trim((string) (
                $macOs['product_version']
                ?? $macOs['productVersion']
                ?? $macOs['ProductVersion']
                ?? ''
            )),
            'build_version' => trim((string) (
                $macOs['build_version']
                ?? $macOs['buildVersion']
                ?? $macOs['BuildVersion']
                ?? ''
            )),
            'software_update_available' => $updateAvailable,
            'available_update_labels' => array_slice($labels, 0, 500),
            'available_updates_count' => max(0, $count),
        ];
    }

    private function normalizeSoftwareInstallPayload(array $payload): array
    {
        $software = is_array($payload['software_install'] ?? null)
            ? $payload['software_install']
            : (is_array($payload['software'] ?? null) ? $payload['software'] : []);

        $rawPackages = $software['packages'] ?? ($software['ids'] ?? ($software['package_ids'] ?? []));
        $packageCandidates = [];
        if (is_string($rawPackages)) {
            $packageCandidates = preg_split('/[\s,]+/', $rawPackages) ?: [];
        } elseif (is_array($rawPackages)) {
            $packageCandidates = $rawPackages;
        }

        $normalizedPackages = [];
        foreach ($packageCandidates as $package) {
            if (!is_string($package)) {
                continue;
            }

            $value = trim($package);
            if ($value === '') {
                continue;
            }

            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:+@\/ -]{0,127}$/', $value) !== 1) {
                continue;
            }

            $normalizedPackages[strtolower($value)] = $value;
        }

        if (count($normalizedPackages) === 0) {
            throw new ApiException(
                422,
                'invalid_request',
                'software_install requires at least one valid package name or ID in software_install.packages.'
            );
        }

        $manager = strtolower(trim((string) ($software['manager'] ?? 'auto')));
        if ($manager === '') {
            $manager = 'auto';
        }

        if (!in_array($manager, ['auto', 'winget', 'apt', 'brew'], true)) {
            throw new ApiException(
                422,
                'invalid_request',
                'software_install.manager must be one of: auto, winget, apt, brew.'
            );
        }

        return [
            'software_install' => [
                'manager' => $manager,
                'allow_update' => $this->toBool(
                    $software['allow_update'] ?? ($software['allow_upgrade'] ?? false)
                ),
                'packages' => array_values($normalizedPackages),
            ],
        ];
    }

    private function normalizeSoftwareSearchPayload(array $payload): array
    {
        $search = is_array($payload['software_search'] ?? null)
            ? $payload['software_search']
            : (
                is_array($payload['search'] ?? null)
                    ? $payload['search']
                    : (is_array($payload['software'] ?? null) ? $payload['software'] : [])
            );

        $query = trim((string) ($search['query'] ?? ($search['search'] ?? ($search['term'] ?? ''))));
        if ($query === '' || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:+@\/ -]{0,127}$/', $query) !== 1) {
            throw new ApiException(
                422,
                'invalid_request',
                'software_search requires a valid query in software_search.query.'
            );
        }

        $manager = strtolower(trim((string) ($search['manager'] ?? 'auto')));
        if ($manager === '') {
            $manager = 'auto';
        }

        if (!in_array($manager, ['auto', 'winget', 'apt', 'brew'], true)) {
            throw new ApiException(
                422,
                'invalid_request',
                'software_search.manager must be one of: auto, winget, apt, brew.'
            );
        }

        $limit = (int) ($search['limit'] ?? ($search['max_results'] ?? 25));
        if ($limit < 1) {
            $limit = 1;
        } elseif ($limit > 100) {
            $limit = 100;
        }

        return [
            'software_search' => [
                'manager' => $manager,
                'query' => $query,
                'limit' => $limit,
            ],
        ];
    }

    private function normalizeApplicationsInventory(array $applications): array
    {
        $normalized = [];

        foreach ($applications as $application) {
            $entry = $this->normalizeApplicationInventoryEntry($application);
            if ($entry === null) {
                continue;
            }

            $key = strtolower(
                trim((string) $entry['name'])
                . '|'
                . trim((string) ($entry['version'] ?? ''))
                . '|'
                . trim((string) ($entry['source'] ?? ''))
            );
            $normalized[$key] = $entry;
        }

        $rows = array_values($normalized);
        usort($rows, static function (array $left, array $right): int {
            $leftName = strtolower((string) ($left['name'] ?? ''));
            $rightName = strtolower((string) ($right['name'] ?? ''));
            if ($leftName === $rightName) {
                return strcmp((string) ($left['version'] ?? ''), (string) ($right['version'] ?? ''));
            }

            return strcmp($leftName, $rightName);
        });

        return array_slice($rows, 0, 3000);
    }

    private function normalizeApplicationInventoryEntry(mixed $application): ?array
    {
        if (!is_array($application)) {
            return null;
        }

        $name = trim((string) (
            $application['name']
            ?? $application['display_name']
            ?? $application['displayName']
            ?? ''
        ));
        if ($name === '') {
            return null;
        }

        return [
            'name' => $name,
            'version' => trim((string) (
                $application['version']
                ?? $application['display_version']
                ?? $application['displayVersion']
                ?? ''
            )),
            'publisher' => trim((string) (
                $application['publisher']
                ?? $application['vendor']
                ?? ''
            )),
            'source' => trim((string) (
                $application['source']
                ?? $application['channel']
                ?? ''
            )),
            'installed_at' => trim((string) (
                $application['installed_at']
                ?? $application['install_date']
                ?? $application['installDate']
                ?? ''
            )),
        ];
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
        $token = $request->bearerToken();
        if (
            $token !== null
            && $token !== ''
            && $this->config->adminKey !== ''
            && hash_equals($this->config->adminKey, $token)
        ) {
            return;
        }

        if ($this->isAdminSessionAuthenticated()) {
            return;
        }

        if ($token !== null && $token !== '' && $this->config->adminKey !== '') {
            throw new ApiException(403, 'invalid_admin_token', 'The admin bearer token is invalid.');
        }

        if ($this->isGoogleOAuthEnabled()) {
            throw new ApiException(401, 'admin_auth_required', 'Admin login is required.');
        }

        if ($this->config->adminKey !== '') {
            throw new ApiException(401, 'missing_admin_token', 'The admin bearer token is required.');
        }
    }

    private function isGoogleOAuthEnabled(): bool
    {
        return $this->config->googleClientId !== ''
            && $this->config->googleClientSecret !== '';
    }

    private function googleRedirectUri(): string
    {
        if ($this->config->googleRedirectUri !== '') {
            return $this->config->googleRedirectUri;
        }

        return $this->baseUrlFromServer() . '/v1/admin/auth/google/callback';
    }

    private function baseUrlFromServer(): string
    {
        $forwardedProto = (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
        $scheme = strtolower(trim(explode(',', $forwardedProto)[0]));
        if ($scheme !== 'http' && $scheme !== 'https') {
            $scheme = $this->isHttpsRequest() ? 'https' : 'http';
        }

        $forwardedHost = (string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? '');
        $host = trim(explode(',', $forwardedHost)[0]);
        if ($host === '') {
            $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
        }
        if ($host === '') {
            $host = 'localhost';
        }

        return sprintf('%s://%s', $scheme, $host);
    }

    private function startAdminSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $sessionName = preg_replace('/[^a-zA-Z0-9_-]/', '', $this->config->adminSessionName);
        if (!is_string($sessionName) || $sessionName === '') {
            $sessionName = 'patchagent_admin';
        }

        session_name($sessionName);
        session_set_cookie_params([
            'lifetime' => max(300, $this->config->adminSessionTtlSeconds),
            'path' => '/',
            'secure' => $this->isHttpsRequest(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        session_start();
    }

    private function isHttpsRequest(): bool
    {
        $https = strtolower((string) ($_SERVER['HTTPS'] ?? 'off'));
        if ($https !== '' && $https !== 'off' && $https !== '0') {
            return true;
        }

        $forwardedProto = (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
        $scheme = strtolower(trim(explode(',', $forwardedProto)[0]));
        return $scheme === 'https';
    }

    private function currentAdminUser(): ?array
    {
        $this->startAdminSession();

        $user = $_SESSION[self::ADMIN_SESSION_USER_KEY] ?? null;
        if (!is_array($user)) {
            return null;
        }

        $email = strtolower(trim((string) ($user['email'] ?? '')));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            unset($_SESSION[self::ADMIN_SESSION_USER_KEY]);
            return null;
        }

        $expiresAt = (int) ($user['expires_at'] ?? 0);
        if ($expiresAt > 0 && $expiresAt <= time()) {
            unset($_SESSION[self::ADMIN_SESSION_USER_KEY]);
            return null;
        }

        return [
            'email' => $email,
            'name' => trim((string) ($user['name'] ?? '')),
            'picture' => trim((string) ($user['picture'] ?? '')),
            'sub' => trim((string) ($user['sub'] ?? '')),
            'hd' => trim((string) ($user['hd'] ?? '')),
            'authenticated_at' => trim((string) ($user['authenticated_at'] ?? '')),
            'expires_at' => $expiresAt,
        ];
    }

    private function isAdminSessionAuthenticated(): bool
    {
        return $this->currentAdminUser() !== null;
    }

    private function exchangeGoogleAuthorizationCode(string $code): array
    {
        $payload = http_build_query([
            'code' => $code,
            'client_id' => $this->config->googleClientId,
            'client_secret' => $this->config->googleClientSecret,
            'redirect_uri' => $this->googleRedirectUri(),
            'grant_type' => 'authorization_code',
        ], '', '&', PHP_QUERY_RFC3986);

        return $this->requestJsonFromUrl(
            'POST',
            self::GOOGLE_TOKEN_URL,
            $payload,
            ['Content-Type: application/x-www-form-urlencoded']
        );
    }

    private function validateGoogleIdToken(string $idToken, string $expectedNonce): array
    {
        $tokenInfo = $this->requestJsonFromUrl(
            'GET',
            self::GOOGLE_TOKENINFO_URL . '?id_token=' . rawurlencode($idToken),
            null,
            []
        );

        $jwtPayload = $this->decodeJwtPayload($idToken);

        $aud = trim((string) ($tokenInfo['aud'] ?? ''));
        if ($aud === '' || !hash_equals($this->config->googleClientId, $aud)) {
            throw new RuntimeException('Google token audience mismatch.');
        }

        $issuer = trim((string) ($tokenInfo['iss'] ?? ''));
        if ($issuer !== 'accounts.google.com' && $issuer !== 'https://accounts.google.com') {
            throw new RuntimeException('Google token issuer is invalid.');
        }

        $expiresAt = (int) ($tokenInfo['exp'] ?? 0);
        if ($expiresAt <= time()) {
            throw new RuntimeException('Google token is expired.');
        }

        $email = strtolower(trim((string) ($tokenInfo['email'] ?? '')));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Google token is missing a valid email.');
        }

        $emailVerified = strtolower(trim((string) ($tokenInfo['email_verified'] ?? 'false')));
        if ($emailVerified !== 'true' && $emailVerified !== '1') {
            throw new RuntimeException('Google account email is not verified.');
        }

        $nonce = trim((string) ($jwtPayload['nonce'] ?? ''));
        if ($expectedNonce !== '' && ($nonce === '' || !hash_equals($expectedNonce, $nonce))) {
            throw new RuntimeException('Google token nonce mismatch.');
        }

        $hostedDomain = strtolower(trim($this->config->googleHostedDomain));
        $emailDomain = strtolower((string) substr(strrchr($email, '@') ?: '', 1));
        $hdClaim = strtolower(trim((string) ($tokenInfo['hd'] ?? ($jwtPayload['hd'] ?? ''))));
        if ($hostedDomain !== '' && $emailDomain !== $hostedDomain && $hdClaim !== $hostedDomain) {
            throw new RuntimeException('Google account is outside the allowed hosted domain.');
        }

        return [
            'email' => $email,
            'name' => trim((string) ($jwtPayload['name'] ?? '')),
            'picture' => trim((string) ($jwtPayload['picture'] ?? '')),
            'sub' => trim((string) ($tokenInfo['sub'] ?? ($jwtPayload['sub'] ?? ''))),
            'hd' => $hdClaim,
        ];
    }

    private function decodeJwtPayload(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) < 2) {
            throw new RuntimeException('Invalid id_token format.');
        }

        $payload = strtr($parts[1], '-_', '+/');
        $padding = strlen($payload) % 4;
        if ($padding > 0) {
            $payload .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($payload, true);
        if ($decoded === false || $decoded === '') {
            throw new RuntimeException('Could not decode id_token payload.');
        }

        $parsed = json_decode($decoded, true);
        if (!is_array($parsed)) {
            throw new RuntimeException('id_token payload is not JSON.');
        }

        return $parsed;
    }

    private function requestJsonFromUrl(string $method, string $url, ?string $body, array $headers): array
    {
        $result = $this->requestRaw($method, $url, $body, $headers);
        $rawBody = trim($result['body']);

        $decoded = $rawBody === '' ? [] : json_decode($rawBody, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Remote endpoint returned a non-JSON response.');
        }

        if ($result['status'] < 200 || $result['status'] >= 300) {
            $errorDescription = trim((string) ($decoded['error_description'] ?? ''));
            $error = trim((string) ($decoded['error'] ?? ''));
            $message = $errorDescription !== '' ? $errorDescription : ($error !== '' ? $error : 'Request failed.');
            throw new RuntimeException($message);
        }

        return $decoded;
    }

    private function requestRaw(string $method, string $url, ?string $body, array $headers): array
    {
        if (function_exists('curl_init')) {
            $curl = curl_init($url);
            if ($curl === false) {
                throw new RuntimeException('Could not initialize HTTP client.');
            }

            $requestHeaders = $headers;
            if ($body !== null && $body !== '') {
                $requestHeaders[] = 'Content-Length: ' . strlen($body);
            }

            curl_setopt_array($curl, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HTTPHEADER => $requestHeaders,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);

            if ($body !== null && $body !== '') {
                curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
            }

            $responseBody = curl_exec($curl);
            if ($responseBody === false) {
                $error = curl_error($curl);
                curl_close($curl);
                throw new RuntimeException('HTTP request failed: ' . $error);
            }

            $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);

            return [
                'status' => $statusCode,
                'body' => (string) $responseBody,
            ];
        }

        $contextHeaders = $headers;
        if ($body !== null && $body !== '') {
            $contextHeaders[] = 'Content-Length: ' . strlen($body);
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $contextHeaders),
                'content' => $body ?? '',
                'ignore_errors' => true,
                'timeout' => 20,
            ],
        ]);

        $stream = @fopen($url, 'rb', false, $context);
        if ($stream === false) {
            throw new RuntimeException('HTTP request failed and returned no response body.');
        }

        $meta = stream_get_meta_data($stream);
        $response = stream_get_contents($stream);
        fclose($stream);

        if ($response === false) {
            throw new RuntimeException('HTTP request failed while reading response body.');
        }

        $statusCode = 0;
        $responseHeaders = is_array($meta['wrapper_data'] ?? null) ? $meta['wrapper_data'] : [];

        if (isset($responseHeaders[0]) && preg_match('#\s(\d{3})\s#', (string) $responseHeaders[0], $matches) === 1) {
            $statusCode = (int) $matches[1];
        }

        return [
            'status' => $statusCode,
            'body' => (string) $response,
        ];
    }

    private function redirect(string $location): void
    {
        http_response_code(302);
        header('Location: ' . $location);
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

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return ((int) $value) !== 0;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
        }

        return false;
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
