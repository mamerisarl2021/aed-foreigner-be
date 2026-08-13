<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use App\Support\ClientLocalCredentials;
use App\Support\StaffRoleMapper;
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
        /** @var User $user */
        $user = $this->resource;

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
            'role' => StaffRoleMapper::codeFromUser($user),
            'link' => $this->link,
            'created_at' => $this->created_at?->toIso8601String(),
            'identites' => $this->relationLoaded('identities')
                ? ClientIdentityResource::collection($this->identities)
                : [],
            'questions_secretes_configurees' => ClientLocalCredentials::securityQuestionsConfigured($user),
            'pin_configure' => ClientLocalCredentials::pinIsStored($user),
        ];
    }
}
