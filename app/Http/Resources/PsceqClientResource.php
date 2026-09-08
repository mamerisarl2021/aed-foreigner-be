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
            'prefixe' => $this->key_prefix,
            'revoque' => $this->isRevoked(),
            'revoque_le' => $this->revoked_at?->toIso8601String(),
            'dernier_usage_le' => $this->last_used_at?->toIso8601String(),
            'cree_le' => $this->created_at?->toIso8601String(),
        ];
    }
}
