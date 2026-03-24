<?php

declare(strict_types=1);

namespace PatchAgent\Api\Storage;

final class JobRepository
{
    private const FILE = 'jobs.json';

    public function __construct(private readonly FileStore $store)
    {
    }

    public function findNextJob(string $agentRecordId, string $deviceId): ?array
    {
        $jobs = $this->store->readJson(self::FILE, ['jobs' => []]);

        foreach ($jobs['jobs'] as $job) {
            if (($job['status'] ?? 'assigned') !== 'assigned') {
                continue;
            }

            $targetAgentId = (string) ($job['target_agent_id'] ?? '');
            $targetDeviceId = (string) ($job['target_device_id'] ?? '');

            $agentMatch = $targetAgentId !== '' && hash_equals($targetAgentId, $agentRecordId);
            $deviceMatch = $targetDeviceId !== '' && hash_equals($targetDeviceId, $deviceId);

            if (!$agentMatch && !$deviceMatch) {
                continue;
            }

            return [
                'job_id' => (string) ($job['job_id'] ?? ''),
                'type' => (string) ($job['type'] ?? ''),
                'correlation_id' => (string) ($job['correlation_id'] ?? ''),
                'policy' => [
                    'maintenance_window' => [
                        'start' => $job['policy']['maintenance_window']['start'] ?? null,
                        'end' => $job['policy']['maintenance_window']['end'] ?? null,
                    ],
                ],
                'payload' => is_array($job['payload'] ?? null) ? $job['payload'] : null,
            ];
        }

        return null;
    }

    public function createJob(array $input): array
    {
        $jobs = $this->store->readJson(self::FILE, ['jobs' => []]);

        $record = [
            'job_id' => $this->stringOrDefault($input, 'job_id', $this->newId('job')),
            'type' => $this->stringOrDefault($input, 'type', 'windows_update_install'),
            'correlation_id' => $this->stringOrDefault($input, 'correlation_id', $this->newId('dep')),
            'status' => $this->stringOrDefault($input, 'status', 'assigned'),
            'target_agent_id' => $this->stringOrDefault($input, 'target_agent_id', ''),
            'target_device_id' => $this->stringOrDefault($input, 'target_device_id', ''),
            'policy' => $this->normalizePolicy(is_array($input['policy'] ?? null) ? $input['policy'] : []),
            'payload' => is_array($input['payload'] ?? null) ? $input['payload'] : [],
            'created_at' => gmdate(DATE_ATOM),
        ];

        $jobs['jobs'][] = $record;
        $this->store->writeJson(self::FILE, $jobs);

        return $record;
    }

    public function listJobs(): array
    {
        $jobs = $this->store->readJson(self::FILE, ['jobs' => []]);
        return array_values(is_array($jobs['jobs'] ?? null) ? $jobs['jobs'] : []);
    }

    public function acknowledgeJob(string $jobId, string $agentRecordId, array $acknowledgement): ?array
    {
        return $this->updateJob($jobId, function (array $job) use ($agentRecordId, $acknowledgement): array {
            $ack = (string) ($acknowledgement['ack'] ?? 'accepted');
            $currentStatus = strtolower((string) ($job['status'] ?? 'assigned'));

            if (in_array($currentStatus, ['succeeded', 'failed', 'canceled', 'rejected'], true)) {
                return $job;
            }

            if ($ack === 'accepted') {
                $job['status'] = $currentStatus === 'acknowledged' ? 'acknowledged' : 'acknowledged';
            } else {
                $job['status'] = 'rejected';
            }
            $job['acknowledged_by_agent_id'] = $agentRecordId;
            $job['acknowledged_at'] = (string) ($acknowledgement['acknowledged_at'] ?? gmdate(DATE_ATOM));
            $job['acknowledgement'] = [
                'ack' => $ack,
                'reason' => (string) ($acknowledgement['reason'] ?? ''),
            ];
            $job['updated_at'] = gmdate(DATE_ATOM);

            return $job;
        });
    }

    public function completeJob(string $jobId, string $agentRecordId, array $completion): ?array
    {
        return $this->updateJob($jobId, function (array $job) use ($agentRecordId, $completion): array {
            $finalState = strtolower((string) ($completion['final_state'] ?? 'succeeded'));

            $job['status'] = $finalState;
            $job['completed_by_agent_id'] = $agentRecordId;
            $job['completed_at'] = (string) ($completion['completed_at'] ?? gmdate(DATE_ATOM));
            $job['completion'] = [
                'final_state' => (string) ($completion['final_state'] ?? 'Succeeded'),
                'result' => is_array($completion['result'] ?? null) ? $completion['result'] : [],
                'error' => is_array($completion['error'] ?? null) ? $completion['error'] : null,
            ];
            $job['updated_at'] = gmdate(DATE_ATOM);

            return $job;
        });
    }

    private function normalizePolicy(array $policy): array
    {
        $window = is_array($policy['maintenance_window'] ?? null)
            ? $policy['maintenance_window']
            : [];

        return [
            'maintenance_window' => [
                'start' => $this->nullableString($window, 'start'),
                'end' => $this->nullableString($window, 'end'),
            ],
        ];
    }

    private function stringOrDefault(array $input, string $key, string $default): string
    {
        $value = $input[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            return $default;
        }

        return trim($value);
    }

    private function nullableString(array $input, string $key): ?string
    {
        $value = $input[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    private function newId(string $prefix): string
    {
        return sprintf('%s_%s', $prefix, bin2hex(random_bytes(10)));
    }

    private function updateJob(string $jobId, callable $transform): ?array
    {
        $jobs = $this->store->readJson(self::FILE, ['jobs' => []]);

        foreach ($jobs['jobs'] as $index => $job) {
            if (($job['job_id'] ?? null) !== $jobId) {
                continue;
            }

            $updated = $transform($job);
            $jobs['jobs'][$index] = $updated;
            $this->store->writeJson(self::FILE, $jobs);

            return $updated;
        }

        return null;
    }
}
