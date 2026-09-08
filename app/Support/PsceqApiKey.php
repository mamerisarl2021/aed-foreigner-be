<?php

declare(strict_types=1);

namespace App\Support;

final class PsceqApiKey
{
    public const PREFIX = 'psceq_';

    public const PREFIX_LENGTH = 14;

    public static function generate(): string
    {
        return self::PREFIX.bin2hex(random_bytes(32));
    }

    public static function prefixOf(string $plain): string
    {
        return substr($plain, 0, self::PREFIX_LENGTH);
    }

    public static function looksLike(string $plain): bool
    {
        return str_starts_with($plain, self::PREFIX) && strlen($plain) > self::PREFIX_LENGTH;
    }
}
