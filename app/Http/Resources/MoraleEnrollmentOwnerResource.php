<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Client owner view after POST /enrolements/morales — tracking only. */
class MoraleEnrollmentOwnerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'statut' => $this->status,
            'type' => $this->type,
            'email_verifie' => $this->email_verified_at !== null,
            'telephone_verifie' => $this->phone_verified_at !== null,
            'verification_deadline_at' => $this->verification_deadline_at,
            'raison_sociale' => $this->kyc_data['legal_name'] ?? null,
        ];
    }
}
