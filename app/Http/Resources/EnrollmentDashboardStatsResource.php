<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\DataTransferObjects\EnrollmentDashboardStatsPayload;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EnrollmentDashboardStatsPayload
 */
class EnrollmentDashboardStatsResource extends JsonResource
{
    public function __construct(mixed $resource)
    {
        parent::__construct(EnrollmentDashboardStatsPayload::from($resource));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var EnrollmentDashboardStatsPayload $payload */
        $payload = $this->resource;

        return $payload->toArray();
    }
}
