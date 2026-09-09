<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PsceqClient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PsceqClient
 */
class PsceqClientResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nom' => $this->name,
            'raison_sociale' => $this->raison_sociale,
            'rccm' => $this->rccm,
            'pays' => $this->pays,
            'adresse_siege' => $this->adresse_siege,
            'site_web' => $this->site_web,
            'email' => $this->email,
            'telephone' => $this->telephone,
            'point_focal_nom' => $this->point_focal_nom,
            'point_focal_prenom' => $this->point_focal_prenom,
            'point_focal_fonction' => $this->point_focal_fonction,
            'point_focal_email' => $this->point_focal_email,
            'point_focal_telephone' => $this->point_focal_telephone,
            'prefixe' => $this->key_prefix,
            'revoque' => $this->isRevoked(),
            'revoque_le' => $this->revoked_at?->toIso8601String(),
            'dernier_usage_le' => $this->last_used_at?->toIso8601String(),
            'cree_le' => $this->created_at?->toIso8601String(),
            'administrateur' => $this->whenLoaded('createdBy', fn () => $this->createdBy === null ? null : [
                'nom' => $this->createdBy->nom,
                'prenom' => $this->createdBy->prenom,
            ]),
        ];
    }
}
