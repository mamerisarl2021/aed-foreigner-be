<?php

declare(strict_types=1);

namespace App\Http\Requests\Enrollment;

use App\Http\Requests\ApiFormRequest;

class ListRejectMotifsRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'active_only' => ['nullable', 'boolean'],
            'stage' => ['nullable', 'string', 'max:50'],
        ];
    }
}
