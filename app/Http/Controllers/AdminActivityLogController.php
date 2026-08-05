<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Admin\ListActivityLogsRequest;
use App\Http\Requests\Admin\ShowActivityLogRequest;
use App\Http\Resources\ActivityLogDetailResource;
use App\Http\Resources\ActivityLogListResource;
use App\Models\ActivityLog;
use App\Services\ActivityLog\ActivityLogService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Admin')]
final class AdminActivityLogController extends BaseController
{
    public function __construct(
        private readonly ActivityLogService $activityLogService,
    ) {}

    /**
     * List business activity logs (journaux)
     *
     * Optional filters: q, action, from, to, per_page.
     * Defaults: per_page=20 (max 100), sorted by created_at desc.
     * Admin only.
     */
    public function index(ListActivityLogsRequest $request): JsonResponse
    {
        $this->authorize('viewAny', ActivityLog::class);

        $paginator = $this->activityLogService->list($request);
        $paginator->getCollection()->transform(fn ($item) => new ActivityLogListResource($item));

        return $this->sendResponse('Historique des actions.', $paginator);
    }

    /**
     * Activity log detail (admin only)
     */
    public function show(ShowActivityLogRequest $request): JsonResponse
    {
        $result = $this->activityLogService->show((string) $request->validated('id'));
        if (! $result->success) {
            return $this->respond($result);
        }

        $this->authorize('view', $result->data);

        return $this->sendResponse($result->message, new ActivityLogDetailResource($result->data));
    }
}
