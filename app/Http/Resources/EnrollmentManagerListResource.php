<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsEnrollmentDocuments;
use App\Models\EnrollmentRequest;
use App\Support\EnrollmentStatusPresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Manager list row — Demandes PP / PM (read-only supervision).
 *
 * @mixin EnrollmentRequest
 */
class EnrollmentManagerListResource extends JsonResource
{
    use FormatsEnrollmentDocuments;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed>|null $kyc */
        $kyc = $this->kyc_data;
        $moraleCols = $this->formatMoraleListColumns(
            $this->isPersonneMorale(),
            $kyc,
            $this->tracking_code,
        );

        return [
            'id' => $this->id,
            'type' => $this->type,
            'numero_suivi' => $moraleCols['numero_suivi'],
            'raison_sociale' => $moraleCols['raison_sociale'],
            'demandeur' => $this->formatDemandeur(
                $this->isPersonneMorale(),
                $kyc,
                $this->relationLoaded('submittedBy') ? $this->submittedBy : null,
            ),
            'date_soumission' => $this->created_at,
            'statut' => $this->status->value,
            'statut_libelle' => EnrollmentStatusPresenter::labelFor($this->statut(), $request->user()),
            'agent' => $this->relationLoaded('assignedAgent')
                ? $this->formatAgent($this->assignedAgent)
                : null,
            'responsable' => $this->relationLoaded('assignedResponsable')
                ? $this->formatAgent($this->assignedResponsable)
                : null,
            'delai_ecoule_jours' => $this->delaiEcouleJours($this->created_at),
        ];
    }
}
