<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EnrolledPersonListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nom' => $this->name,
            'prenom' => $this->first_name,
            'email' => $this->email,
            'npi' => $this->npi,
            'date_enrolement' => $this->enrolled_at ?? $this->last_login_at,
        ];
    }
}
