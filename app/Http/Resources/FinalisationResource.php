<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\DataTransferObjects\FinalisationPayload;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public finalisation payloads — eligibility, OTP, and accepted-for-processing.
 *
 * @mixin FinalisationPayload
 */
class FinalisationResource extends JsonResource
{
    public function __construct(mixed $resource)
    {
        parent::__construct(FinalisationPayload::from($resource));
    }

    /**
     * @return array<string, string|bool>
     */
    public function toArray(Request $request): array
    {
        /** @var FinalisationPayload $payload */
        $payload = $this->resource;

        return $payload->toArray();
    }
}
