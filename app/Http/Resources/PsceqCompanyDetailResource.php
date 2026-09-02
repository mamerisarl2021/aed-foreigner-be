<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\EnrolledCompany;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EnrolledCompany
 */
class PsceqCompanyDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'identifiant' => $this->identifiant,
            'raison_sociale' => $this->legal_name,
            'forme_juridique' => $this->legal_form,
            'pays_origine' => $this->country_of_incorporation,
            'numero_immatriculation' => $this->registration_number,
            'date_creation' => $this->incorporation_date?->toDateString(),
            'adresse_siege_social' => $this->headquarters_address,
            'secteur_activite' => $this->activity_sector,
            'statut' => $this->statusValue(),
            'date_enrolement' => $this->approved_at->toIso8601String(),
        ];
    }
}
