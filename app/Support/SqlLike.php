<?php

declare(strict_types=1);

namespace App\Support;

final class SqlLike
{
    /**
     * Bound LIKE/ILIKE pattern that matches a substring without treating
     * user `%`, `_`, or `\` as wildcards.
     */
    public static function contains(string $value): string
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);

        return '%'.$escaped.'%';
    }
}
