<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\EnrollmentStatus;
use App\Http\Resources\Concerns\FormatsEnrollmentDocuments;
use App\Http\Resources\Concerns\MapsEnrollmentApplicantDetail;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Agent detail view — fields aligned with backoffice UI per type. */
class EnrollmentRequestAgentDetailResource extends JsonResource
{
    use FormatsEnrollmentDocuments;
    use MapsEnrollmentApplicantDetail;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $base = [
            'id' => $this->id,
            // Code de suivi communiqué au demandeur : c'est par lui qu'on
            // désigne un dossier, l'UUID ne circulant qu'entre machines.
            'numero_suivi' => $this->tracking_code,
            'type' => $this->type,
            'statut' => $this->status,
            'date_soumission' => $this->created_at,
            'agent_responsable' => $this->relationLoaded('assignedAgent')
                ? $this->formatAgent($this->assignedAgent)
                : null,
            'peut_prendre_en_charge' => $this->status === EnrollmentStatus::EnAttente->value
                && $this->assigned_agent_id === null,
            'peut_instruire' => $this->status === EnrollmentStatus::EnAttente->value
                && $this->assigned_agent_id !== null
                && (string) $this->assigned_agent_id === (string) $request->user()?->id,
        ];

        if ($this->isPersonneMorale()) {
            return array_merge($base, $this->moraleDetail(), [
                'pieces_jointes' => $this->moralePiecesJointes($this->documents),
            ]);
        }

        return array_merge($base, $this->physiqueDetail(), [
            'pieces_jointes' => $this->physiquePiecesJointes($this->documents),
            'analyse_kyc' => [
                'liveness' => $this->liveness,
                'similarity' => $this->similarity,
                'risk_score' => $this->risk_score,
                'details' => $this->analysis_details,
            ],
        ]);
    }
}
