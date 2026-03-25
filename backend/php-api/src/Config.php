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
        public readonly string $adminTotpSecret,
        public readonly string $adminTotpIssuer,
        public readonly int $adminTotpWindow,
        public readonly int $adminTotpChallengeTtlSeconds,
        public readonly string $windowsSplashtopMsiUrl,
        public readonly string $windowsSplashtopDeploymentCode,
        public readonly string $windowsAgentPackageUrl,
        public readonly bool $linuxCveLookupEnabled,
        public readonly int $linuxCveCacheTtlSeconds,
        public readonly int $linuxCveMaxPackageLookups,
        public readonly int $linuxCveMaxVulnsPerPackage,
        public readonly int $heartbeatSeconds,
        public readonly int $jobsSeconds,
        public readonly int $inventorySeconds
    ) {
    }

    public static function fromEnvironment(): self
    {
        $defaultRoot = Path::normalize(dirname(__DIR__) . '/storage/runtime');
        $legacyConfig = self::legacyPhpConfig(
            self::env('PATCH_API_LEGACY_CONFIG_FILE', '/var/lib/php/config.php')
        );

        return new self(
            storageRoot: Path::normalize(self::env('PATCH_API_STORAGE_ROOT', $defaultRoot)),
            enrollmentKey: self::env('PATCH_API_ENROLLMENT_KEY', ''),
            adminKey: self::env('PATCH_API_ADMIN_KEY', ''),
            googleClientId: self::env('PATCH_API_GOOGLE_CLIENT_ID', $legacyConfig['google_client_id'] ?? ''),
            googleClientSecret: self::env('PATCH_API_GOOGLE_CLIENT_SECRET', $legacyConfig['google_client_secret'] ?? ''),
            googleRedirectUri: self::env('PATCH_API_GOOGLE_REDIRECT_URI', $legacyConfig['google_redirect_uri'] ?? ''),
            googleHostedDomain: self::env('PATCH_API_GOOGLE_HOSTED_DOMAIN', $legacyConfig['google_hosted_domain'] ?? ''),
            adminSessionName: self::env('PATCH_API_ADMIN_SESSION_NAME', 'patchagent_admin'),
            adminSessionTtlSeconds: self::envInt('PATCH_API_ADMIN_SESSION_TTL_SECONDS', 28800),
            adminTotpSecret: self::env('PATCH_API_ADMIN_TOTP_SECRET', ''),
            adminTotpIssuer: self::env('PATCH_API_ADMIN_TOTP_ISSUER', 'PatchAgent Admin'),
            adminTotpWindow: max(0, self::envInt('PATCH_API_ADMIN_TOTP_WINDOW', 1)),
            adminTotpChallengeTtlSeconds: max(60, self::envInt('PATCH_API_ADMIN_TOTP_CHALLENGE_TTL_SECONDS', 300)),
            windowsSplashtopMsiUrl: self::env('PATCH_API_WINDOWS_SPLASHTOP_MSI_URL', ''),
            windowsSplashtopDeploymentCode: self::env('PATCH_API_WINDOWS_SPLASHTOP_DEPLOY_CODE', ''),
            windowsAgentPackageUrl: self::env(
                'PATCH_API_WINDOWS_AGENT_PACKAGE_URL',
                'https://github.com/Klinenator/WinPatchAgent/releases/latest/download/winpatchagent-windows-x64.zip'
            ),
            linuxCveLookupEnabled: self::envBool('PATCH_API_LINUX_CVE_LOOKUP_ENABLED', true),
            linuxCveCacheTtlSeconds: max(300, self::envInt('PATCH_API_LINUX_CVE_CACHE_TTL_SECONDS', 21600)),
            linuxCveMaxPackageLookups: max(1, self::envInt('PATCH_API_LINUX_CVE_MAX_PACKAGE_LOOKUPS', 25)),
            linuxCveMaxVulnsPerPackage: max(1, self::envInt('PATCH_API_LINUX_CVE_MAX_VULNS_PER_PACKAGE', 25)),
            heartbeatSeconds: self::envInt('PATCH_API_HEARTBEAT_SECONDS', 300),
            jobsSeconds: self::envInt('PATCH_API_JOBS_SECONDS', 120),
            inventorySeconds: self::envInt('PATCH_API_INVENTORY_SECONDS', 21600)
        );
    }

    private static function legacyPhpConfig(string $filePath): array
    {
        static $cache = [];

        $path = trim($filePath);
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            return [];
        }

        if (array_key_exists($path, $cache)) {
            return $cache[$path];
        }

        $clientID = null;
        $clientSecret = null;
        $redirectUri = null;
        $hostedDomain = null;
        $googleClientId = null;
        $googleClientSecret = null;
        $googleRedirectUri = null;
        $googleHostedDomain = null;

        try {
            include $path;
        } catch (\Throwable) {
            $cache[$path] = [];
            return [];
        }

        $result = [];

        $resolvedClientId = self::readLegacyString($googleClientId)
            ?? self::readLegacyString($clientID)
            ?? self::readLegacyConstant('GOOGLE_CLIENT_ID')
            ?? self::readLegacyConstant('CLIENT_ID');
        if ($resolvedClientId !== null) {
            $result['google_client_id'] = $resolvedClientId;
        }

        $resolvedClientSecret = self::readLegacyString($googleClientSecret)
            ?? self::readLegacyString($clientSecret)
            ?? self::readLegacyConstant('GOOGLE_CLIENT_SECRET')
            ?? self::readLegacyConstant('CLIENT_SECRET');
        if ($resolvedClientSecret !== null) {
            $result['google_client_secret'] = $resolvedClientSecret;
        }

        $resolvedRedirectUri = self::readLegacyString($googleRedirectUri)
            ?? self::readLegacyString($redirectUri)
            ?? self::readLegacyConstant('GOOGLE_REDIRECT_URI')
            ?? self::readLegacyConstant('REDIRECT_URI');
        if ($resolvedRedirectUri !== null) {
            $result['google_redirect_uri'] = $resolvedRedirectUri;
        }

        $resolvedHostedDomain = self::readLegacyString($googleHostedDomain)
            ?? self::readLegacyString($hostedDomain)
            ?? self::readLegacyConstant('GOOGLE_HOSTED_DOMAIN')
            ?? self::readLegacyConstant('HOSTED_DOMAIN');
        if ($resolvedHostedDomain !== null) {
            $result['google_hosted_domain'] = $resolvedHostedDomain;
        }

        $cache[$path] = $result;
        return $result;
    }

    private static function readLegacyConstant(string $name): ?string
    {
        if (!defined($name)) {
            return null;
        }

        return self::readLegacyString(constant($name));
    }

    private static function readLegacyString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
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

    private static function envBool(string $key, bool $default): bool
    {
        $value = getenv($key);
        if ($value === false) {
            return $default;
        }

        $normalized = strtolower(trim((string) $value));
        if ($normalized === '') {
            return $default;
        }

        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }

        if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        return $default;
    }
}
