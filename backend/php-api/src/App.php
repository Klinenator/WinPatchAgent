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
    private const OAUTH_SESSION_STATE_KEY = 'google_oauth_state';
    private const OAUTH_SESSION_NONCE_KEY = 'google_oauth_nonce';
    private const OAUTH_SESSION_STARTED_AT_KEY = 'google_oauth_started_at';
    private const ADMIN_SESSION_USER_KEY = 'admin_user';

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

            if ($method === 'GET' && ($path === '/admin/login' || $path === '/admin/login/')) {
                $this->handleAdminLoginView();
                return;
            }

            if ($method === 'GET' && $path === '/v1/admin/auth/status') {
                $this->handleAdminAuthStatus();
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

            if ($method === 'GET' && $path === '/v1/admin/agents') {
                $this->requireAdmin($request);
                $this->handleListAgents();
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
            'linux' => is_array($body['linux'] ?? null) ? $body['linux'] : [],
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
        $platformRaw = strtolower($this->requireString($body, 'platform'));
        $platform = $platformRaw === 'macos' ? 'mac' : $platformRaw;

        if (!in_array($platform, ['linux', 'windows', 'mac'], true)) {
            throw new ApiException(422, 'invalid_request', 'Field "platform" must be "linux", "windows", or "mac".');
        }

        $ttlSeconds = $this->readEnrollmentTtlSeconds($body);
        $enrollment = $this->enrollments->createEnrollment($platform, $ttlSeconds);

        $scriptPath = match ($platform) {
            'windows' => '/install/windows.ps1',
            'mac' => '/install/macos.sh',
            default => '/install/linux.sh',
        };
        $scriptUrl = sprintf(
            '%s%s?enrollment_key=%s',
            $request->baseUrl(),
            $scriptPath,
            rawurlencode((string) $enrollment['enrollment_key'])
        );

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
        $agents = $this->agents->listAgents();
        foreach ($agents as $index => $agent) {
            $inventory = $this->inventory->loadSnapshot((string) ($agent['agent_record_id'] ?? ''));
            if ($inventory !== null) {
                $inventory['windows_update'] = $this->normalizeWindowsUpdateInventory(
                    is_array($inventory['windows_update'] ?? null) ? $inventory['windows_update'] : []
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

        JsonResponse::ok([
            'agent_record_id' => $agentRecordId,
            'inventory' => $inventory,
        ]);
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

    private function handleAdminProtectedView(string $filename, string $missingMessage): void
    {
        if ($this->isGoogleOAuthEnabled() && !$this->isAdminSessionAuthenticated()) {
            $this->redirect('/admin/login');
            return;
        }

        $this->servePublicHtmlFile($filename, $missingMessage);
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

        $this->servePublicHtmlFile('admin-login.html', 'Admin login page is missing.');
    }

    private function handleAdminAuthStatus(): void
    {
        $user = $this->currentAdminUser();

        JsonResponse::ok([
            'oauth_enabled' => $this->isGoogleOAuthEnabled(),
            'logged_in' => $user !== null,
            'user' => $user,
            'login_url' => '/v1/admin/auth/google/start',
            'logout_url' => '/v1/admin/auth/logout',
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
                $_SESSION[self::OAUTH_SESSION_STARTED_AT_KEY]
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
                $_SESSION[self::OAUTH_SESSION_STARTED_AT_KEY]
            );
            $this->redirect('/admin/login?error=oauth_failed&reason=' . rawurlencode($exception->getMessage()));
            return;
        }

        unset(
            $_SESSION[self::OAUTH_SESSION_STATE_KEY],
            $_SESSION[self::OAUTH_SESSION_NONCE_KEY],
            $_SESSION[self::OAUTH_SESSION_STARTED_AT_KEY]
        );

        $sessionTtl = max(300, $this->config->adminSessionTtlSeconds);
        $_SESSION[self::ADMIN_SESSION_USER_KEY] = [
            'email' => $claims['email'],
            'name' => $claims['name'],
            'picture' => $claims['picture'],
            'sub' => $claims['sub'],
            'hd' => $claims['hd'],
            'authenticated_at' => gmdate(DATE_ATOM),
            'expires_at' => time() + $sessionTtl,
        ];
        session_regenerate_id(true);

        $this->redirect('/admin');
    }

    private function handleAdminLogout(): void
    {
        $this->startAdminSession();

        unset(
            $_SESSION[self::ADMIN_SESSION_USER_KEY],
            $_SESSION[self::OAUTH_SESSION_STATE_KEY],
            $_SESSION[self::OAUTH_SESSION_NONCE_KEY],
            $_SESSION[self::OAUTH_SESSION_STARTED_AT_KEY]
        );
        session_regenerate_id(true);

        JsonResponse::ok([
            'logged_out' => true,
        ]);
    }

    private function servePublicHtmlFile(string $filename, string $missingMessage): void
    {
        $path = dirname(__DIR__) . '/public/' . $filename;
        if (!is_file($path)) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo $missingMessage;
            return;
        }

        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        readfile($path);
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
        EnableWindowsUpdateJobExecution = \$true
        WindowsUpdateCommandTimeoutSeconds = 5400
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

for cmd in git curl launchctl; do
  if ! command -v "\${cmd}" >/dev/null 2>&1; then
    echo "Missing required command: \${cmd}" >&2
    exit 1
  fi
done

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

if [[ -d "\${WORK_DIR}/.git" ]]; then
  git -C "\${WORK_DIR}" fetch --depth 1 origin "\${REPO_REF}"
  git -C "\${WORK_DIR}" checkout -B "\${REPO_REF}" FETCH_HEAD
else
  git clone --depth 1 --branch "\${REPO_REF}" "\${REPO_URL}" "\${WORK_DIR}"
fi

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

        $windowsUpdate['installed_patches'] = array_slice($normalizedPatches, 0, 300);
        $windowsUpdate['installed_patches_count'] = count($normalizedPatches);
        return $windowsUpdate;
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
