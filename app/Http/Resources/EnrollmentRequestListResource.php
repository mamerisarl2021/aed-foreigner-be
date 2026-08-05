<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsEnrollmentDocuments;
use App\Models\EnrollmentRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Agent list row — physique or morale (table columns only).
 *
 * @mixin EnrollmentRequest
 */
class EnrollmentRequestListResource extends JsonResource
{
    use FormatsEnrollmentDocuments;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed>|null $kyc */
        $kyc = $this->kyc_data;

        return [
            'id' => $this->id,
            'type' => $this->type,
            'demandeur' => $this->formatDemandeur(
                $this->isPersonneMorale(),
                $kyc,
                $this->relationLoaded('submittedBy') ? $this->submittedBy : null,
            ),
            'date_soumission' => $this->created_at,
            'statut' => $this->status,
            'agent_responsable' => $this->relationLoaded('assignedAgent')
                ? $this->formatAgent($this->assignedAgent)
                : null,
            // Date de la décision de l'agent : c'est elle qui date un enrôlement
            // dans les listes, `created_at` ne datant que la soumission.
            'date_decision' => $this->agent_decided_at,
            // Un retour du responsable remet la demande en EN_ATTENTE : sans cet
            // horodatage, rien ne la distingue d'une demande jamais instruite.
            'retournee_le' => $this->returned_at,
        ];
    }
}
