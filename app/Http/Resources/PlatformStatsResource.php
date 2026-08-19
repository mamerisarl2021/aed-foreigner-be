<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlatformStatsResource extends JsonResource
{
    /**
     * @return array{stats: mixed, roles: mixed, enrollment_by_status: mixed}
     */
    public function toArray(Request $request): array
    {
        /** @var array{stats: mixed, roles: mixed, enrollment_by_status: mixed} $payload */
        $payload = $this->resource;

        return [
            'stats' => $payload['stats'],
            'roles' => $payload['roles'],
            'enrollment_by_status' => $payload['enrollment_by_status'],
        ];
    }
}
