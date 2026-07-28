<?php

declare(strict_types=1);

namespace App\Http\Requests\Enrollment;

use App\Http\Requests\ApiFormRequest;

class ListRejectMotifsRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Défaut: true (seuls les motifs actifs sont retournés).
            'active_only' => ['nullable', 'boolean'],
            'stage' => ['nullable', 'string', 'in:KYC,DOCUMENT,BIOMETRY,COMPANY,REPRESENTATIVE,OTHER'],
        ];
    }
}
