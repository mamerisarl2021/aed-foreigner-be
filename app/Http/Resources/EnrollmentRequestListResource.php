<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsEnrollmentDocuments;
use App\Models\EnrollmentRequest;
use App\Models\User;
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
        return [
            'id' => $this->id,
            'type' => $this->type,
            'demandeur' => $this->demandeurForList(),
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

    /**
     * @return array{nom: ?string, prenom: ?string}
     */
    private function demandeurForList(): array
    {
        if ($this->isPersonneMorale()) {
            /** @var User|null $submitter */
            $submitter = $this->relationLoaded('submittedBy') ? $this->submittedBy : null;

            return [
                'nom' => $submitter?->name,
                'prenom' => $submitter?->first_name,
            ];
        }

        /** @var array<string, mixed> $kyc */
        $kyc = $this->kyc_data ?? [];

        return [
            'nom' => $kyc['name'] ?? null,
            'prenom' => $kyc['first_name'] ?? null,
        ];
    }
}
