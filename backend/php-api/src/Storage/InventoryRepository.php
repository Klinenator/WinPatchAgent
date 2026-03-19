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
}
