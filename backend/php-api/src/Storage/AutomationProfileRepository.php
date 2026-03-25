<?php

declare(strict_types=1);

namespace PatchAgent\Api\Storage;

use DateTimeImmutable;
use DateTimeZone;

final class AutomationProfileRepository
{
    private const FILE = 'automation_profiles.json';

    /** @var array<string, int> */
    private const DAY_TO_INDEX = [
        'sun' => 0,
        'mon' => 1,
        'tue' => 2,
        'wed' => 3,
        'thu' => 4,
        'fri' => 5,
        'sat' => 6,
    ];

    /** @var array<int, string> */
    private const INDEX_TO_DAY = [
        0 => 'sun',
        1 => 'mon',
        2 => 'tue',
        3 => 'wed',
        4 => 'thu',
        5 => 'fri',
        6 => 'sat',
    ];

    public function __construct(private readonly FileStore $store)
    {
    }

    public function listProfiles(): array
    {
        $payload = $this->store->readJson(self::FILE, ['profiles' => []]);
        $profiles = array_values(is_array($payload['profiles'] ?? null) ? $payload['profiles'] : []);

        $normalized = [];
        foreach ($profiles as $profile) {
            if (!is_array($profile)) {
                continue;
            }

            $normalized[] = $this->normalizeProfileForRead($profile);
        }

        usort($normalized, static function (array $left, array $right): int {
            $leftUpdated = (string) ($left['updated_at'] ?? '');
            $rightUpdated = (string) ($right['updated_at'] ?? '');
            return strcmp($rightUpdated, $leftUpdated);
        });

        return $normalized;
    }

    public function findProfile(string $profileId): ?array
    {
        foreach ($this->listProfiles() as $profile) {
            if ((string) ($profile['profile_id'] ?? '') === $profileId) {
                return $profile;
            }
        }

        return null;
    }

    public function saveProfile(array $input): array
    {
        $payload = $this->store->readJson(self::FILE, ['profiles' => []]);
        $profiles = array_values(is_array($payload['profiles'] ?? null) ? $payload['profiles'] : []);
        $profileId = $this->stringOrNull($input['profile_id'] ?? null);

        $existingIndex = null;
        $existingProfile = null;
        if ($profileId !== null) {
            foreach ($profiles as $index => $candidate) {
                if (!is_array($candidate)) {
                    continue;
                }

                if ((string) ($candidate['profile_id'] ?? '') === $profileId) {
                    $existingIndex = $index;
                    $existingProfile = $candidate;
                    break;
                }
            }
        }

        $profile = $this->normalizeProfileForSave($input, $existingProfile);

        if ($existingIndex === null) {
            $profiles[] = $profile;
        } else {
            $profiles[$existingIndex] = $profile;
        }

        $payload['profiles'] = $profiles;
        $this->store->writeJson(self::FILE, $payload);

        return $this->normalizeProfileForRead($profile);
    }

    public function deleteProfile(string $profileId): bool
    {
        $payload = $this->store->readJson(self::FILE, ['profiles' => []]);
        $profiles = array_values(is_array($payload['profiles'] ?? null) ? $payload['profiles'] : []);
        $filtered = [];
        $deleted = false;

        foreach ($profiles as $profile) {
            if (!is_array($profile)) {
                continue;
            }

            if ((string) ($profile['profile_id'] ?? '') === $profileId) {
                $deleted = true;
                continue;
            }

            $filtered[] = $profile;
        }

        if (!$deleted) {
            return false;
        }

        $payload['profiles'] = $filtered;
        $this->store->writeJson(self::FILE, $payload);
        return true;
    }

    public function recordExecution(string $profileId, string $trigger, array $summary): ?array
    {
        $payload = $this->store->readJson(self::FILE, ['profiles' => []]);
        $profiles = array_values(is_array($payload['profiles'] ?? null) ? $payload['profiles'] : []);

        foreach ($profiles as $index => $profile) {
            if (!is_array($profile) || (string) ($profile['profile_id'] ?? '') !== $profileId) {
                continue;
            }

            $normalized = $this->normalizeProfileForSave($profile, $profile);
            $now = gmdate(DATE_ATOM);
            $normalized['last_executed_at'] = $now;
            $normalized['last_execution_trigger'] = trim($trigger) === '' ? 'manual' : trim($trigger);
            $normalized['last_execution_summary'] = [
                'jobs_queued' => (int) ($summary['jobs_queued'] ?? 0),
                'agents_targeted' => (int) ($summary['agents_targeted'] ?? 0),
                'at' => $now,
            ];
            $normalized['updated_at'] = $now;

            if (($normalized['active'] ?? false) && (($normalized['schedule']['mode'] ?? 'manual') !== 'manual')) {
                $next = $this->computeNextExecutionUtc(
                    $normalized,
                    new DateTimeImmutable('now', new DateTimeZone('UTC'))
                );
                $normalized['next_execution_at'] = $next;
            } else {
                $normalized['next_execution_at'] = null;
            }

            $profiles[$index] = $normalized;
            $payload['profiles'] = $profiles;
            $this->store->writeJson(self::FILE, $payload);

            return $this->normalizeProfileForRead($normalized);
        }

        return null;
    }

    public function claimDueProfiles(string $nowUtcIso): array
    {
        $payload = $this->store->readJson(self::FILE, ['profiles' => []]);
        $profiles = array_values(is_array($payload['profiles'] ?? null) ? $payload['profiles'] : []);
        $dueProfiles = [];
        $changed = false;

        $nowUtc = $this->parseUtc($nowUtcIso) ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));

        foreach ($profiles as $index => $profile) {
            if (!is_array($profile)) {
                continue;
            }

            $normalized = $this->normalizeProfileForSave($profile, $profile);
            if (!(bool) ($normalized['active'] ?? false)) {
                $profiles[$index] = $normalized;
                continue;
            }

            if (($normalized['schedule']['mode'] ?? 'manual') === 'manual') {
                $profiles[$index] = $normalized;
                continue;
            }

            $nextExecutionRaw = $this->stringOrNull($normalized['next_execution_at'] ?? null);
            if ($nextExecutionRaw === null) {
                $normalized['next_execution_at'] = $this->computeNextExecutionUtc($normalized, $nowUtc);
                $profiles[$index] = $normalized;
                $changed = true;
                continue;
            }

            $nextExecution = $this->parseUtc($nextExecutionRaw);
            if ($nextExecution === null || $nextExecution > $nowUtc) {
                $profiles[$index] = $normalized;
                continue;
            }

            $normalized['last_execution_claimed_at'] = $nowUtc->format(DATE_ATOM);
            $normalized['next_execution_at'] = $this->computeNextExecutionUtc($normalized, $nowUtc);
            $normalized['updated_at'] = $nowUtc->format(DATE_ATOM);

            $profiles[$index] = $normalized;
            $dueProfiles[] = $this->normalizeProfileForRead($normalized);
            $changed = true;
        }

        if ($changed) {
            $payload['profiles'] = $profiles;
            $this->store->writeJson(self::FILE, $payload);
        }

        return $dueProfiles;
    }

    public function markAppliedToAgent(string $profileId, string $agentRecordId): bool
    {
        $payload = $this->store->readJson(self::FILE, ['profiles' => []]);
        $profiles = array_values(is_array($payload['profiles'] ?? null) ? $payload['profiles'] : []);
        $agentRecordId = trim($agentRecordId);
        if ($agentRecordId === '') {
            return false;
        }

        foreach ($profiles as $index => $profile) {
            if (!is_array($profile) || (string) ($profile['profile_id'] ?? '') !== $profileId) {
                continue;
            }

            $normalized = $this->normalizeProfileForSave($profile, $profile);
            $applied = is_array($normalized['applied_to_agent_ids'] ?? null) ? $normalized['applied_to_agent_ids'] : [];
            foreach ($applied as $existingAgentId) {
                if (is_string($existingAgentId) && $existingAgentId === $agentRecordId) {
                    return false;
                }
            }

            $applied[] = $agentRecordId;
            if (count($applied) > 1000) {
                $applied = array_slice($applied, -1000);
            }

            $normalized['applied_to_agent_ids'] = array_values($applied);
            $normalized['updated_at'] = gmdate(DATE_ATOM);
            $profiles[$index] = $normalized;
            $payload['profiles'] = $profiles;
            $this->store->writeJson(self::FILE, $payload);
            return true;
        }

        return false;
    }

    private function normalizeProfileForRead(array $profile): array
    {
        return $this->normalizeProfileForSave($profile, $profile);
    }

    private function normalizeProfileForSave(array $input, ?array $existing): array
    {
        $now = gmdate(DATE_ATOM);
        $profileId = $this->stringOrNull($input['profile_id'] ?? ($existing['profile_id'] ?? null))
            ?? $this->newId('aut');
        $createdAt = $this->stringOrNull($existing['created_at'] ?? ($input['created_at'] ?? null)) ?? $now;
        $timeZone = $this->normalizeTimeZone(
            $this->stringOrNull($input['time_zone'] ?? ($existing['time_zone'] ?? null)) ?? 'UTC'
        );
        $schedule = $this->normalizeSchedule(
            is_array($input['schedule'] ?? null) ? $input['schedule'] : [],
            is_array($existing['schedule'] ?? null) ? $existing['schedule'] : []
        );

        $profile = [
            'profile_id' => $profileId,
            'name' => $this->stringOrNull($input['name'] ?? ($existing['name'] ?? null)) ?? 'Automation Profile',
            'description' => $this->stringOrNull($input['description'] ?? ($existing['description'] ?? null)) ?? '',
            'active' => $this->boolOrDefault($input['active'] ?? ($existing['active'] ?? null), true),
            'run_on_new_agents' => $this->boolOrDefault(
                $input['run_on_new_agents'] ?? ($existing['run_on_new_agents'] ?? null),
                false
            ),
            'time_zone' => $timeZone,
            'schedule' => $schedule,
            'targets' => $this->normalizeTargets(
                is_array($input['targets'] ?? null) ? $input['targets'] : [],
                is_array($existing['targets'] ?? null) ? $existing['targets'] : []
            ),
            'tasks' => $this->normalizeTasks(
                is_array($input['tasks'] ?? null) ? $input['tasks'] : [],
                is_array($existing['tasks'] ?? null) ? $existing['tasks'] : []
            ),
            'created_at' => $createdAt,
            'updated_at' => $now,
            'last_executed_at' => $this->stringOrNull($input['last_executed_at'] ?? ($existing['last_executed_at'] ?? null)),
            'last_execution_trigger' => $this->stringOrNull(
                $input['last_execution_trigger'] ?? ($existing['last_execution_trigger'] ?? null)
            ) ?? '',
            'last_execution_summary' => is_array($input['last_execution_summary'] ?? null)
                ? $input['last_execution_summary']
                : (is_array($existing['last_execution_summary'] ?? null) ? $existing['last_execution_summary'] : []),
            'last_execution_claimed_at' => $this->stringOrNull(
                $input['last_execution_claimed_at'] ?? ($existing['last_execution_claimed_at'] ?? null)
            ),
            'applied_to_agent_ids' => $this->normalizeAppliedAgentIds(
                is_array($input['applied_to_agent_ids'] ?? null)
                    ? $input['applied_to_agent_ids']
                    : (is_array($existing['applied_to_agent_ids'] ?? null) ? $existing['applied_to_agent_ids'] : [])
            ),
        ];

        $nextInput = $this->stringOrNull($input['next_execution_at'] ?? ($existing['next_execution_at'] ?? null));
        if (!$profile['active'] || ($schedule['mode'] ?? 'manual') === 'manual') {
            $profile['next_execution_at'] = null;
        } else {
            $next = $nextInput;
            if ($next === null || $this->parseUtc($next) === null) {
                $next = $this->computeNextExecutionUtc(
                    $profile,
                    new DateTimeImmutable('now', new DateTimeZone('UTC'))
                );
            }
            $profile['next_execution_at'] = $next;
        }

        return $profile;
    }

    private function normalizeSchedule(array $input, array $existing): array
    {
        $mode = strtolower(trim((string) ($input['mode'] ?? ($existing['mode'] ?? 'manual'))));
        if (!in_array($mode, ['manual', 'hourly', 'daily', 'weekly'], true)) {
            $mode = 'manual';
        }

        $intervalHours = (int) ($input['interval_hours'] ?? ($existing['interval_hours'] ?? 24));
        if ($intervalHours < 1) {
            $intervalHours = 1;
        }
        if ($intervalHours > 168) {
            $intervalHours = 168;
        }

        $timeOfDay = $this->normalizeTimeOfDay((string) ($input['time_of_day'] ?? ($existing['time_of_day'] ?? '03:00')));
        $days = $this->normalizeDaysOfWeek(
            is_array($input['days_of_week'] ?? null)
                ? $input['days_of_week']
                : (is_array($existing['days_of_week'] ?? null) ? $existing['days_of_week'] : [])
        );
        if ($mode === 'weekly' && count($days) === 0) {
            $days = ['mon'];
        }

        return [
            'mode' => $mode,
            'interval_hours' => $intervalHours,
            'time_of_day' => $timeOfDay,
            'days_of_week' => $days,
        ];
    }

    private function normalizeTargets(array $input, array $existing): array
    {
        return [
            'windows' => $this->boolOrDefault($input['windows'] ?? ($existing['windows'] ?? null), true),
            'linux' => $this->boolOrDefault($input['linux'] ?? ($existing['linux'] ?? null), true),
            'mac' => $this->boolOrDefault($input['mac'] ?? ($existing['mac'] ?? null), true),
        ];
    }

    private function normalizeTasks(array $input, array $existing): array
    {
        $windowsInput = is_array($input['windows'] ?? null) ? $input['windows'] : [];
        $windowsExisting = is_array($existing['windows'] ?? null) ? $existing['windows'] : [];
        $linuxInput = is_array($input['linux'] ?? null) ? $input['linux'] : [];
        $linuxExisting = is_array($existing['linux'] ?? null) ? $existing['linux'] : [];
        $macInput = is_array($input['mac'] ?? null) ? $input['mac'] : [];
        $macExisting = is_array($existing['mac'] ?? null) ? $existing['mac'] : [];
        $maintenanceInput = is_array($input['maintenance'] ?? null) ? $input['maintenance'] : [];
        $maintenanceExisting = is_array($existing['maintenance'] ?? null) ? $existing['maintenance'] : [];

        $scriptsInput = is_array($input['windows_scripts'] ?? null) ? $input['windows_scripts'] : [];
        $scriptsExisting = is_array($existing['windows_scripts'] ?? null) ? $existing['windows_scripts'] : [];
        $scripts = $this->normalizeWindowsScripts($scriptsInput, $scriptsExisting);

        return [
            'windows' => [
                'install_all_updates' => $this->boolOrDefault(
                    $windowsInput['install_all_updates'] ?? ($windowsExisting['install_all_updates'] ?? null),
                    false
                ),
            ],
            'linux' => [
                'upgrade_all' => $this->boolOrDefault(
                    $linuxInput['upgrade_all'] ?? ($linuxExisting['upgrade_all'] ?? null),
                    false
                ),
            ],
            'mac' => [
                'install_all_updates' => $this->boolOrDefault(
                    $macInput['install_all_updates'] ?? ($macExisting['install_all_updates'] ?? null),
                    false
                ),
            ],
            'maintenance' => [
                'reboot_if_needed' => $this->boolOrDefault(
                    $maintenanceInput['reboot_if_needed'] ?? ($maintenanceExisting['reboot_if_needed'] ?? null),
                    false
                ),
            ],
            'windows_scripts' => $scripts,
        ];
    }

    private function normalizeWindowsScripts(array $input, array $existing): array
    {
        $fallbackById = [];
        foreach ($existing as $script) {
            if (!is_array($script)) {
                continue;
            }
            $scriptId = $this->stringOrNull($script['script_id'] ?? null);
            if ($scriptId !== null) {
                $fallbackById[$scriptId] = $script;
            }
        }

        $normalized = [];
        foreach ($input as $script) {
            if (!is_array($script)) {
                continue;
            }

            $scriptId = $this->stringOrNull($script['script_id'] ?? null) ?? $this->newId('scr');
            $fallback = $fallbackById[$scriptId] ?? [];

            $name = $this->stringOrNull($script['name'] ?? ($fallback['name'] ?? null)) ?? 'Script';
            $scriptBody = $this->stringOrNull($script['script'] ?? ($fallback['script'] ?? null)) ?? '';
            $scriptUrl = $this->stringOrNull($script['script_url'] ?? ($fallback['script_url'] ?? null)) ?? '';
            $enabled = $this->boolOrDefault($script['enabled'] ?? ($fallback['enabled'] ?? null), true);

            if ($scriptBody === '' && $scriptUrl === '') {
                continue;
            }

            $normalized[] = [
                'script_id' => $scriptId,
                'name' => $name,
                'script' => $scriptBody,
                'script_url' => $scriptUrl,
                'enabled' => $enabled,
            ];
        }

        return array_slice($normalized, 0, 50);
    }

    private function normalizeAppliedAgentIds(array $input): array
    {
        $result = [];
        foreach ($input as $agentId) {
            if (!is_string($agentId)) {
                continue;
            }

            $trimmed = trim($agentId);
            if ($trimmed === '') {
                continue;
            }

            $result[$trimmed] = true;
        }

        return array_slice(array_keys($result), -1000);
    }

    private function normalizeTimeZone(string $timeZone): string
    {
        $candidate = trim($timeZone);
        if ($candidate === '') {
            return 'UTC';
        }

        try {
            new DateTimeZone($candidate);
            return $candidate;
        } catch (\Throwable) {
            return 'UTC';
        }
    }

    private function normalizeTimeOfDay(string $value): string
    {
        $trimmed = trim($value);
        if (preg_match('/^(2[0-3]|[01]?\d):([0-5]\d)$/', $trimmed, $matches) !== 1) {
            return '03:00';
        }

        $hour = str_pad((string) ((int) $matches[1]), 2, '0', STR_PAD_LEFT);
        $minute = str_pad((string) ((int) $matches[2]), 2, '0', STR_PAD_LEFT);
        return $hour . ':' . $minute;
    }

    private function normalizeDaysOfWeek(array $input): array
    {
        $result = [];
        foreach ($input as $entry) {
            $candidate = strtolower(trim((string) $entry));
            if ($candidate === '') {
                continue;
            }

            if (isset(self::DAY_TO_INDEX[$candidate])) {
                $result[self::DAY_TO_INDEX[$candidate]] = $candidate;
                continue;
            }

            if (is_numeric($candidate)) {
                $index = ((int) $candidate) % 7;
                if ($index < 0) {
                    $index += 7;
                }

                $result[$index] = self::INDEX_TO_DAY[$index];
            }
        }

        ksort($result);
        return array_values($result);
    }

    private function computeNextExecutionUtc(array $profile, DateTimeImmutable $fromUtc): ?string
    {
        $schedule = is_array($profile['schedule'] ?? null) ? $profile['schedule'] : [];
        $mode = strtolower(trim((string) ($schedule['mode'] ?? 'manual')));

        if ($mode === 'manual') {
            return null;
        }

        $timeZoneName = $this->normalizeTimeZone((string) ($profile['time_zone'] ?? 'UTC'));
        $timeZone = new DateTimeZone($timeZoneName);
        $fromLocal = $fromUtc->setTimezone($timeZone);

        if ($mode === 'hourly') {
            $interval = max(1, (int) ($schedule['interval_hours'] ?? 24));
            return $fromUtc->modify('+' . $interval . ' hours')->format(DATE_ATOM);
        }

        $timeOfDay = $this->normalizeTimeOfDay((string) ($schedule['time_of_day'] ?? '03:00'));
        [$hourText, $minuteText] = explode(':', $timeOfDay);
        $hour = (int) $hourText;
        $minute = (int) $minuteText;

        if ($mode === 'daily') {
            $candidate = $fromLocal->setTime($hour, $minute, 0);
            if ($candidate <= $fromLocal) {
                $candidate = $candidate->modify('+1 day');
            }

            return $candidate->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
        }

        if ($mode === 'weekly') {
            $days = $this->normalizeDaysOfWeek(is_array($schedule['days_of_week'] ?? null) ? $schedule['days_of_week'] : []);
            if (count($days) === 0) {
                $days = ['mon'];
            }

            $daySet = array_fill_keys($days, true);
            for ($offset = 0; $offset <= 14; $offset++) {
                $candidate = $fromLocal->modify('+' . $offset . ' day')->setTime($hour, $minute, 0);
                $candidateDay = strtolower(substr($candidate->format('D'), 0, 3));
                if (!isset($daySet[$candidateDay])) {
                    continue;
                }

                if ($candidate <= $fromLocal) {
                    continue;
                }

                return $candidate->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
            }
        }

        return null;
    }

    private function parseUtc(string $value): ?DateTimeImmutable
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($trimmed, new DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }

    private function boolOrDefault(mixed $value, bool $default): bool
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

        return $default;
    }

    private function newId(string $prefix): string
    {
        return sprintf('%s_%s', $prefix, bin2hex(random_bytes(10)));
    }
}

