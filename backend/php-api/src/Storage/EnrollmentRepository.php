<?php

declare(strict_types=1);

namespace PatchAgent\Api\Storage;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class EnrollmentRepository
{
    private const FILE = 'enrollments.json';

    public function __construct(private readonly FileStore $store)
    {
    }

    public function createEnrollment(string $platform, int $ttlSeconds): array
    {
        $records = $this->store->readJson(self::FILE, ['enrollments' => []]);
        $plainKey = $this->newEnrollmentKey();
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $expiresAt = $now->modify(sprintf('+%d seconds', max(1, $ttlSeconds)))->format(DATE_ATOM);

        $record = [
            'enrollment_id' => $this->newId('enr'),
            'platform' => $platform,
            'key_hash' => hash('sha256', $plainKey),
            'created_at' => $now->format(DATE_ATOM),
            'updated_at' => $now->format(DATE_ATOM),
            'expires_at' => $expiresAt,
            'used_at' => null,
            'used_by_device_id' => null,
        ];

        $records['enrollments'][] = $record;
        $this->store->writeJson(self::FILE, $records);

        return [
            'enrollment_id' => (string) $record['enrollment_id'],
            'platform' => (string) $record['platform'],
            'enrollment_key' => $plainKey,
            'created_at' => (string) $record['created_at'],
            'expires_at' => (string) $record['expires_at'],
            'used_at' => null,
            'used_by_device_id' => null,
        ];
    }

    public function isEnrollmentKeyActive(string $key): bool
    {
        $records = $this->store->readJson(self::FILE, ['enrollments' => []]);
        $keyHash = hash('sha256', $key);

        foreach ($records['enrollments'] as $record) {
            if (($record['key_hash'] ?? '') !== $keyHash) {
                continue;
            }

            if ($this->isExpired($record['expires_at'] ?? null)) {
                return false;
            }

            return true;
        }

        return false;
    }

    public function consumeEnrollmentKey(string $key, string $deviceId): bool
    {
        $records = $this->store->readJson(self::FILE, ['enrollments' => []]);
        $keyHash = hash('sha256', $key);

        foreach ($records['enrollments'] as $index => $record) {
            if (($record['key_hash'] ?? '') !== $keyHash) {
                continue;
            }

            if ($this->isExpired($record['expires_at'] ?? null)) {
                return false;
            }

            $usedBy = (string) ($record['used_by_device_id'] ?? '');
            if ($usedBy !== '' && !hash_equals($usedBy, $deviceId)) {
                return false;
            }

            if ($usedBy === '') {
                $record['used_by_device_id'] = $deviceId;
                $record['used_at'] = gmdate(DATE_ATOM);
                $record['updated_at'] = gmdate(DATE_ATOM);
                $records['enrollments'][$index] = $record;
                $this->store->writeJson(self::FILE, $records);
            }

            return true;
        }

        return false;
    }

    private function isExpired(mixed $expiresAt): bool
    {
        if (!is_string($expiresAt) || trim($expiresAt) === '') {
            return false;
        }

        try {
            $expiry = new DateTimeImmutable($expiresAt);
        } catch (Throwable) {
            return true;
        }

        return $expiry <= new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private function newId(string $prefix): string
    {
        return sprintf('%s_%s', $prefix, bin2hex(random_bytes(10)));
    }

    private function newEnrollmentKey(): string
    {
        return sprintf('enrkey_%s', bin2hex(random_bytes(20)));
    }
}
