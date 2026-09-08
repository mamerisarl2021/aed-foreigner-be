<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Alimentée par `EnrolledPersonService::show()`, dont la jointure sur
 * `enrollment_requests` ajoute trois alias qui n'existent pas sur `users`.
 *
 * @mixin User
 *
 * @property-read string|null $enrollment_id
 * @property-read string|null $enrolled_at
 */
class EnrolledPersonDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $kyc = $this->enrollment_kyc ?? [];
        if (is_string($kyc)) {
            $kyc = json_decode($kyc, true) ?? [];
        }

        return [
            'id' => $this->id,
            'nom' => $this->name,
            'prenom' => $this->first_name,
            'email' => $this->email,
            'npi' => $this->npi,
            'telephone' => $this->phonenumber,
            'nationalite' => $this->nationality,
            'date_enrolement' => $this->enrolled_at,
            'demande_id' => $this->enrollment_id,
            'informations' => [
                'sexe' => $kyc['sexe'] ?? $kyc['sex'] ?? null,
                'date_naissance' => $kyc['date_of_birth'] ?? null,
                'lieu_naissance' => $kyc['place_of_birth'] ?? null,
                'pays_residence' => $kyc['country_of_residence'] ?? null,
                'type_piece' => $kyc['document_type'] ?? null,
                'numero_piece' => $kyc['document_number'] ?? null,
            ],
        ];
    }
}
