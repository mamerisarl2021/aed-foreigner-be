<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ActivityLog
 */
class PsceqClientHistoriqueResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'date' => $this->created_at?->toIso8601String(),
            'evenement' => $this->action instanceof \BackedEnum ? $this->action->value : $this->action,
            'details' => $this->description,
        ];
    }
}
