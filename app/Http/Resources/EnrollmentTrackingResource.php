<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\EnrollmentStatus;
use App\Models\EnrollmentRequest;
use App\Support\EnrollmentMotifs;
use App\Support\EnrollmentStatusPresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Guest tracking view — statut demandeur only; no KYC analysis, avis, or documents.
 *
 * @mixin EnrollmentRequest
 */
class EnrollmentTrackingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $enrollment = $this->resource;
        if (! $enrollment instanceof EnrollmentRequest) {
            return [];
        }

        $kyc = is_array($enrollment->kyc_data) ? $enrollment->kyc_data : [];
        $isMorale = $enrollment->isPersonneMorale();
        $submitter = $enrollment->submittedBy;

        $payload = [
            'id' => $enrollment->id,
            'numero_suivi' => $enrollment->tracking_code,
            'type' => $enrollment->type,
            'statut' => $enrollment->status->value,
            'statut_libelle' => EnrollmentStatusPresenter::label(
                $enrollment->statut(),
                EnrollmentStatusPresenter::PERSPECTIVE_DEMANDEUR,
            ),
            'date_soumission' => $enrollment->created_at,
            'finalisation_disponible' => ! $isMorale && $enrollment->status === EnrollmentStatus::Approuvee,
            'identifiant' => $enrollment->enrolledCompany?->identifiant,
            'correction_deadline_at' => $enrollment->correction_deadline_at,
            'motifs' => $this->applicantMotifs($enrollment),
            'raison_sociale' => $isMorale ? ($kyc['legal_name'] ?? null) : null,
            'demandeur' => [
                'nom' => $isMorale
                    ? ($submitter !== null ? $submitter->name : ($kyc['legal_representative_name'] ?? null))
                    : ($kyc['name'] ?? null),
                'prenom' => $isMorale
                    ? ($submitter !== null ? $submitter->first_name : ($kyc['legal_representative_first_name'] ?? null))
                    : ($kyc['first_name'] ?? null),
            ],
            'email_verifie' => $enrollment->isEmailVerified(),
            'telephone_verifie' => $enrollment->isPhoneVerified(),
        ];

        return $payload;
    }

    /**
     * @return list<array{id: string, title: string, description: string}>|null
     */
    private function applicantMotifs(EnrollmentRequest $enrollment): ?array
    {
        if (! in_array($enrollment->status, [EnrollmentStatus::ACorriger, EnrollmentStatus::Rejetee], true)) {
            return null;
        }

        return EnrollmentMotifs::resolve($enrollment->reject_reasons);
    }
}
