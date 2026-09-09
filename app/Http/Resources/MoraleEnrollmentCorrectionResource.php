<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\DataTransferObjects\MoraleEnrollmentCorrectionPayload;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Payload after PUT /enrolements/morales/{id} — demande back in the agent queue.
 *
 * @mixin MoraleEnrollmentCorrectionPayload
 */
class MoraleEnrollmentCorrectionResource extends JsonResource
{
    public function __construct(mixed $resource)
    {
        parent::__construct(MoraleEnrollmentCorrectionPayload::from($resource));
    }

    /**
     * @return array{demande_id: string|null, numero_suivi: string|null, statut: string|null}
     */
    public function toArray(Request $request): array
    {
        /** @var MoraleEnrollmentCorrectionPayload $payload */
        $payload = $this->resource;

        return $payload->toArray();
    }
}
