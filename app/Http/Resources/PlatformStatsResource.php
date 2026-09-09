<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\DataTransferObjects\PlatformStatsPayload;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PlatformStatsPayload
 */
class PlatformStatsResource extends JsonResource
{
    public function __construct(mixed $resource)
    {
        parent::__construct(PlatformStatsPayload::from($resource));
    }

    /**
     * @return array{stats: array<string, int>, roles: array<string, int>, enrollment_by_status: array<string, int>}
     */
    public function toArray(Request $request): array
    {
        /** @var PlatformStatsPayload $payload */
        $payload = $this->resource;

        return $payload->toArray();
    }
}
