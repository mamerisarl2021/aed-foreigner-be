<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Payload after PUT /enrolements/morales/{id} — demande back in the agent queue. */
class MoraleEnrollmentCorrectionResource extends JsonResource
{
    /**
     * @return array{demande_id: mixed, numero_suivi: mixed, statut: mixed}
     */
    public function toArray(Request $request): array
    {
        $data = $this->resource;
        if (! is_array($data)) {
            return [
                'demande_id' => null,
                'numero_suivi' => null,
                'statut' => null,
            ];
        }

        return [
            'demande_id' => $data['demande_id'] ?? null,
            'numero_suivi' => $data['numero_suivi'] ?? null,
            'statut' => $data['statut'] ?? null,
        ];
    }
}
