<?php

declare(strict_types=1);

namespace PatchAgent\Api\Support;

final class Path
{
    public static function normalize(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}
