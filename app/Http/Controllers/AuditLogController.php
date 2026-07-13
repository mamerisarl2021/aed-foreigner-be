<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;


class AuditLogController extends BaseController
{
    public function auditLogs()
    {
        $user = User::find(Auth::user()->id);
        $agentRoles = ['tech_one', 'tech_two', 'tech_three'];

        // Check if the user has the 'agent' role
        if ($user->hasRole('admin')) {
            // SuperAdmin peut voir tous les logs
            $logs = ActivityLog::all();
        } elseif ($user->hasAnyRole($agentRoles)) {
            // Agents voient uniquement leurs propres actions et celles des Manageurs
            $logs = ActivityLog::whereIn('user_role', ['Agent', 'Manageur'])->get();
        } elseif ($user->hasRole('client')) {
            // Managers voient leurs actions et celles de leurs employés
            $logs = ActivityLog::where('user_id', $user->id)->orWhereIn('user_role', ['Employé'])->get();
        }

        return response()->json($logs);
    }

    public function certificateAudit()
    {
        $logs = ActivityLog::where('action', 'certificate_request')
            ->orWhere('action', 'signature_transaction')
            ->get();

        return response()->json($logs);
    }

    /**
     * Retrieve all audit logs with optional filters and pagination.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
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
            $query->where('event','LIKE', "%{$request->input('event')}%");
        }

        if ($request->has('user_id')) {
            $query->where('user_id','LIKE', "%{$request->input('user_id')}%");
        }

        if ($request->has('auditable_type')) {
            $query->where('auditable_type','LIKE', "%{$request->input('auditable_type')}%");
        }

        if ($request->has('auditable_id')) {
            $query->where('auditable_id','LIKE', "%{$request->input('auditable_id')}%");
        }

        if ($request->has('ip_address')) {
            $query->where('ip_address','LIKE', "%{$request->input('ip_address')}%");
        }

        if ($request->has('date_from') && $request->has('date_to')) {
            $query->whereBetween('created_at', [$request->input('date_from'), $request->input('date_to')]);
        }

        // Set default pagination size or get it from the request
        $perPage = $request->input('per_page', 15);

        // Execute the query with pagination
        $logs = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json($logs);
    }
}