<?php

declare(strict_types=1);

namespace App\Http\Requests\Enrollment;

use App\Enums\EnrollmentStatus;
use App\Http\Requests\ApiFormRequest;

class ListManagerEnrollmentRequestsRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $statuses = implode('|', EnrollmentStatus::listable());
        $statusRegex = "regex:/^({$statuses})(\\|({$statuses}))*$/";

        return [
            // Statut(s) à filtrer. Plusieurs valeurs séparées par "|". Défaut: tous les statuts listables.
            'statut' => ['nullable', 'string', $statusRegex],
            // Alias anglais de "statut". Mêmes valeurs.
            'status' => ['nullable', 'string', $statusRegex],
            'q' => ['nullable', 'string', 'max:255'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            // Défaut: 20. Maximum: 100.
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            // Défaut: created_at.
            'order_by' => ['nullable', 'string', 'in:created_at,id'],
            // Défaut: desc.
            'order_dir' => ['nullable', 'string', 'in:asc,desc'],
        ];
    }
}
