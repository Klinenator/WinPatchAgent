<?php

declare(strict_types=1);

namespace PatchAgent\Api\Storage;

final class EventRepository
{
    public function __construct(private readonly FileStore $store)
    {
    }

    public function appendBatch(string $agentRecordId, string $deviceId, array $events): int
    {
        $accepted = 0;

        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }

            $this->store->appendLine(sprintf('events/%s.ndjson', $agentRecordId), [
                'recorded_at' => gmdate(DATE_ATOM),
                'agent_record_id' => $agentRecordId,
                'device_id' => $deviceId,
                'event' => $event,
            ]);
            $accepted++;
        }

        return $accepted;
    }
}
