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
            $agent['last_heartbeat'] = $heartbeat;
            $agents['agents'][$index] = $agent;

            $this->store->writeJson(self::FILE, $agents);
            return;
        }
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
