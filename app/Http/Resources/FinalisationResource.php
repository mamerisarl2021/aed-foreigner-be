<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Public finalisation payloads — eligibility, OTP, and accepted-for-processing. */
class FinalisationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payload = is_array($this->resource) ? $this->resource : [];

        return array_filter([
            'demande_id' => $payload['demande_id'] ?? null,
            'numero_suivi' => $payload['numero_suivi'] ?? null,
            'statut' => $payload['statut'] ?? null,
            'email' => $payload['email'] ?? null,
            'npi' => $payload['npi'] ?? null,
            'otp_verified' => $payload['otp_verified'] ?? null,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
