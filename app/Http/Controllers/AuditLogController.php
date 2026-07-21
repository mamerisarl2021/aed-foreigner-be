<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AuditLogController extends BaseController
{
    public function auditLogs(Request $request)
    {
        $user = $request->user();
        $agentRoles = [config('roles.agent')];
        $perPage = min((int) $request->get('perPage', 15), 100);

        // Check if the user has the 'agent' role
        if ($user->hasRole(config('roles.administrateur_plateforme'))) {
            // SuperAdmin peut voir tous les logs
            $logs = ActivityLog::paginate($perPage);
        } elseif ($user->hasAnyRole($agentRoles)) {
            // Agents voient uniquement leurs propres actions et celles des Manageurs
            $logs = ActivityLog::whereIn('user_role', ['Agent', 'Manageur'])->paginate($perPage);
        } elseif ($user->hasRole('client')) {
            // Managers voient leurs actions et celles de leurs employés
            $logs = ActivityLog::where('user_id', $user->id)->orWhereIn('user_role', ['Employé'])->paginate($perPage);
        } else {
            $logs = ActivityLog::where('user_id', $user->id)->paginate($perPage);
        }

        return response()->json($logs);
    }

    public function certificateAudit(Request $request)
    {
        $perPage = min((int) $request->get('perPage', 15), 100);
        $logs = ActivityLog::where('action', 'certificate_request')
            ->orWhere('action', 'signature_transaction')
            ->paginate($perPage);

        return response()->json($logs);
    }

    /**
     * Retrieve all audit logs with optional filters and pagination.
     *
     * @return JsonResponse
     */
    public function index(Request $request)
    {
        // Retrieve the audits table name from the config
        $table = config('audit.drivers.database.table', 'audits');

        // Start building the query
        $query = DB::table($table)
            ->leftJoin('users', "{$table}.user_id", '=', 'users.id')
            ->select("{$table}.*", 'users.name as user_name', 'users.email as user_email');

        // Apply filters based on the request parameters
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

        // Set default pagination size or get it from the request
        $perPage = min((int) $request->input('per_page', 15), 100);

        // Execute the query with pagination
        $logs = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json($logs);
    }
}
