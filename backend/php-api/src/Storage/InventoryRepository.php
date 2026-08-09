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
        $previous = $this->loadSnapshot($agentRecordId);
        $snapshot['pending_reboot_since'] = $this->resolvePendingRebootSince($snapshot, $previous);
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

    /**
     * Tracks when a host first reported a pending reboot, so patch SLAs can be
     * measured against it.
     *
     * Only the latest snapshot is retained per agent, so this cannot be derived
     * after the fact — the clock has to be carried forward on each write. It is
     * kept server-side rather than on the agent so that reinstalling an agent
     * does not silently reset the clock.
     *
     * The caller supplies pending_reboot_effective because deciding what counts
     * as "pending" is OS-specific and already handled in App::detectPendingReboot().
     */
    private function resolvePendingRebootSince(array $snapshot, ?array $previous): ?string
    {
        if (!(bool) ($snapshot['pending_reboot_effective'] ?? false)) {
            return null;
        }

        $previousPending = (bool) ($previous['pending_reboot_effective'] ?? false);
        $previousSince = $previous['pending_reboot_since'] ?? null;

        if ($previousPending && is_string($previousSince) && trim($previousSince) !== '') {
            return $previousSince;
        }

        return gmdate(DATE_ATOM);
    }
}
