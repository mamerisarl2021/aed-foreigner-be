<?php

declare(strict_types=1);

namespace App\Http\Requests\Enrollment;

use App\Enums\AgentAvis;
use App\Enums\EnrollmentStatus;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class ListEnrollmentRequestsRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Single status or several separated by "|" (e.g. EN_ATTENTE_AGENT|EN_COURS_AGENT).
        $statuses = implode('|', EnrollmentStatus::listable());
        $statusRegex = "regex:/^({$statuses})(\\|({$statuses}))*$/";

        return [
            // Statut(s) à filtrer: EN_ATTENTE_AGENT, EN_COURS_AGENT, EN_ATTENTE_RESPONSABLE, EN_COURS_RESPONSABLE, APPROUVEE, REJETEE, ENROLEE. Plusieurs valeurs séparées par "|". Défaut: EN_ATTENTE_AGENT|EN_COURS_AGENT (agent) ou EN_ATTENTE_RESPONSABLE|EN_COURS_RESPONSABLE (responsable).
            'statut' => ['nullable', 'string', $statusRegex],
            // Alias anglais de "statut". Mêmes valeurs.
            'status' => ['nullable', 'string', $statusRegex],
            // Avis rendu par l'agent de traitement. Sépare, dans la file du responsable, les dossiers proposés à l'approbation de ceux proposés au rejet.
            'avis' => ['nullable', 'string', Rule::in(AgentAvis::values())],
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
