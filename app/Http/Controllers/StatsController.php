<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Admin\PlatformStatsRequest;
use App\Http\Resources\PlatformStatsResource;
use App\Services\Stats\StatsService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Admin')]
class StatsController extends BaseController
{
    public function __construct(
        private readonly StatsService $stats,
    ) {}

    /**
     * Platform statistics overview
     *
     * Totals per entity, users per role, and enrollment requests per status.
     */
    public function index(PlatformStatsRequest $request): JsonResponse
    {
        $this->authorize('viewStats');

        $result = $this->stats->overview();

        return $this->sendResponse($result->message, new PlatformStatsResource($result->data));
    }
}
