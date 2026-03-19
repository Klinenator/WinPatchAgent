<?php

declare(strict_types=1);

namespace PatchAgent\Api\Http;

final class JsonResponse
{
    public static function ok(array $payload): void
    {
        self::send(200, $payload);
    }

    public static function error(int $statusCode, string $errorCode, string $message, array $extra = []): void
    {
        self::send($statusCode, array_merge([
            'error' => [
                'code' => $errorCode,
                'message' => $message,
            ],
        ], $extra));
    }

    private static function send(int $statusCode, array $payload): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
