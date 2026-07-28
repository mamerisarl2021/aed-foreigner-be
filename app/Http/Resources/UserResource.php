<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'npi' => $this->npi,
            'name' => $this->name,
            'first_name' => $this->first_name,
            'sexe' => $this->sexe,
            'nationality' => $this->nationality,
            'email' => $this->email,
            'phonenumber' => $this->phonenumber,
            'status' => $this->status,
            'link' => $this->link,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
