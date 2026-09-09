<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\DataTransferObjects\EnrollmentSubmitPayload;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EnrollmentSubmitPayload
 */
class EnrollmentSubmitResource extends JsonResource
{
    public function __construct(mixed $resource)
    {
        parent::__construct(EnrollmentSubmitPayload::from($resource));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var EnrollmentSubmitPayload $payload */
        $payload = $this->resource;

        return $payload->toArray();
    }
}
