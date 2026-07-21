<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuditLogController extends BaseController
{
    /**
     * Retrieve all audit logs with optional filters and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $table = config('audit.drivers.database.table', 'audits');

        $query = DB::table($table)
            ->leftJoin('users', "{$table}.user_id", '=', 'users.id')
            ->select("{$table}.*", 'users.name as user_name', 'users.email as user_email');

        if ($request->has('event')) {
            $query->where('event', 'LIKE', "%{$request->input('event')}%");
        }

        if ($request->has('user_id')) {
            $query->where('user_id', 'LIKE', "%{$request->input('user_id')}%");
        }

        if ($request->has('auditable_type')) {
            $query->where('auditable_type', 'LIKE', "%{$request->input('auditable_type')}%");
        }

        if ($request->has('auditable_id')) {
            $query->where('auditable_id', 'LIKE', "%{$request->input('auditable_id')}%");
        }

        if ($request->has('ip_address')) {
            $query->where('ip_address', 'LIKE', "%{$request->input('ip_address')}%");
        }

        if ($request->has('date_from') && $request->has('date_to')) {
            $query->whereBetween('created_at', [$request->input('date_from'), $request->input('date_to')]);
        }

        $perPage = min((int) $request->input('per_page', 15), 100);

        $logs = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json($logs);
    }
}
