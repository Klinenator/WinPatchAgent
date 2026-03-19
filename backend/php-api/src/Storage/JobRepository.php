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
            ];
        }

        return null;
    }
}
