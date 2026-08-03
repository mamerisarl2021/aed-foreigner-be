<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\EnrollmentStatus;
use App\Http\Resources\Concerns\FormatsEnrollmentDocuments;
use App\Http\Resources\Concerns\MapsEnrollmentApplicantDetail;
use App\Models\EnrollmentRejectMotif;
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
        $inQueue = in_array($this->status, [
            EnrollmentStatus::ValidationAgent->value,
            EnrollmentStatus::RejetAgent->value,
        ], true);

        $base = [
            'id' => $this->id,
            'type' => $this->type,
            'statut' => $this->status,
            'date_soumission' => $this->created_at,
            'decision_agent' => $this->decisionAgentBlock(),
            'responsable' => $this->relationLoaded('assignedResponsable')
                ? $this->formatAgent($this->assignedResponsable)
                : null,
            'peut_prendre_en_charge' => $inQueue && $this->assigned_responsable_id === null,
            'peut_valider' => $inQueue
                && $this->assigned_responsable_id !== null
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
    private function decisionAgentBlock(): array
    {
        $agentRejected = match ($this->status) {
            EnrollmentStatus::RejetAgent->value, EnrollmentStatus::Rejetee->value => true,
            EnrollmentStatus::ValidationAgent->value, EnrollmentStatus::Approuvee->value => false,
            default => $this->reject_stage === 'AGENT' && ! empty($this->reject_reasons),
        };

        return [
            'statut' => $agentRejected ? 'Rejeté' : 'Approuvé',
            'agent' => $this->relationLoaded('assignedAgent')
                ? $this->formatAgent($this->assignedAgent)
                : null,
            'date' => $this->agent_decided_at ?? $this->updated_at,
            'motifs' => $agentRejected ? $this->resolveMotifs($this->reject_reasons) : [],
            'description' => $this->review_comments,
        ];
    }

    /**
     * @param  list<string>|null  $codes
     * @return list<array{code: string, label: string}>
     */
    private function resolveMotifs(?array $codes): array
    {
        if ($codes === null || $codes === []) {
            return [];
        }

        $motifs = EnrollmentRejectMotif::query()
            ->whereIn('code', $codes)
            ->get()
            ->keyBy('code');

        return array_values(array_map(
            fn (string $code) => [
                'code' => $code,
                'label' => $motifs->get($code)?->label_fr ?? $code,
            ],
            $codes
        ));
    }
}
