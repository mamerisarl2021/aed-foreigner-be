<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ActivityLogDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $actor = $this->actor;

        return [
            'id' => $this->id,
            'action' => $this->action_code,
            'description' => $this->description,
            'date' => $this->created_at,
            'actor' => $actor ? [
                'id' => $actor->id,
                'name' => $actor->name,
                'first_name' => $actor->first_name,
                'email' => $actor->email,
            ] : null,
            'enrollment_request_id' => $this->enrollment_request_id,
            'metadata' => $this->metadata,
        ];
    }
}
