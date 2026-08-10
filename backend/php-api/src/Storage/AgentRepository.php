<?php

declare(strict_types=1);

namespace PatchAgent\Api\Storage;

final class AgentRepository
{
    private const FILE = 'agents.json';

    public function __construct(private readonly FileStore $store)
    {
    }

    public function upsertRegistration(array $registration): array
    {
        $agents = $this->store->readJson(self::FILE, ['agents' => []]);
        $existingIndex = null;

        foreach ($agents['agents'] as $index => $agent) {
            if (($agent['device_id'] ?? null) === $registration['device_id']) {
                $existingIndex = $index;
                break;
            }
        }

        $existing = $existingIndex === null ? [] : $agents['agents'][$existingIndex];
        $agentRecordId = (string) ($existing['agent_record_id'] ?? $this->newId('agt'));
        $plainToken = $this->newToken();

        $record = [
            'agent_record_id' => $agentRecordId,
            'device_id' => $registration['device_id'],
            'hostname' => $registration['hostname'],
            // Falls back to whatever was already stored, so a re-register from an
            // older agent that does not send it does not erase a known instance id.
            'instance_id' => (string) (($registration['instance_id'] ?? '') !== ''
                ? $registration['instance_id']
                : ($existing['instance_id'] ?? '')),
            'display_name' => (string) ($existing['display_name'] ?? $registration['hostname']),
            'domain' => $registration['domain'],
            'os' => $registration['os'],
            'agent' => $registration['agent'],
            'capabilities' => $registration['capabilities'],
            'token_hash' => hash('sha256', $plainToken),
            'created_at' => (string) ($existing['created_at'] ?? gmdate(DATE_ATOM)),
            'updated_at' => gmdate(DATE_ATOM),
            'last_seen_at' => (string) ($existing['last_seen_at'] ?? null),
            'last_heartbeat' => (array) ($existing['last_heartbeat'] ?? []),
        ];

        if ($existingIndex === null) {
            $agents['agents'][] = $record;
        } else {
            $agents['agents'][$existingIndex] = $record;
        }

        $this->store->writeJson(self::FILE, $agents);

        $record['agent_token'] = $plainToken;
        return $record;
    }

    public function findByToken(string $token): ?array
    {
        $agents = $this->store->readJson(self::FILE, ['agents' => []]);
        $tokenHash = hash('sha256', $token);

        foreach ($agents['agents'] as $agent) {
            if (($agent['token_hash'] ?? '') === $tokenHash) {
                return $agent;
            }
        }

        return null;
    }

    public function recordHeartbeat(string $agentRecordId, array $heartbeat): void
    {
        $agents = $this->store->readJson(self::FILE, ['agents' => []]);

        foreach ($agents['agents'] as $index => $agent) {
            if (($agent['agent_record_id'] ?? null) !== $agentRecordId) {
                continue;
            }

            $agent['last_seen_at'] = gmdate(DATE_ATOM);
            $agent['updated_at'] = gmdate(DATE_ATOM);

            // Promoted from the heartbeat rather than requiring a re-register, so
            // upgrading the agent is enough for an enrolled host to gain its
            // instance id. Folded into this existing read-modify-write on purpose:
            // a second write per heartbeat would only widen the window in which
            // concurrent agents can clobber each other's updates.
            $reported = trim((string) ($heartbeat['instance_id'] ?? ''));
            if ($reported !== '' && preg_match('/^i-[0-9a-f]{8,32}$/', $reported) === 1) {
                $agent['instance_id'] = $reported;
            }

            $agent['last_heartbeat'] = $heartbeat;
            $agents['agents'][$index] = $agent;

            $this->store->writeJson(self::FILE, $agents);
            return;
        }
    }

    public function updateDisplayName(string $agentRecordId, string $displayName): ?array
    {
        $agents = $this->store->readJson(self::FILE, ['agents' => []]);

        foreach ($agents['agents'] as $index => $agent) {
            if (($agent['agent_record_id'] ?? null) !== $agentRecordId) {
                continue;
            }

            $agent['display_name'] = $displayName;
            $agent['updated_at'] = gmdate(DATE_ATOM);
            $agents['agents'][$index] = $agent;

            $this->store->writeJson(self::FILE, $agents);
            return $agent;
        }

        return null;
    }

    public function listAgents(): array
    {
        $agents = $this->store->readJson(self::FILE, ['agents' => []]);
        $records = array_values(is_array($agents['agents'] ?? null) ? $agents['agents'] : []);

        usort($records, static function (array $left, array $right): int {
            $leftSeen = (string) ($left['last_seen_at'] ?? '');
            $rightSeen = (string) ($right['last_seen_at'] ?? '');

            if ($leftSeen === $rightSeen) {
                $leftUpdated = (string) ($left['updated_at'] ?? '');
                $rightUpdated = (string) ($right['updated_at'] ?? '');
                return strcmp($rightUpdated, $leftUpdated);
            }

            return strcmp($rightSeen, $leftSeen);
        });

        return array_map(static function (array $agent): array {
            $capabilities = is_array($agent['capabilities'] ?? null) ? $agent['capabilities'] : [];
            $cleanCapabilities = array_values(array_filter(
                $capabilities,
                static fn ($value): bool => is_string($value) && trim($value) !== ''
            ));

            return [
                'agent_record_id' => (string) ($agent['agent_record_id'] ?? ''),
                'device_id' => (string) ($agent['device_id'] ?? ''),
                'hostname' => (string) ($agent['hostname'] ?? ''),
                // This projection is a whitelist: a field absent here is invisible
                // to every caller no matter what is stored. handleAdminServers
                // joins on instance_id, so omitting it made the join silently fall
                // back to hostname guessing with no error anywhere.
                'instance_id' => (string) ($agent['instance_id'] ?? ''),
                'display_name' => (string) ($agent['display_name'] ?? ($agent['hostname'] ?? '')),
                'domain' => (string) ($agent['domain'] ?? ''),
                'os' => is_array($agent['os'] ?? null) ? $agent['os'] : [],
                'agent' => is_array($agent['agent'] ?? null) ? $agent['agent'] : [],
                'capabilities' => $cleanCapabilities,
                'created_at' => (string) ($agent['created_at'] ?? ''),
                'updated_at' => (string) ($agent['updated_at'] ?? ''),
                'last_seen_at' => (string) ($agent['last_seen_at'] ?? ''),
                'last_heartbeat' => is_array($agent['last_heartbeat'] ?? null) ? $agent['last_heartbeat'] : [],
            ];
        }, $records);
    }

    private function newId(string $prefix): string
    {
        return sprintf('%s_%s', $prefix, bin2hex(random_bytes(10)));
    }

    private function newToken(): string
    {
        return bin2hex(random_bytes(32));
    }
}
