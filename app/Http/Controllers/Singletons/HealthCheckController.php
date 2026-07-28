<?php

declare(strict_types=1);

namespace App\Http\Controllers\Singletons;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

#[Group('Infrastructure')]
class HealthCheckController extends Controller
{
    /**
     * Service health check
     *
     * Public. Returns `{"status": "UP"}` (200) when the database is reachable,
     * `{"status": "DOWN"}` (503) otherwise. Used by Consul and load balancers.
     */
    public function __invoke(Request $request): JsonResponse
    {
        try {
            DB::connection()->getPdo();
        } catch (Throwable $e) {
            return response()->json(['status' => 'DOWN'], 503);
        }

        return response()->json(['status' => 'UP'], 200);
    }
}
