<?php

declare(strict_types=1);

namespace PatchAgent\Api;

use PatchAgent\Api\Http\JsonResponse;
use PatchAgent\Api\Http\Request;
use PatchAgent\Api\Storage\AgentRepository;
use PatchAgent\Api\Storage\EventRepository;
use PatchAgent\Api\Storage\FileStore;
use PatchAgent\Api\Storage\InventoryRepository;
use PatchAgent\Api\Storage\JobRepository;
use Throwable;

final class App
{
    private Config $config;
    private AgentRepository $agents;
    private InventoryRepository $inventory;
    private EventRepository $events;
    private JobRepository $jobs;

    public function __construct()
    {
        $this->config = Config::fromEnvironment();
        $store = new FileStore($this->config->storageRoot);

        $this->agents = new AgentRepository($store);
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

        if ($this->config->enrollmentKey !== '' && !hash_equals($this->config->enrollmentKey, $incomingEnrollmentKey)) {
            throw new ApiException(403, 'invalid_enrollment_key', 'The enrollment key is invalid.');
        }

        $device = $body['device'] ?? [];
        $agentInfo = $body['agent'] ?? [];
        $os = $body['os'] ?? [];

        $deviceId = $this->requireString($device, 'device_id');
        $hostname = $this->requireString($device, 'hostname');

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

    private function requireString(array $source, string $key): string
    {
        $value = $source[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new ApiException(422, 'invalid_request', sprintf('Field "%s" is required.', $key));
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
