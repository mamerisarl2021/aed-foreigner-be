<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\ActivityLogListResource;
use App\Models\ActivityLog;
use App\Services\ActivityLog\ActivityLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Annotations as OA;

final class AdminActivityLogController extends BaseController
{
    public function __construct(
        private readonly ActivityLogService $activityLogService,
    ) {}

    /**
     * @OA\Get(
     *      path="/api/v1/admin/activity-logs",
     *      operationId="adminActivityLogs",
     *      tags={"Admin"},
     *      summary="List business activity logs (journaux)",
     *      security={{"sanctum":{}}},
     *
     *      @OA\Parameter(name="q", in="query", @OA\Schema(type="string")),
     *      @OA\Parameter(name="action", in="query", @OA\Schema(type="string")),
     *      @OA\Parameter(name="from", in="query", @OA\Schema(type="string", format="date")),
     *      @OA\Parameter(name="to", in="query", @OA\Schema(type="string", format="date")),
     *      @OA\Parameter(name="per_page", in="query", @OA\Schema(type="integer", default=15)),
     *
     *      @OA\Response(response=200, description="Paginated activity logs")
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ActivityLog::class);

        $paginator = $this->activityLogService->list($request);
        $paginator->getCollection()->transform(fn ($item) => new ActivityLogListResource($item));

        return $this->sendResponse('Historique des actions.', $paginator);
    }
}
