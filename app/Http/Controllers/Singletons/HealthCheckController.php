<?php

declare(strict_types=1);

namespace App\Http\Controllers\Singletons;

use App\Http\Controllers\Controller;
use App\Services\Infrastructure\HealthCheckService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Infrastructure')]
class HealthCheckController extends Controller
{
    public function __construct(
        private readonly HealthCheckService $health,
    ) {}

    /**
     * Service health check
     *
     * Public. Returns `{"status": "UP"}` (200) when the database is reachable,
     * `{"status": "DOWN"}` (503) otherwise. Used by Consul and load balancers.
     * Exception to the standard `{success,message,data}` envelope (§9.2).
     */
    public function __invoke(Request $request): JsonResponse
    {
        if (! $this->health->isDatabaseUp()) {
            return response()->json(['status' => 'DOWN'], 503);
        }

        return response()->json(['status' => 'UP'], 200);
    }
}
