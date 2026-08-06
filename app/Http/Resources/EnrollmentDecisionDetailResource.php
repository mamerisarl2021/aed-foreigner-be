<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\AgentAvis;
use App\Enums\EnrollmentStatus;
use App\Http\Resources\Concerns\FormatsEnrollmentDocuments;
use App\Http\Resources\Concerns\MapsEnrollmentApplicantDetail;
use App\Models\EnrollmentRejectMotif;
use App\Models\EnrollmentRequest;
use App\Support\EnrollmentStatusPresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Responsable detail view — agent decision card + applicant/enterprise fields. */
class EnrollmentDecisionDetailResource extends JsonResource
{
    use FormatsEnrollmentDocuments;
    use MapsEnrollmentApplicantDetail;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var EnrollmentRequest $enrollment */
        $enrollment = $this->resource;

        $base = [
            'id' => $this->id,
            'type' => $this->type,
            'statut' => $this->status,
            // Au niveau responsable, un dossier non pris en charge est « À valider » :
            // l'avis de l'agent est une proposition, il ne préjuge de rien ici.
            'statut_libelle' => EnrollmentStatusPresenter::labelFor($enrollment->statut(), $request->user()),
            'date_soumission' => $this->created_at,
            'decision_agent' => $this->decisionAgentBlock($enrollment),
            'responsable' => $this->relationLoaded('assignedResponsable')
                ? $this->formatAgent($this->assignedResponsable)
                : null,
            'peut_prendre_en_charge' => $this->status === EnrollmentStatus::EnAttenteResponsable->value
                && $this->assigned_responsable_id === null,
            'peut_valider' => $this->status === EnrollmentStatus::EnCoursResponsable->value
                && (string) $this->assigned_responsable_id === (string) $request->user()?->id,
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

    /**
     * @return array<string, mixed>
     */
    private function decisionAgentBlock(EnrollmentRequest $enrollment): array
    {
        // `null` quand l'agent n'a pas encore rendu d'avis — l'ancien bloc
        // retombait sur « Approuvé » par défaut et annonçait donc une décision
        // que personne n'avait prise.
        $avis = $enrollment->avisAgent();

        return [
            'avis' => $avis?->value,
            'avis_libelle' => $avis?->label(),
            'agent' => $this->relationLoaded('assignedAgent')
                ? $this->formatAgent($this->assignedAgent)
                : null,
            'date' => $enrollment->agent_decided_at,
            'motifs' => $avis === AgentAvis::Defavorable ? $this->resolveMotifs($this->reject_reasons) : [],
            'description' => $this->review_comments,
        ];
    }

    /**
     * @param  list<string>|null  $ids
     * @return list<array{id: string, title: string, description: string}>
     */
    private function resolveMotifs(?array $ids): array
    {
        if ($ids === null || $ids === []) {
            return [];
        }

        $ids = array_values(array_map(fn ($id) => (string) $id, $ids));

        $motifs = EnrollmentRejectMotif::query()
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        return array_values(array_map(
            fn (string $id) => [
                'id' => $id,
                'title' => $motifs->get($id)?->title ?? $id,
                'description' => $motifs->get($id)?->description ?? '',
            ],
            $ids
        ));
    }
}
