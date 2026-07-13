<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IdentityResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'type' => $this->type,
            'level' => $this->level,
            'status' => $this->status,
            'date' => $this->date,
            'risk_score' => $this->risk_score,
            'analysis_details' => $this->analysis_details ? json_decode($this->analysis_details, true) : null,
            'assigned_agent_id' => $this->assigned_agent_id,
            'reject_stage' => $this->reject_stage,
            'reject_reasons' => $this->reject_reasons ? json_decode($this->reject_reasons, true) : null,
            'review_comments' => $this->review_comments,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            // Proof URLs and Raw
            'proof' => $this->proof ? json_decode($this->proof, true) : null,
            'selfieUrl' => $this->selfieUrl,
            'rectoUrl' => $this->rectoUrl,
            'versoUrl' => $this->versoUrl,

            // Relationships
            'user' => $this->whenLoaded('user'),
            'structure' => $this->structure ?? null,
        ];
    }
}
