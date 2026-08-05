<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

trait MergesRouteId
{
    protected function prepareForValidation(): void
    {
        $id = $this->route('id');
        if ($id !== null) {
            $this->merge(['id' => $id]);
        }
    }
}
