<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\DataTransferObjects\AuditLogListFilters;
use App\Support\SqlLike;
use Illuminate\Pagination\LengthAwarePaginator;
use OwenIt\Auditing\Models\Audit;

final class AuditLogQueryService
{
    /**
     * @return LengthAwarePaginator<int, Audit>
     */
    public function list(AuditLogListFilters $filters): LengthAwarePaginator
    {
        $table = (new Audit)->getTable();

        $query = Audit::query()
            ->leftJoin('users', "{$table}.user_id", '=', 'users.id')
            ->select("{$table}.*", 'users.name as user_name', 'users.email as user_email');

        $textFilters = [
            'event' => $filters->event,
            'user_id' => $filters->userId,
            'auditable_type' => $filters->auditableType,
            'auditable_id' => $filters->auditableId,
            'ip_address' => $filters->ipAddress,
        ];
        foreach ($textFilters as $column => $value) {
            if (is_string($value) && $value !== '') {
                $query->where("{$table}.{$column}", 'LIKE', SqlLike::contains($value));
            }
        }

        if (is_string($filters->dateFrom) && $filters->dateFrom !== '') {
            $query->where("{$table}.created_at", '>=', $filters->dateFrom);
        }

        if (is_string($filters->dateTo) && $filters->dateTo !== '') {
            $query->where("{$table}.created_at", '<=', $filters->dateTo);
        }

        return $query->orderBy("{$table}.created_at", 'desc')->paginate($filters->perPage);
    }
}
