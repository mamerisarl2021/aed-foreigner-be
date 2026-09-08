<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\EnrolledCompany;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EnrolledCompany
 */
class PsceqCompanyListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'identifiant' => $this->identifiant,
            'raison_sociale' => $this->legal_name,
            'pays_origine' => $this->country_of_incorporation,
            'statut' => $this->statusValue(),
        ];
    }
}
