<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\EnrolledCompany;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EnrolledCompany
 */
class PsceqCompanyStatutResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'identifiant' => $this->identifiant,
            'statut' => $this->statusValue(),
            'existe' => true,
        ];
    }
}
