<?php

declare(strict_types=1);

namespace PatchAgent\Api\Storage;

use PatchAgent\Api\Config;
use PatchAgent\Api\Support\Json;

final class FileStore
{
    public static function fromConfig(Config $config): self
    {
        $driver = strtolower(trim($config->dbDriver));
        if ($driver !== 'mysql') {
            return new self($config->storageRoot, null);
        }

        $mysql = new MySqlDocumentStore(
            host: $config->dbHost,
            port: $config->dbPort,
            database: $config->dbName,
            username: $config->dbUser,
            password: $config->dbPassword,
            tableName: $config->dbTable
        );

        return new self($config->storageRoot, $mysql);
    }

    public function __construct(
        private readonly string $root,
        private readonly ?MySqlDocumentStore $mysqlStore = null
    )
    {
        if ($this->mysqlStore !== null) {
            return;
        }

        if (!is_dir($this->root)) {
            mkdir($this->root, 0775, true);
        }
    }

    public function readJson(string $relativePath, array $default = []): array
    {
        if ($this->mysqlStore !== null) {
            return $this->mysqlStore->readJson($relativePath, $default);
        }

        $path = $this->path($relativePath);
        if (!is_file($path)) {
            return $default;
        }

        $contents = file_get_contents($path);
        if ($contents === false || trim($contents) === '') {
            return $default;
        }

        $decoded = Json::decodeObject($contents);
        return is_array($decoded) ? $decoded : $default;
    }

    public function writeJson(string $relativePath, array $data): void
    {
        if ($this->mysqlStore !== null) {
            $this->mysqlStore->writeJson($relativePath, $data);
            return;
        }

        $path = $this->path($relativePath);
        $this->ensureParentDirectory($path);

        $handle = fopen($path, 'c+');
        if ($handle === false) {
            throw new \RuntimeException(sprintf('Unable to open %s for writing.', $path));
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException(sprintf('Unable to lock %s.', $path));
            }

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, Json::encode($data));
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function appendLine(string $relativePath, array $data): void
    {
        if ($this->mysqlStore !== null) {
            $this->mysqlStore->appendLine($relativePath, $data);
            return;
        }

        $path = $this->path($relativePath);
        $this->ensureParentDirectory($path);

        $handle = fopen($path, 'ab');
        if ($handle === false) {
            throw new \RuntimeException(sprintf('Unable to open %s for append.', $path));
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException(sprintf('Unable to lock %s.', $path));
            }

            fwrite($handle, Json::encode($data) . PHP_EOL);
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function exists(string $relativePath): bool
    {
        if ($this->mysqlStore !== null) {
            return $this->mysqlStore->exists($relativePath);
        }

        return is_file($this->path($relativePath));
    }

    private function path(string $relativePath): string
    {
        return $this->root . '/' . ltrim($relativePath, '/');
    }

    private function ensureParentDirectory(string $path): void
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
    }
}
