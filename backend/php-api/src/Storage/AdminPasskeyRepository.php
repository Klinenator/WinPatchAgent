<?php

declare(strict_types=1);

namespace PatchAgent\Api\Storage;

final class AdminPasskeyRepository
{
    private const FILE = 'admin_passkeys.json';

    public function __construct(private readonly FileStore $store)
    {
    }

    public function listForUser(string $email): array
    {
        $normalizedEmail = $this->normalizeEmail($email);
        if ($normalizedEmail === null) {
            return [];
        }

        $payload = $this->store->readJson(self::FILE, ['users' => []]);
        $users = is_array($payload['users'] ?? null) ? $payload['users'] : [];
        $user = is_array($users[$normalizedEmail] ?? null) ? $users[$normalizedEmail] : [];
        $credentials = is_array($user['credentials'] ?? null) ? $user['credentials'] : [];

        $normalized = [];
        foreach ($credentials as $credential) {
            if (!is_array($credential)) {
                continue;
            }

            $item = $this->normalizeCredential($credential);
            if ($item !== null) {
                $normalized[] = $item;
            }
        }

        usort($normalized, static function (array $left, array $right): int {
            return strcmp((string) ($right['updated_at'] ?? ''), (string) ($left['updated_at'] ?? ''));
        });

        return $normalized;
    }

    public function countForUser(string $email): int
    {
        return count($this->listForUser($email));
    }

    public function findForUserByCredentialId(string $email, string $credentialId): ?array
    {
        $normalizedEmail = $this->normalizeEmail($email);
        $normalizedCredentialId = $this->normalizeCredentialId($credentialId);
        if ($normalizedEmail === null || $normalizedCredentialId === null) {
            return null;
        }

        foreach ($this->listForUser($normalizedEmail) as $credential) {
            if ((string) ($credential['credential_id'] ?? '') === $normalizedCredentialId) {
                return $credential;
            }
        }

        return null;
    }

    public function saveForUser(string $email, array $input): array
    {
        $normalizedEmail = $this->normalizeEmail($email);
        if ($normalizedEmail === null) {
            throw new \RuntimeException('A valid email is required to save a passkey.');
        }

        $credentialId = $this->normalizeCredentialId((string) ($input['credential_id'] ?? ''));
        if ($credentialId === null) {
            throw new \RuntimeException('A valid credential_id is required.');
        }

        $now = gmdate(DATE_ATOM);
        $payload = $this->store->readJson(self::FILE, ['users' => []]);
        $users = is_array($payload['users'] ?? null) ? $payload['users'] : [];
        $user = is_array($users[$normalizedEmail] ?? null) ? $users[$normalizedEmail] : [];
        $credentials = is_array($user['credentials'] ?? null) ? $user['credentials'] : [];

        $existing = null;
        $existingIndex = null;
        foreach ($credentials as $index => $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            if ((string) ($candidate['credential_id'] ?? '') === $credentialId) {
                $existing = $candidate;
                $existingIndex = $index;
                break;
            }
        }

        $normalized = [
            'credential_id' => $credentialId,
            'name' => $this->normalizeLabel((string) ($input['name'] ?? ($existing['name'] ?? 'Passkey'))),
            'public_key_pem' => $this->normalizePublicKeyPem((string) ($input['public_key_pem'] ?? ($existing['public_key_pem'] ?? ''))),
            'counter' => max(0, (int) ($input['counter'] ?? ($existing['counter'] ?? 0))),
            'transports' => $this->normalizeTransports(is_array($input['transports'] ?? null) ? $input['transports'] : ($existing['transports'] ?? [])),
            'created_at' => (string) ($existing['created_at'] ?? $now),
            'updated_at' => $now,
            'last_used_at' => $this->normalizeIsoOrNull((string) ($input['last_used_at'] ?? ($existing['last_used_at'] ?? ''))),
        ];

        if ($normalized['public_key_pem'] === '') {
            throw new \RuntimeException('A valid public key is required.');
        }

        if ($existingIndex === null) {
            $credentials[] = $normalized;
        } else {
            $credentials[$existingIndex] = $normalized;
        }

        $user['credentials'] = array_values(array_filter($credentials, static fn ($item): bool => is_array($item)));
        $users[$normalizedEmail] = $user;
        $payload['users'] = $users;
        $this->store->writeJson(self::FILE, $payload);

        return $normalized;
    }

    public function updateCounterAndLastUsed(string $email, string $credentialId, int $counter): ?array
    {
        $normalizedEmail = $this->normalizeEmail($email);
        $normalizedCredentialId = $this->normalizeCredentialId($credentialId);
        if ($normalizedEmail === null || $normalizedCredentialId === null) {
            return null;
        }

        $payload = $this->store->readJson(self::FILE, ['users' => []]);
        $users = is_array($payload['users'] ?? null) ? $payload['users'] : [];
        $user = is_array($users[$normalizedEmail] ?? null) ? $users[$normalizedEmail] : [];
        $credentials = is_array($user['credentials'] ?? null) ? $user['credentials'] : [];

        foreach ($credentials as $index => $credential) {
            if (!is_array($credential)) {
                continue;
            }

            if ((string) ($credential['credential_id'] ?? '') !== $normalizedCredentialId) {
                continue;
            }

            $credential['counter'] = max(0, $counter);
            $credential['last_used_at'] = gmdate(DATE_ATOM);
            $credential['updated_at'] = gmdate(DATE_ATOM);
            $credentials[$index] = $credential;

            $user['credentials'] = $credentials;
            $users[$normalizedEmail] = $user;
            $payload['users'] = $users;
            $this->store->writeJson(self::FILE, $payload);

            return $this->normalizeCredential($credential);
        }

        return null;
    }

    public function deleteForUser(string $email, string $credentialId): bool
    {
        $normalizedEmail = $this->normalizeEmail($email);
        $normalizedCredentialId = $this->normalizeCredentialId($credentialId);
        if ($normalizedEmail === null || $normalizedCredentialId === null) {
            return false;
        }

        $payload = $this->store->readJson(self::FILE, ['users' => []]);
        $users = is_array($payload['users'] ?? null) ? $payload['users'] : [];
        $user = is_array($users[$normalizedEmail] ?? null) ? $users[$normalizedEmail] : [];
        $credentials = is_array($user['credentials'] ?? null) ? $user['credentials'] : [];

        $deleted = false;
        $kept = [];
        foreach ($credentials as $credential) {
            if (!is_array($credential)) {
                continue;
            }

            if ((string) ($credential['credential_id'] ?? '') === $normalizedCredentialId) {
                $deleted = true;
                continue;
            }

            $kept[] = $credential;
        }

        if (!$deleted) {
            return false;
        }

        $user['credentials'] = $kept;
        $users[$normalizedEmail] = $user;
        $payload['users'] = $users;
        $this->store->writeJson(self::FILE, $payload);
        return true;
    }

    private function normalizeCredential(array $credential): ?array
    {
        $credentialId = $this->normalizeCredentialId((string) ($credential['credential_id'] ?? ''));
        $publicKeyPem = $this->normalizePublicKeyPem((string) ($credential['public_key_pem'] ?? ''));
        if ($credentialId === null || $publicKeyPem === '') {
            return null;
        }

        return [
            'credential_id' => $credentialId,
            'name' => $this->normalizeLabel((string) ($credential['name'] ?? 'Passkey')),
            'public_key_pem' => $publicKeyPem,
            'counter' => max(0, (int) ($credential['counter'] ?? 0)),
            'transports' => $this->normalizeTransports(is_array($credential['transports'] ?? null) ? $credential['transports'] : []),
            'created_at' => $this->normalizeIsoOrNull((string) ($credential['created_at'] ?? '')) ?? gmdate(DATE_ATOM),
            'updated_at' => $this->normalizeIsoOrNull((string) ($credential['updated_at'] ?? '')) ?? gmdate(DATE_ATOM),
            'last_used_at' => $this->normalizeIsoOrNull((string) ($credential['last_used_at'] ?? '')),
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

    private function normalizeCredentialId(string $value): ?string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/^[A-Za-z0-9_-]+$/', $trimmed) !== 1) {
            return null;
        }

        return $trimmed;
    }

    private function normalizeLabel(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return 'Passkey';
        }

        if (strlen($trimmed) > 80) {
            return substr($trimmed, 0, 80);
        }

        return $trimmed;
    }

    private function normalizePublicKeyPem(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        if (!function_exists('openssl_pkey_get_public')) {
            return $trimmed;
        }

        $key = openssl_pkey_get_public($trimmed);
        if ($key === false) {
            return '';
        }

        return $trimmed;
    }

    private function normalizeTransports(array $input): array
    {
        $allowed = [
            'usb' => true,
            'nfc' => true,
            'ble' => true,
            'internal' => true,
            'hybrid' => true,
        ];

        $result = [];
        foreach ($input as $entry) {
            if (!is_string($entry)) {
                continue;
            }

            $candidate = strtolower(trim($entry));
            if ($candidate === '' || !isset($allowed[$candidate])) {
                continue;
            }

            $result[$candidate] = true;
        }

        return array_values(array_keys($result));
    }

    private function normalizeIsoOrNull(string $value): ?string
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
