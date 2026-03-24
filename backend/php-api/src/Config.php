<?php

declare(strict_types=1);

namespace PatchAgent\Api;

use PatchAgent\Api\Support\Path;

final class Config
{
    public function __construct(
        public readonly string $storageRoot,
        public readonly string $enrollmentKey,
        public readonly string $adminKey,
        public readonly string $googleClientId,
        public readonly string $googleClientSecret,
        public readonly string $googleRedirectUri,
        public readonly string $googleHostedDomain,
        public readonly string $adminSessionName,
        public readonly int $adminSessionTtlSeconds,
        public readonly int $heartbeatSeconds,
        public readonly int $jobsSeconds,
        public readonly int $inventorySeconds
    ) {
    }

    public static function fromEnvironment(): self
    {
        $defaultRoot = Path::normalize(dirname(__DIR__) . '/storage/runtime');

        return new self(
            storageRoot: Path::normalize(self::env('PATCH_API_STORAGE_ROOT', $defaultRoot)),
            enrollmentKey: self::env('PATCH_API_ENROLLMENT_KEY', ''),
            adminKey: self::env('PATCH_API_ADMIN_KEY', ''),
            googleClientId: self::env('PATCH_API_GOOGLE_CLIENT_ID', ''),
            googleClientSecret: self::env('PATCH_API_GOOGLE_CLIENT_SECRET', ''),
            googleRedirectUri: self::env('PATCH_API_GOOGLE_REDIRECT_URI', ''),
            googleHostedDomain: self::env('PATCH_API_GOOGLE_HOSTED_DOMAIN', ''),
            adminSessionName: self::env('PATCH_API_ADMIN_SESSION_NAME', 'patchagent_admin'),
            adminSessionTtlSeconds: self::envInt('PATCH_API_ADMIN_SESSION_TTL_SECONDS', 28800),
            heartbeatSeconds: self::envInt('PATCH_API_HEARTBEAT_SECONDS', 300),
            jobsSeconds: self::envInt('PATCH_API_JOBS_SECONDS', 120),
            inventorySeconds: self::envInt('PATCH_API_INVENTORY_SECONDS', 21600)
        );
    }

    private static function env(string $key, string $default): string
    {
        $value = getenv($key);
        if ($value === false) {
            return $default;
        }

        $trimmed = trim((string) $value);
        return $trimmed === '' ? $default : $trimmed;
    }

    private static function envInt(string $key, int $default): int
    {
        $value = getenv($key);
        if ($value === false) {
            return $default;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_INT);
        return $parsed === false ? $default : (int) $parsed;
    }
}
