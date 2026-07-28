<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Stats\StatsService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

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
    public function index(): JsonResponse
    {
        Gate::authorize('viewStats');

        return $this->respond($this->stats->overview());
    }
}
