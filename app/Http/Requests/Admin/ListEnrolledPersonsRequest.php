<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

class ListEnrolledPersonsRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Recherche sur nom, prénom, email ou NPI.
            'q' => ['nullable', 'string', 'max:255'],
            // Défaut: 15. Maximum: 100.
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            // Défaut: enrolled_at.
            'order_by' => ['nullable', 'string', 'in:enrolled_at,name,email'],
            // Défaut: desc.
            'order_dir' => ['nullable', 'string', 'in:asc,desc'],
        ];
    }
}
