<?php

declare(strict_types=1);

namespace PatchAgent\Api\Storage;

final class JobRepository
{
    private const FILE = 'jobs.json';
    private const TERMINAL_STATUSES = ['succeeded', 'failed', 'canceled', 'rejected'];

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

    public function findActiveDuplicate(
        string $type,
        string $targetAgentId,
        string $targetDeviceId,
        array $payload
    ): ?array {
        $typeNormalized = strtolower(trim($type));
        if ($typeNormalized === '') {
            return null;
        }

        $candidateSignature = $this->payloadSignature($typeNormalized, $payload);
        $jobs = $this->store->readJson(self::FILE, ['jobs' => []]);
        foreach ($jobs['jobs'] as $job) {
            if (!is_array($job)) {
                continue;
            }

            if (!$this->isActiveStatus((string) ($job['status'] ?? 'assigned'))) {
                continue;
            }

            if (strtolower(trim((string) ($job['type'] ?? ''))) !== $typeNormalized) {
                continue;
            }

            if ($targetAgentId !== '' && trim((string) ($job['target_agent_id'] ?? '')) !== $targetAgentId) {
                continue;
            }

            if ($targetDeviceId !== '' && trim((string) ($job['target_device_id'] ?? '')) !== $targetDeviceId) {
                continue;
            }

            $jobPayload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
            if ($this->payloadSignature($typeNormalized, $jobPayload) !== $candidateSignature) {
                continue;
            }

            return $job;
        }

        return null;
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

    public function cancelJob(string $jobId, string $reason = ''): ?array
    {
        return $this->updateJob($jobId, function (array $job) use ($reason): array {
            $currentStatus = strtolower(trim((string) ($job['status'] ?? 'assigned')));
            if (!$this->isActiveStatus($currentStatus)) {
                return $job;
            }

            $job['status'] = 'canceled';
            $job['canceled_at'] = gmdate(DATE_ATOM);
            $job['cancel_reason'] = trim($reason);
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

    private function isActiveStatus(string $status): bool
    {
        $normalized = strtolower(trim($status));
        if ($normalized === '') {
            $normalized = 'assigned';
        }

        return !in_array($normalized, self::TERMINAL_STATUSES, true);
    }

    private function payloadSignature(string $typeNormalized, array $payload): string
    {
        $normalizedPayload = $this->normalizePayloadForComparison($typeNormalized, $payload);
        return json_encode($normalizedPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ?: md5(serialize($normalizedPayload));
    }

    private function normalizePayloadForComparison(string $typeNormalized, array $payload): array
    {
        if ($typeNormalized === 'windows_update_install') {
            $windows = is_array($payload['windows_update'] ?? null) ? $payload['windows_update'] : [];
            $kbs = is_array($windows['kbs'] ?? null) ? $windows['kbs'] : [];
            $normalizedKbs = [];
            foreach ($kbs as $kb) {
                if (!is_string($kb)) {
                    continue;
                }

                $value = strtoupper(trim($kb));
                if ($value === '') {
                    continue;
                }

                if (preg_match('/^\d+$/', $value) === 1) {
                    $value = 'KB' . $value;
                }

                $normalizedKbs[$value] = true;
            }

            $kbList = array_values(array_keys($normalizedKbs));
            sort($kbList, SORT_STRING);

            return [
                'windows_update' => [
                    'install_all' => filter_var($windows['install_all'] ?? false, FILTER_VALIDATE_BOOL),
                    'kbs' => $kbList,
                ],
            ];
        }

        if ($typeNormalized === 'ubuntu_apt_upgrade') {
            $apt = is_array($payload['apt'] ?? null) ? $payload['apt'] : [];
            $packages = is_array($apt['packages'] ?? null) ? $apt['packages'] : [];
            $normalizedPackages = [];
            foreach ($packages as $package) {
                if (!is_string($package)) {
                    continue;
                }

                $value = strtolower(trim($package));
                if ($value === '') {
                    continue;
                }

                $normalizedPackages[$value] = true;
            }

            $packageList = array_values(array_keys($normalizedPackages));
            sort($packageList, SORT_STRING);

            return [
                'apt' => [
                    'upgrade_all' => filter_var($apt['upgrade_all'] ?? false, FILTER_VALIDATE_BOOL),
                    'packages' => $packageList,
                ],
            ];
        }

        if ($typeNormalized === 'macos_software_update') {
            $mac = is_array($payload['macos_update'] ?? null) ? $payload['macos_update'] : [];
            $labels = is_array($mac['labels'] ?? null) ? $mac['labels'] : [];
            $normalizedLabels = [];
            foreach ($labels as $label) {
                if (!is_string($label)) {
                    continue;
                }

                $value = trim($label);
                if ($value === '') {
                    continue;
                }

                $normalizedLabels[$value] = true;
            }

            $labelList = array_values(array_keys($normalizedLabels));
            sort($labelList, SORT_STRING);

            return [
                'macos_update' => [
                    'install_all' => filter_var($mac['install_all'] ?? false, FILTER_VALIDATE_BOOL),
                    'labels' => $labelList,
                ],
            ];
        }

        if (
            $typeNormalized === 'software_install'
            || $typeNormalized === 'application_install'
            || $typeNormalized === 'package_install'
        ) {
            $software = is_array($payload['software_install'] ?? null)
                ? $payload['software_install']
                : (is_array($payload['software'] ?? null) ? $payload['software'] : []);
            $packages = is_array($software['packages'] ?? null)
                ? $software['packages']
                : (
                    is_array($software['ids'] ?? null)
                        ? $software['ids']
                        : (is_array($software['package_ids'] ?? null) ? $software['package_ids'] : [])
                );
            $normalizedPackages = [];
            foreach ($packages as $package) {
                if (!is_string($package)) {
                    continue;
                }

                $value = trim($package);
                if ($value === '') {
                    continue;
                }

                $normalizedPackages[strtolower($value)] = $value;
            }

            $packageList = array_values($normalizedPackages);
            sort($packageList, SORT_STRING);

            $manager = strtolower(trim((string) ($software['manager'] ?? 'auto')));
            if ($manager === '') {
                $manager = 'auto';
            }

            return [
                'software_install' => [
                    'manager' => $manager,
                    'allow_update' => filter_var(
                        $software['allow_update'] ?? ($software['allow_upgrade'] ?? false),
                        FILTER_VALIDATE_BOOL
                    ),
                    'packages' => $packageList,
                ],
            ];
        }

        if (
            $typeNormalized === 'software_search'
            || $typeNormalized === 'application_search'
            || $typeNormalized === 'package_search'
        ) {
            $search = is_array($payload['software_search'] ?? null)
                ? $payload['software_search']
                : (
                    is_array($payload['search'] ?? null)
                        ? $payload['search']
                        : (is_array($payload['software'] ?? null) ? $payload['software'] : [])
                );

            $query = trim((string) ($search['query'] ?? ($search['search'] ?? ($search['term'] ?? ''))));
            $manager = strtolower(trim((string) ($search['manager'] ?? 'auto')));
            if ($manager === '') {
                $manager = 'auto';
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

        if ($typeNormalized === 'windows_powershell_script') {
            $script = is_array($payload['windows_script'] ?? null) ? $payload['windows_script'] : [];
            return [
                'windows_script' => [
                    'script' => trim((string) ($script['script'] ?? '')),
                    'script_url' => trim((string) ($script['script_url'] ?? '')),
                ],
            ];
        }

        if ($typeNormalized === 'macos_shell_script'
            || $typeNormalized === 'mac_shell_script'
            || $typeNormalized === 'macos_run_script') {
            $script = is_array($payload['macos_script'] ?? null)
                ? $payload['macos_script']
                : (
                    is_array($payload['mac_script'] ?? null)
                        ? $payload['mac_script']
                        : (is_array($payload['shell_script'] ?? null) ? $payload['shell_script'] : [])
                );
            return [
                'macos_script' => [
                    'script' => trim((string) ($script['script'] ?? '')),
                    'script_url' => trim((string) ($script['script_url'] ?? '')),
                ],
            ];
        }

        if ($typeNormalized === 'agent_self_update' || $typeNormalized === 'self_update') {
            $selfUpdate = is_array($payload['agent_self_update'] ?? null)
                ? $payload['agent_self_update']
                : (is_array($payload['self_update'] ?? null) ? $payload['self_update'] : []);

            return [
                'agent_self_update' => [
                    'repo_url' => trim((string) ($selfUpdate['repo_url'] ?? '')),
                    'repo_ref' => trim((string) ($selfUpdate['repo_ref'] ?? $selfUpdate['branch'] ?? '')),
                    'package_url' => trim((string) ($selfUpdate['package_url'] ?? $selfUpdate['windows_package_url'] ?? '')),
                    'windows_install_mode' => in_array(
                        strtolower(trim((string) (
                            $selfUpdate['windows_install_mode']
                            ?? $selfUpdate['install_mode']
                            ?? $selfUpdate['mode']
                            ?? 'source'
                        ))),
                        ['source', 'prebuilt'],
                        true
                    )
                        ? strtolower(trim((string) (
                            $selfUpdate['windows_install_mode']
                            ?? $selfUpdate['install_mode']
                            ?? $selfUpdate['mode']
                            ?? 'source'
                        )))
                        : 'source',
                ],
            ];
        }

        return $this->normalizeRecursive($payload);
    }

    private function normalizeRecursive(mixed $value): mixed
    {
        if (is_array($value)) {
            if ($this->isList($value)) {
                $normalized = array_map(fn ($item) => $this->normalizeRecursive($item), $value);
                if ($this->isScalarList($normalized)) {
                    $copy = $normalized;
                    sort($copy);
                    return $copy;
                }

                return $normalized;
            }

            $normalized = [];
            ksort($value);
            foreach ($value as $key => $item) {
                $normalized[(string) $key] = $this->normalizeRecursive($item);
            }
            return $normalized;
        }

        if (is_string($value)) {
            return trim($value);
        }

        return $value;
    }

    private function isScalarList(array $value): bool
    {
        foreach ($value as $item) {
            if (!is_scalar($item) && $item !== null) {
                return false;
            }
        }

        return true;
    }

    private function isList(array $value): bool
    {
        if (function_exists('array_is_list')) {
            return array_is_list($value);
        }

        $index = 0;
        foreach ($value as $key => $_unused) {
            if ($key !== $index) {
                return false;
            }
            $index++;
        }

        return true;
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
