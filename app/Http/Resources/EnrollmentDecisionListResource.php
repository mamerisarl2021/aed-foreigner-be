<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsEnrollmentDocuments;
use App\Models\EnrollmentRequest;
use App\Support\EnrollmentStatusPresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Responsable list row — Décisions des agents columns.
 *
 * @mixin EnrollmentRequest
 */
class EnrollmentDecisionListResource extends JsonResource
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
            // Le responsable consulte aussi les personnes enrôlées : sans le
            // demandeur, cet écran n'aurait personne à nommer.
            'demandeur' => $this->formatDemandeur(
                $this->isPersonneMorale(),
                $kyc,
                $this->relationLoaded('submittedBy') ? $this->submittedBy : null,
            ),
            'date_soumission' => $this->created_at,
            'agent' => $this->relationLoaded('assignedAgent')
                ? $this->formatAgent($this->assignedAgent)
                : null,
            'date_decision' => $this->agent_decided_at ?? $this->updated_at,
            'statut' => $this->status,
            // « À valider » tant que le responsable n'a rien fait : le sens de
            // l'avis de l'agent vit dans sa propre colonne, jamais dans le statut.
            'statut_libelle' => EnrollmentStatusPresenter::labelFor($this->statut(), $request->user()),
            'avis_agent' => $this->agent_avis,
            'avis_agent_libelle' => $this->avisAgent()?->label(),
            'responsable' => $this->relationLoaded('assignedResponsable')
                ? $this->formatAgent($this->assignedResponsable)
                : null,
            'pris_en_charge_par_moi' => $this->assigned_responsable_id !== null
                && (string) $this->assigned_responsable_id === (string) $request->user()?->id,
        ];
    }
}
