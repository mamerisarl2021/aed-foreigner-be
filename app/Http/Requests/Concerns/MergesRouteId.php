<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

/**
 * Merges route `{id}` into the request for Form Request validation (§9.5).
 *
 * Scramble documents path params from the route + controller `#[PathParameter]`.
 * Put `#[IgnoreParam('id')]` on the Form Request so Scramble does not also expose
 * `id` as a query/body field.
 */
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
