<?php

declare(strict_types=1);

namespace PatchAgent\Api\Storage;

final class InventoryRepository
{
    public function __construct(private readonly FileStore $store)
    {
    }

    public function storeSnapshot(string $agentRecordId, array $snapshot): void
    {
        $snapshot['stored_at'] = gmdate(DATE_ATOM);
        $this->store->writeJson(sprintf('inventory/%s.json', $agentRecordId), $snapshot);
    }

    public function loadSnapshot(string $agentRecordId): ?array
    {
        $path = sprintf('inventory/%s.json', $agentRecordId);
        if (!$this->store->exists($path)) {
            return null;
        }

        $snapshot = $this->store->readJson($path, []);
        return is_array($snapshot) ? $snapshot : null;
    }
}
