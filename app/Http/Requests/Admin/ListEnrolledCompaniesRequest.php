<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

class ListEnrolledCompaniesRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Recherche sur la raison sociale, l'email, le numéro d'immatriculation ou l'identifiant.
            'q' => ['nullable', 'string', 'max:255'],
            // Défaut: 15. Maximum: 100.
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            // Défaut: enrolled_at. Mêmes clés que /admin/enrolled-persons, aux colonnes près.
            'order_by' => ['nullable', 'string', 'in:enrolled_at,legal_name,email'],
            // Défaut: desc.
            'order_dir' => ['nullable', 'string', 'in:asc,desc'],
        ];
    }
}
