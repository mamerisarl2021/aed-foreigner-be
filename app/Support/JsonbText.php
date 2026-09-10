<?php

declare(strict_types=1);

namespace App\Support;

final class JsonbText
{
    /**
     * Postgres `->>'key'` / `#>>'{a,b}'` for an allowlisted JSON path (`$.a.b`).
     */
    public static function textExpression(string $column, string $dollarPath): ?string
    {
        if (! in_array($column, ['kyc_data', 'proof'], true)) {
            return null;
        }

        if (preg_match('/^\$(\.[A-Za-z_]+)+$/', $dollarPath) !== 1) {
            return null;
        }

        $keys = explode('.', substr($dollarPath, 2));
        $quoted = array_map(
            static fn (string $key): string => str_replace("'", "''", $key),
            $keys,
        );

        if (count($quoted) === 1) {
            return $column."->>'".$quoted[0]."'";
        }

        return $column."#>>'{".implode(',', $quoted)."}'";
    }

    public static function upperTrimEqualsSql(string $column, string $dollarPath): ?string
    {
        $expression = self::textExpression($column, $dollarPath);
        if ($expression === null) {
            return null;
        }

        return 'UPPER(TRIM('.$expression.')) = ?';
    }
}
