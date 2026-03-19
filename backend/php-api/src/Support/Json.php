<?php

declare(strict_types=1);

namespace PatchAgent\Api\Support;

use PatchAgent\Api\ApiException;

final class Json
{
    public static function decodeObject(string $json): mixed
    {
        try {
            return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ApiException(400, 'invalid_json', 'The request body contains invalid JSON.');
        }
    }

    public static function encode(array $data): string
    {
        try {
            return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('Unable to encode JSON payload.', 0, $exception);
        }
    }
}
