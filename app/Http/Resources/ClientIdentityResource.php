<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Identity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Identity
 */
class ClientIdentityResource extends JsonResource
{
    /**
     * @return array{id: mixed, type: mixed, statut: mixed, niveau: mixed, date: mixed}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'statut' => $this->status,
            'niveau' => $this->level,
            'date' => $this->date,
        ];
    }
}
