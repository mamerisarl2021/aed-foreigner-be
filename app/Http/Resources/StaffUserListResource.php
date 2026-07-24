<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Support\StaffRoleMapper;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StaffUserListResource extends JsonResource
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
            'role' => StaffRoleMapper::codeFromUser($this->resource),
            'derniere_connexion' => $this->last_login_at,
        ];
    }
}
