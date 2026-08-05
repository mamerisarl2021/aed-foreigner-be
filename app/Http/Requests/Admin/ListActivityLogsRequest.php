<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\ActivityLogAction;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class ListActivityLogsRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Recherche sur la description ou le libellé d'action.
            'q' => ['nullable', 'string', 'max:255'],
            'action' => ['nullable', 'string', Rule::in(array_column(ActivityLogAction::cases(), 'value'))],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            // Défaut: 20. Maximum: 100.
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
