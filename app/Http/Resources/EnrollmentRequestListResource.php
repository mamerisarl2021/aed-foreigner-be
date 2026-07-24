<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsEnrollmentDocuments;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Agent list row — physique or morale (table columns only). */
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
        ];
    }

    /**
     * @return array{nom: ?string, prenom: ?string}
     */
    private function demandeurForList(): array
    {
        if ($this->isPersonneMorale()) {
            $submitter = $this->relationLoaded('submittedBy') ? $this->submittedBy : null;

            return [
                'nom' => $submitter?->name,
                'prenom' => $submitter?->first_name,
            ];
        }

        $kyc = $this->kyc_data ?? [];

        return [
            'nom' => $kyc['name'] ?? null,
            'prenom' => $kyc['first_name'] ?? null,
        ];
    }
}
