<?php

declare(strict_types=1);

namespace App\Http\Requests\Enrollment;

use App\Http\Requests\ApiFormRequest;

class ListEnrollmentRequestsRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'statut' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
            'type' => ['nullable', 'string', 'in:PERSONNE_PHYSIQUE,PERSONNE_MORALE,personne_physique,personne_morale'],
            'q' => ['nullable', 'string', 'max:255'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'order_by' => ['nullable', 'string', 'in:id,created_at'],
            'order_dir' => ['nullable', 'string', 'in:asc,desc'],
        ];
    }
}
