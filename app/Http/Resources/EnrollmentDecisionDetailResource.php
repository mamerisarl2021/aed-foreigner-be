<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\AgentAvis;
use App\Enums\EnrollmentStatus;
use App\Http\Resources\Concerns\FormatsEnrollmentDocuments;
use App\Http\Resources\Concerns\MapsEnrollmentApplicantDetail;
use App\Http\Resources\Concerns\MapsEnrollmentKycAnalysis;
use App\Models\EnrollmentRejectMotif;
use App\Models\EnrollmentRequest;
use App\Support\EnrollmentStatusPresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Responsable detail view — agent decision card + applicant/enterprise fields.
 *
 * @mixin EnrollmentRequest
 */
class EnrollmentDecisionDetailResource extends JsonResource
{
    use FormatsEnrollmentDocuments;
    use MapsEnrollmentApplicantDetail;
    use MapsEnrollmentKycAnalysis;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var EnrollmentRequest $enrollment */
        $enrollment = $this->resource;

        $base = [
            'id' => $this->id,
            'numero_suivi' => $this->tracking_code,
            'identifiant' => $this->enrolledCompany?->identifiant,
            'type' => $this->type,
            'statut' => $this->status->value,
            // Au niveau responsable, un dossier non pris en charge est « À valider » :
            // l'avis de l'agent est une proposition, il ne préjuge de rien ici.
            'statut_libelle' => EnrollmentStatusPresenter::labelFor($enrollment->statut(), $request->user()),
            'date_soumission' => $this->created_at,
            'decision_agent' => $this->decisionAgentBlock($enrollment),
            'responsable' => $this->relationLoaded('assignedResponsable')
                ? $this->formatAgent($this->assignedResponsable)
                : null,
            'peut_prendre_en_charge' => $this->status === EnrollmentStatus::EnAttenteResponsable
                && $this->assigned_responsable_id === null,
            'peut_valider' => $this->status === EnrollmentStatus::EnCoursResponsable
                && (string) $this->assigned_responsable_id === (string) $request->user()?->id,
            // Vérification croisée (PDF §5.1) : déjà calculée au show, exposée ici.
            'similar_enrollments' => $this->similarEnrollments($enrollment),
        ];

        if ($this->isPersonneMorale()) {
            return array_merge($base, $this->moraleDetail(), [
                'pieces_jointes' => $this->moralePiecesJointes($this->documents),
                'analyse_kyc' => $this->analyseKycMorale($enrollment),
            ]);
        }

        return array_merge($base, $this->physiqueDetail(), [
            'pieces_jointes' => $this->physiquePiecesJointes($this->documents),
            'analyse_kyc' => $this->analyseKycPhysique($enrollment),
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

        $normalized = [];
        foreach ($ids as $id) {
            $normalized[] = (string) $id;
        }

        $motifs = EnrollmentRejectMotif::query()
            ->whereIn('id', $normalized)
            ->get()
            ->keyBy('id');

        $resolved = [];
        foreach ($normalized as $id) {
            $motif = $motifs->get($id);
            $resolved[] = [
                'id' => $id,
                'title' => $motif !== null ? $motif->title : $id,
                'description' => $motif !== null ? $motif->description : '',
            ];
        }

        return $resolved;
    }
}
