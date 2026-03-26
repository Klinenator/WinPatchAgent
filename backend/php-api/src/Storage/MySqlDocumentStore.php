<?php

declare(strict_types=1);

namespace PatchAgent\Api\Storage;

use PatchAgent\Api\Support\Json;
use PDO;
use PDOException;

final class MySqlDocumentStore
{
    private PDO $pdo;
    private string $tableName;

    public function __construct(
        string $host,
        int $port,
        string $database,
        string $username,
        string $password,
        string $tableName = 'patchapi_documents'
    ) {
        $dbHost = trim($host);
        $dbName = trim($database);
        $dbUser = trim($username);
        $safeTable = trim($tableName);

        if ($dbHost === '' || $dbName === '' || $dbUser === '') {
            throw new \RuntimeException('MySQL storage requires host, database name, and username.');
        }

        if (!preg_match('/^[A-Za-z0-9_]+$/', $safeTable)) {
            throw new \RuntimeException('PATCH_API_DB_TABLE may contain only letters, numbers, and underscore.');
        }

        $this->tableName = $safeTable;

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $dbHost,
            max(1, $port),
            $dbName
        );

        try {
            $this->pdo = new PDO($dsn, $dbUser, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $exception) {
            throw new \RuntimeException(
                'Failed to connect to MySQL document store: ' . $exception->getMessage(),
                0,
                $exception
            );
        }

        $this->ensureSchema();
    }

    public function readJson(string $relativePath, array $default = []): array
    {
        $content = $this->readRaw($relativePath);
        if ($content === null || trim($content) === '') {
            return $default;
        }

        $decoded = Json::decodeObject($content);
        return is_array($decoded) ? $decoded : $default;
    }

    public function writeJson(string $relativePath, array $data): void
    {
        $this->writeRaw($relativePath, Json::encode($data));
    }

    public function writeRaw(string $relativePath, string $content): void
    {
        $sql = sprintf(
            'INSERT INTO `%s` (`path`, `content`, `created_at`, `updated_at`)
             VALUES (:path, :content, CURRENT_TIMESTAMP(6), CURRENT_TIMESTAMP(6))
             ON DUPLICATE KEY UPDATE `content` = VALUES(`content`), `updated_at` = CURRENT_TIMESTAMP(6)',
            $this->tableName
        );

        $statement = $this->pdo->prepare($sql);
        $statement->execute([
            ':path' => $this->normalizePath($relativePath),
            ':content' => $content,
        ]);
    }

    public function appendLine(string $relativePath, array $data): void
    {
        $line = Json::encode($data) . PHP_EOL;
        $sql = sprintf(
            'INSERT INTO `%s` (`path`, `content`, `created_at`, `updated_at`)
             VALUES (:path, :line, CURRENT_TIMESTAMP(6), CURRENT_TIMESTAMP(6))
             ON DUPLICATE KEY UPDATE `content` = CONCAT(`content`, VALUES(`content`)), `updated_at` = CURRENT_TIMESTAMP(6)',
            $this->tableName
        );

        $statement = $this->pdo->prepare($sql);
        $statement->execute([
            ':path' => $this->normalizePath($relativePath),
            ':line' => $line,
        ]);
    }

    public function exists(string $relativePath): bool
    {
        $sql = sprintf(
            'SELECT 1 FROM `%s` WHERE `path` = :path LIMIT 1',
            $this->tableName
        );
        $statement = $this->pdo->prepare($sql);
        $statement->execute([':path' => $this->normalizePath($relativePath)]);
        return $statement->fetchColumn() !== false;
    }

    public function readRaw(string $relativePath): ?string
    {
        $sql = sprintf(
            'SELECT `content` FROM `%s` WHERE `path` = :path LIMIT 1',
            $this->tableName
        );
        $statement = $this->pdo->prepare($sql);
        $statement->execute([':path' => $this->normalizePath($relativePath)]);
        $value = $statement->fetchColumn();
        return is_string($value) ? $value : null;
    }

    private function ensureSchema(): void
    {
        $sql = sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `path` VARCHAR(255) NOT NULL,
                `content` LONGTEXT NOT NULL,
                `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
                PRIMARY KEY (`path`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            $this->tableName
        );

        $this->pdo->exec($sql);
    }

    private function normalizePath(string $relativePath): string
    {
        $path = ltrim(str_replace('\\', '/', trim($relativePath)), '/');
        if ($path === '') {
            throw new \RuntimeException('Storage path must not be empty.');
        }

        if (strlen($path) > 255) {
            throw new \RuntimeException('Storage path is too long for MySQL document key.');
        }

        return $path;
    }
}
