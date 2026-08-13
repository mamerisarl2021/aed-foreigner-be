<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\EnrollmentRequest;
use App\Support\EnrollmentStatusPresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Owner list row for GET /enrolements/morales — « Mes entreprises ».
 *
 * @mixin EnrollmentRequest
 */
class MoraleEnrollmentOwnerListResource extends JsonResource
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

        return [
            'id' => $enrollment->id,
            'numero_suivi' => $enrollment->tracking_code,
            'identifiant' => $enrollment->enrolledCompany?->identifiant,
            'statut' => $enrollment->status->value,
            'statut_libelle' => EnrollmentStatusPresenter::labelFor($enrollment->statut(), $request->user()),
            'raison_sociale' => $kyc['legal_name'] ?? null,
            'forme_juridique' => $kyc['legal_form'] ?? null,
            'pays_origine' => $kyc['country_of_incorporation'] ?? null,
            'date_creation' => $kyc['incorporation_date'] ?? null,
            'email_verifie' => $enrollment->email_verified_at !== null,
            'telephone_verifie' => $enrollment->phone_verified_at !== null,
        ];
    }
}
