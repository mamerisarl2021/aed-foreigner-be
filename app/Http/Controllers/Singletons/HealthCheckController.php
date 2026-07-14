<?php

declare(strict_types=1);

namespace App\Http\Controllers\Singletons;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthCheckController extends Controller
{
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
