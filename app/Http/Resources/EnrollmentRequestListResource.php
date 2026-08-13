<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsEnrollmentDocuments;
use App\Models\EnrollmentRequest;
use App\Support\EnrollmentStatusPresenter;
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
            'pays_origine' => $moraleCols['pays_origine'],
            'demandeur' => $this->formatDemandeur(
                $this->isPersonneMorale(),
                $kyc,
                $this->relationLoaded('submittedBy') ? $this->submittedBy : null,
            ),
            'date_soumission' => $this->created_at,
            'statut' => $this->status->value,
            // Le statut machine dit où est la demande ; le libellé dit ce que
            // *ce* lecteur doit en comprendre — un agent ne lit jamais
            // « approuvée » sur un dossier passé au responsable.
            'statut_libelle' => EnrollmentStatusPresenter::labelFor($this->statut(), $request->user()),
            'agent_responsable' => $this->relationLoaded('assignedAgent')
                ? $this->formatAgent($this->assignedAgent)
                : null,
            'pris_en_charge_par_moi' => $this->assigned_agent_id !== null
                && (string) $this->assigned_agent_id === (string) $request->user()?->id,
            // Avis rendu au niveau agent : c'est l'acte de ce niveau, pas le sort
            // de la demande, qui reste suspendu à la décision du responsable.
            'avis_agent' => $this->agent_avis?->value,
            // Date de la décision de l'agent : c'est elle qui date un enrôlement
            // dans les listes, `created_at` ne datant que la soumission.
            'date_decision' => $this->agent_decided_at,
            // Un retour du responsable remet la demande en EN_ATTENTE_AGENT : sans cet
            // horodatage, rien ne la distingue d'une demande jamais instruite.
            'retournee_le' => $this->returned_at,
        ];
    }
}
