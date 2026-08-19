<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

final class CloudTemporaryUrl
{
    /**
     * @param  array<string, mixed>  $options
     */
    public static function make(?string $path, array $options = []): string
    {
        if ($path === null || $path === '') {
            return '';
        }

        return Storage::cloud()->temporaryUrl($path, Carbon::now()->addDays(3), $options);
    }
}
