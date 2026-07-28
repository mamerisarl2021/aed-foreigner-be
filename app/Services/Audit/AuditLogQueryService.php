<?php

declare(strict_types=1);

namespace App\Services\Audit;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use OwenIt\Auditing\Models\Audit;

final class AuditLogQueryService
{
    /**
     * @return LengthAwarePaginator<int, Audit>
     */
    public function list(Request $request): LengthAwarePaginator
    {
        $table = (new Audit)->getTable();

        $query = Audit::query()
            ->leftJoin('users', "{$table}.user_id", '=', 'users.id')
            ->select("{$table}.*", 'users.name as user_name', 'users.email as user_email');

        foreach (['event', 'user_id', 'auditable_type', 'auditable_id', 'ip_address'] as $filter) {
            if ($request->filled($filter)) {
                $query->where("{$table}.{$filter}", 'LIKE', '%'.$request->input($filter).'%');
            }
        }

        if ($request->filled('date_from') && $request->filled('date_to')) {
            $query->whereBetween("{$table}.created_at", [
                $request->input('date_from'),
                $request->input('date_to'),
            ]);
        }

        $perPage = min((int) $request->input('per_page', 15), 100);

        return $query->orderBy("{$table}.created_at", 'desc')->paginate($perPage);
    }
}
