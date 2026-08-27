<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\EnrolledCompany;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Ligne de liste d'une entreprise enrôlée.
 *
 * Pendant de {@see EnrolledPersonListResource} : `identifiant` y tient le rôle
 * du `npi`, et `date_enrolement` celui de la date d'enrôlement — l'entreprise
 * naît de l'approbation, d'où `approved_at`.
 *
 * @mixin EnrolledCompany
 */
class EnrolledCompanyListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'raison_sociale' => $this->legal_name,
            'email' => $this->company_email,
            'identifiant' => $this->identifiant,
            'pays_origine' => $this->country_of_incorporation,
            'date_enrolement' => $this->approved_at,
        ];
    }
}
