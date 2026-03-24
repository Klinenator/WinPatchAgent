<?php

declare(strict_types=1);

namespace PatchAgent\Api\Http;

use PatchAgent\Api\ApiException;
use PatchAgent\Api\Support\Json;

final class Request
{
    private ?array $decodedJson = null;

    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query,
        private readonly string $baseUrl,
        private readonly array $headers,
        private readonly string $rawBody
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string) parse_url($uri, PHP_URL_PATH);
        $query = is_array($_GET ?? null) ? $_GET : [];
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $rawBody = file_get_contents('php://input') ?: '';

        $forwardedProto = (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
        $scheme = strtolower(trim(explode(',', $forwardedProto)[0]));
        if ($scheme !== 'http' && $scheme !== 'https') {
            $https = (string) ($_SERVER['HTTPS'] ?? 'off');
            $scheme = strtolower($https) !== 'off' && $https !== '' ? 'https' : 'http';
        }

        $forwardedHost = (string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? '');
        $host = trim(explode(',', $forwardedHost)[0]);
        if ($host === '') {
            $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        }

        $baseUrl = sprintf('%s://%s', $scheme, $host);

        return new self($method, $path, $query, $baseUrl, $headers, $rawBody);
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function queryParam(string $name): ?string
    {
        $value = $this->query[$name] ?? null;
        if (!is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);
        return $text === '' ? null : $text;
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function json(): array
    {
        if ($this->decodedJson !== null) {
            return $this->decodedJson;
        }

        if ($this->rawBody === '') {
            $this->decodedJson = [];
            return $this->decodedJson;
        }

        $decoded = Json::decodeObject($this->rawBody);
        if (!is_array($decoded)) {
            throw new ApiException(400, 'invalid_json', 'Request body must decode to a JSON object.');
        }

        $this->decodedJson = $decoded;
        return $this->decodedJson;
    }

    public function bearerToken(): ?string
    {
        $header = $this->header('Authorization');
        if ($header === null) {
            return null;
        }

        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            return null;
        }

        return trim($matches[1]);
    }

    private function header(string $name): ?string
    {
        foreach ($this->headers as $headerName => $value) {
            if (strcasecmp((string) $headerName, $name) === 0) {
                return is_array($value) ? (string) reset($value) : (string) $value;
            }
        }

        return null;
    }
}
