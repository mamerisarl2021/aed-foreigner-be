<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

/**
 * Merges route `{filename}` into the request for Form Request validation (§9.5).
 *
 * Put `#[IgnoreParam('filename')]` on the Form Request and `#[PathParameter('filename')]`
 * on the controller action so Scramble documents a single path parameter.
 */
trait MergesRouteFilename
{
    protected function prepareForValidation(): void
    {
        $filename = $this->route('filename');
        if ($filename !== null) {
            $this->merge(['filename' => basename((string) $filename)]);
        }
    }
}
