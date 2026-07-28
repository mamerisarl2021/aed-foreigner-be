<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Audit\ListAuditLogsRequest;
use App\Services\Audit\AuditLogQueryService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

#[Group('Admin')]
class AuditLogController extends BaseController
{
    public function __construct(
        private readonly AuditLogQueryService $auditLogs,
    ) {}

    /**
     * List technical audit logs (model change history)
     *
     * Optional filters: event, user_id, auditable_type, auditable_id, ip_address, date range.
     * Defaults: per_page=15 (max 100), sorted by created_at desc.
     */
    public function index(ListAuditLogsRequest $request): JsonResponse
    {
        Gate::authorize('viewAudits');

        return $this->sendResponse('Journaux d\'audit.', $this->auditLogs->list($request));
    }
}
