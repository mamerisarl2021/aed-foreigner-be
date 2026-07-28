<?php

declare(strict_types=1);

namespace App\Http\Requests\Enrollment;

use App\Enums\EnrollmentStatus;
use App\Http\Requests\ApiFormRequest;

class ListEnrollmentRequestsRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Single status or several separated by "|" (e.g. VALIDATION_AGENT|REJET_AGENT).
        $statuses = implode('|', EnrollmentStatus::reviewable());
        $statusRegex = "regex:/^({$statuses})(\\|({$statuses}))*$/";

        return [
            // Statut(s) à filtrer: EN_ATTENTE, VALIDATION_AGENT, REJET_AGENT, APPROUVEE, REJETEE, ENROLEE. Plusieurs valeurs séparées par "|". Défaut: EN_ATTENTE (agent) ou VALIDATION_AGENT|REJET_AGENT (responsable).
            'statut' => ['nullable', 'string', $statusRegex],
            // Alias anglais de "statut". Mêmes valeurs.
            'status' => ['nullable', 'string', $statusRegex],
            'type' => ['nullable', 'string', 'in:PERSONNE_PHYSIQUE,PERSONNE_MORALE,personne_physique,personne_morale'],
            'q' => ['nullable', 'string', 'max:255'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            // Défaut: 15. Maximum: 100.
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            // Défaut: created_at.
            'order_by' => ['nullable', 'string', 'in:created_at,id'],
            // Défaut: desc.
            'order_dir' => ['nullable', 'string', 'in:asc,desc'],
        ];
    }
}
