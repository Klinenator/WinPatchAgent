<?php

declare(strict_types=1);

namespace PatchAgent\Api\Storage;

final class AdminUserRepository
{
    public const ROLE_ADMIN = 'admin';
    public const ROLE_TECHNICIAN = 'technician';
    private const FILE = 'admin_users.json';

    public function __construct(private readonly FileStore $store)
    {
    }

    public function listUsers(): array
    {
        $payload = $this->readPayload();
        $users = is_array($payload['users'] ?? null) ? $payload['users'] : [];

        $normalized = [];
        foreach ($users as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $user = $this->normalizeUserRecord($entry);
            if ($user !== null) {
                $normalized[] = $user;
            }
        }

        usort($normalized, static function (array $left, array $right): int {
            return strcmp((string) ($left['email'] ?? ''), (string) ($right['email'] ?? ''));
        });

        return $normalized;
    }

    public function findUser(string $email): ?array
    {
        $normalizedEmail = $this->normalizeEmail($email);
        if ($normalizedEmail === null) {
            return null;
        }

        $payload = $this->readPayload();
        $users = is_array($payload['users'] ?? null) ? $payload['users'] : [];
        $entry = is_array($users[$normalizedEmail] ?? null) ? $users[$normalizedEmail] : null;
        if ($entry === null) {
            return null;
        }

        return $this->normalizeUserRecord($entry);
    }

    public function isEmpty(): bool
    {
        $payload = $this->readPayload();
        $users = is_array($payload['users'] ?? null) ? $payload['users'] : [];
        foreach ($users as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            if ($this->normalizeUserRecord($entry) !== null) {
                return false;
            }
        }

        return true;
    }

    public function countActiveAdmins(): int
    {
        $count = 0;
        foreach ($this->listUsers() as $user) {
            if (($user['active'] ?? false) === true && (string) ($user['role'] ?? '') === self::ROLE_ADMIN) {
                $count += 1;
            }
        }

        return $count;
    }

    public function bootstrapAdminIfEmpty(string $email, string $name): ?array
    {
        if (!$this->isEmpty()) {
            return null;
        }

        return $this->upsertUser(
            $email,
            $name,
            self::ROLE_ADMIN,
            true,
            'bootstrap'
        );
    }

    public function upsertUser(
        string $email,
        string $name,
        string $role,
        bool $active,
        string $actorEmail
    ): array {
        $normalizedEmail = $this->normalizeEmail($email);
        if ($normalizedEmail === null) {
            throw new \RuntimeException('A valid user email is required.');
        }

        $normalizedRole = $this->normalizeRole($role);
        if ($normalizedRole === null) {
            throw new \RuntimeException('Role must be admin or technician.');
        }

        $safeActor = $this->normalizeEmail($actorEmail) ?? 'system';
        $payload = $this->readPayload();
        $users = is_array($payload['users'] ?? null) ? $payload['users'] : [];
        $existing = is_array($users[$normalizedEmail] ?? null) ? $users[$normalizedEmail] : [];

        $now = gmdate(DATE_ATOM);
        $normalizedName = $this->normalizeName($name);
        $record = [
            'email' => $normalizedEmail,
            'name' => $normalizedName !== '' ? $normalizedName : (string) ($existing['name'] ?? ''),
            'role' => $normalizedRole,
            'active' => $active,
            'created_at' => (string) ($existing['created_at'] ?? $now),
            'updated_at' => $now,
            'created_by' => (string) ($existing['created_by'] ?? $safeActor),
            'updated_by' => $safeActor,
        ];

        $users[$normalizedEmail] = $record;
        $payload['users'] = $users;
        $this->writePayload($payload);

        return $this->normalizeUserRecord($record) ?? $record;
    }

    public function deleteUser(string $email): bool
    {
        $normalizedEmail = $this->normalizeEmail($email);
        if ($normalizedEmail === null) {
            return false;
        }

        $payload = $this->readPayload();
        $users = is_array($payload['users'] ?? null) ? $payload['users'] : [];
        if (!isset($users[$normalizedEmail])) {
            return false;
        }

        unset($users[$normalizedEmail]);
        $payload['users'] = $users;
        $this->writePayload($payload);
        return true;
    }

    private function readPayload(): array
    {
        $payload = $this->store->readJson(self::FILE, ['users' => []]);
        if (!is_array($payload)) {
            return ['users' => []];
        }

        if (!is_array($payload['users'] ?? null)) {
            $payload['users'] = [];
        }

        return $payload;
    }

    private function writePayload(array $payload): void
    {
        $this->store->writeJson(self::FILE, $payload);
    }

    private function normalizeUserRecord(array $entry): ?array
    {
        $email = $this->normalizeEmail((string) ($entry['email'] ?? ''));
        if ($email === null) {
            return null;
        }

        $role = $this->normalizeRole((string) ($entry['role'] ?? self::ROLE_TECHNICIAN));
        if ($role === null) {
            return null;
        }

        return [
            'email' => $email,
            'name' => $this->normalizeName((string) ($entry['name'] ?? '')),
            'role' => $role,
            'active' => (bool) ($entry['active'] ?? true),
            'created_at' => $this->normalizeIso((string) ($entry['created_at'] ?? '')) ?? gmdate(DATE_ATOM),
            'updated_at' => $this->normalizeIso((string) ($entry['updated_at'] ?? '')) ?? gmdate(DATE_ATOM),
            'created_by' => $this->normalizeEmail((string) ($entry['created_by'] ?? '')) ?? 'system',
            'updated_by' => $this->normalizeEmail((string) ($entry['updated_by'] ?? '')) ?? 'system',
        ];
    }

    private function normalizeEmail(string $value): ?string
    {
        $email = strtolower(trim($value));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return $email;
    }

    private function normalizeRole(string $value): ?string
    {
        $role = strtolower(trim($value));
        if ($role === self::ROLE_ADMIN || $role === self::ROLE_TECHNICIAN) {
            return $role;
        }

        return null;
    }

    private function normalizeName(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        if (strlen($trimmed) > 120) {
            return substr($trimmed, 0, 120);
        }

        return $trimmed;
    }

    private function normalizeIso(string $value): ?string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($trimmed))->format(DATE_ATOM);
        } catch (\Throwable) {
            return null;
        }
    }
}

