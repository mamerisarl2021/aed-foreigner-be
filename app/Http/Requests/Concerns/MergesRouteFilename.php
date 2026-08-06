<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

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
