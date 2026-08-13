<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsEnrollmentDocuments;
use App\Http\Resources\Concerns\MapsEnrollmentApplicantDetail;
use App\Models\EnrollmentRequest;
use App\Support\EnrollmentStatusPresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Client owner view — tracking, company fields, pièces jointes.
 *
 * @mixin EnrollmentRequest
 */
class MoraleEnrollmentOwnerResource extends JsonResource
{
    use FormatsEnrollmentDocuments;
    use MapsEnrollmentApplicantDetail;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $enrollment = $this->resource;
        if (! $enrollment instanceof EnrollmentRequest) {
            return [];
        }

        return array_merge([
            'id' => $enrollment->id,
            'numero_suivi' => $enrollment->tracking_code,
            'identifiant' => $enrollment->enrolledCompany?->identifiant,
            'statut' => $enrollment->status->value,
            'statut_libelle' => EnrollmentStatusPresenter::labelFor($enrollment->statut(), $request->user()),
            'type' => $enrollment->type,
            'email_verifie' => $enrollment->email_verified_at !== null,
            'telephone_verifie' => $enrollment->phone_verified_at !== null,
            'verification_deadline_at' => $enrollment->verification_deadline_at,
            'correction_deadline_at' => $enrollment->correction_deadline_at,
            'raison_sociale' => is_array($enrollment->kyc_data) ? ($enrollment->kyc_data['legal_name'] ?? null) : null,
            'pieces_jointes' => $this->moralePiecesJointes($enrollment->documents),
        ], $this->moraleDetail());
    }
}
