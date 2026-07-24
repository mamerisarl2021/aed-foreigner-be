<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Support\StaffRoleMapper;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StaffUserDetailResource extends JsonResource
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
            'telephone' => $this->phonenumber,
            'role' => StaffRoleMapper::codeFromUser($this->resource),
            'statut' => $this->status,
            'must_change_password' => (bool) $this->must_change_password,
            'derniere_connexion' => $this->last_login_at,
        ];
    }
}
