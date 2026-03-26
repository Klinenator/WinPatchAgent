#!/usr/bin/env php
<?php

declare(strict_types=1);

use PatchAgent\Api\Storage\MySqlDocumentStore;

require_once __DIR__ . '/../src/bootstrap.php';

function usage(): void
{
    $script = basename(__FILE__);
    fwrite(STDERR, <<<TXT
Usage:
  php backend/php-api/scripts/{$script} [--storage-root PATH] [--db-host HOST] [--db-port PORT] [--db-name NAME] [--db-user USER] [--db-password PASS] [--db-table TABLE]

Environment fallback:
  PATCH_API_STORAGE_ROOT
  PATCH_API_DB_HOST
  PATCH_API_DB_PORT
  PATCH_API_DB_NAME
  PATCH_API_DB_USER
  PATCH_API_DB_PASSWORD
  PATCH_API_DB_TABLE

TXT);
}

function parseOptions(array $argv): array
{
    $parsed = [];

    for ($index = 1; $index < count($argv); $index++) {
        $token = (string) $argv[$index];
        if ($token === '--help' || $token === '-h') {
            usage();
            exit(0);
        }

        if (!str_starts_with($token, '--')) {
            throw new RuntimeException(sprintf('Unexpected argument: %s', $token));
        }

        $pair = explode('=', substr($token, 2), 2);
        $key = trim((string) ($pair[0] ?? ''));
        if ($key === '') {
            throw new RuntimeException('Invalid option format.');
        }

        if (array_key_exists(1, $pair)) {
            $value = (string) $pair[1];
        } else {
            $next = $argv[$index + 1] ?? null;
            if (!is_string($next) || str_starts_with($next, '--')) {
                throw new RuntimeException(sprintf('Missing value for option --%s', $key));
            }
            $value = $next;
            $index++;
        }

        $parsed[$key] = $value;
    }

    return $parsed;
}

function envOrDefault(string $key, string $default): string
{
    $value = getenv($key);
    if ($value === false) {
        return $default;
    }

    $trimmed = trim((string) $value);
    return $trimmed === '' ? $default : $trimmed;
}

function promptHidden(string $label): string
{
    fwrite(STDOUT, $label);
    $sttyMode = shell_exec('stty -g');
    if (is_string($sttyMode)) {
        shell_exec('stty -echo');
    }

    $line = fgets(STDIN);

    if (is_string($sttyMode)) {
        shell_exec('stty ' . trim($sttyMode));
    }
    fwrite(STDOUT, PHP_EOL);

    return trim((string) $line);
}

try {
    $options = parseOptions($argv);

    $defaultRoot = realpath(__DIR__ . '/../storage/runtime');
    if (!is_string($defaultRoot) || $defaultRoot === '') {
        $defaultRoot = __DIR__ . '/../storage/runtime';
    }

    $storageRoot = trim((string) ($options['storage-root'] ?? envOrDefault('PATCH_API_STORAGE_ROOT', $defaultRoot)));
    $dbHost = trim((string) ($options['db-host'] ?? envOrDefault('PATCH_API_DB_HOST', '127.0.0.1')));
    $dbPortText = trim((string) ($options['db-port'] ?? envOrDefault('PATCH_API_DB_PORT', '3306')));
    $dbName = trim((string) ($options['db-name'] ?? envOrDefault('PATCH_API_DB_NAME', '')));
    $dbUser = trim((string) ($options['db-user'] ?? envOrDefault('PATCH_API_DB_USER', '')));
    $dbPassword = (string) ($options['db-password'] ?? envOrDefault('PATCH_API_DB_PASSWORD', ''));
    if ($dbPassword === '') {
        $dbPassword = promptHidden(sprintf('MySQL password for %s: ', $dbUser === '' ? 'user' : $dbUser));
    }
    $dbTable = trim((string) ($options['db-table'] ?? envOrDefault('PATCH_API_DB_TABLE', 'patchapi_documents')));

    if ($storageRoot === '') {
        throw new RuntimeException('Storage root is required.');
    }

    if (!is_dir($storageRoot)) {
        throw new RuntimeException(sprintf('Storage root does not exist: %s', $storageRoot));
    }

    if ($dbName === '' || $dbUser === '') {
        throw new RuntimeException('DB name and DB user are required (via args or env).');
    }

    $dbPort = filter_var($dbPortText, FILTER_VALIDATE_INT);
    if ($dbPort === false || $dbPort <= 0) {
        throw new RuntimeException('DB port must be a positive integer.');
    }

    $store = new MySqlDocumentStore(
        host: $dbHost,
        port: (int) $dbPort,
        database: $dbName,
        username: $dbUser,
        password: $dbPassword,
        tableName: $dbTable
    );

    $rootRealPath = realpath($storageRoot);
    if (!is_string($rootRealPath) || $rootRealPath === '') {
        throw new RuntimeException(sprintf('Unable to resolve storage root: %s', $storageRoot));
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($rootRealPath, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    $imported = 0;
    $skipped = 0;

    foreach ($iterator as $entry) {
        if (!$entry->isFile()) {
            continue;
        }

        $fullPath = $entry->getPathname();
        $relativePath = ltrim(str_replace('\\', '/', substr($fullPath, strlen($rootRealPath))), '/');
        if ($relativePath === '') {
            $skipped++;
            continue;
        }

        $content = file_get_contents($fullPath);
        if ($content === false) {
            fwrite(STDERR, sprintf("Skipping unreadable file: %s\n", $fullPath));
            $skipped++;
            continue;
        }

        $store->writeRaw($relativePath, $content);
        $imported++;
    }

    fwrite(STDOUT, sprintf("MySQL import complete. Imported: %d, Skipped: %d\n", $imported, $skipped));
    exit(0);
} catch (\Throwable $exception) {
    fwrite(STDERR, 'Migration failed: ' . $exception->getMessage() . PHP_EOL);
    usage();
    exit(1);
}
