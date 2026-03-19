<?php

declare(strict_types=1);

namespace PatchAgent\Api\Storage;

use PatchAgent\Api\Support\Json;

final class FileStore
{
    public function __construct(private readonly string $root)
    {
        if (!is_dir($this->root)) {
            mkdir($this->root, 0775, true);
        }
    }

    public function readJson(string $relativePath, array $default = []): array
    {
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
