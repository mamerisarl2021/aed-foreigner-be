<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\UploadedFile;

final class ValidatedUpload
{
    /**
     * @param  array<string, mixed>  $validated
     */
    public static function file(array $validated, string $key): ?UploadedFile
    {
        $value = $validated[$key] ?? null;

        return $value instanceof UploadedFile ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  list<string>  $keys
     * @return array<string, UploadedFile>
     */
    public static function files(array $validated, array $keys): array
    {
        $files = [];
        foreach ($keys as $key) {
            $file = self::file($validated, $key);
            if ($file !== null) {
                $files[$key] = $file;
            }
        }

        return $files;
    }
}
