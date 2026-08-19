<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiFormRequest;

class ListAgentsRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:255'],
            'role' => ['nullable', 'string', 'in:AGENT,RESPONSABLE_DE_VALIDATION,MANAGER'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'order_by' => ['nullable', 'string', 'in:created_at,name,email,last_login_at'],
            'order_dir' => ['nullable', 'string', 'in:asc,desc'],
        ];
    }
}
