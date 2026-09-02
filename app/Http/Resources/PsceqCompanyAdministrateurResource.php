<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\EnrolledCompany;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EnrolledCompany
 */
class PsceqCompanyAdministrateurResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'identifiant' => $this->identifiant,
            'nom' => $this->legal_representative_name,
            'prenoms' => $this->legal_representative_first_name,
        ];
    }
}
