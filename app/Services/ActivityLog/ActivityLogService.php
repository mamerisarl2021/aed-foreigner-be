<?php

declare(strict_types=1);

namespace App\Services\ActivityLog;

use App\Enums\ActivityLogAction;
use App\Models\ActivityLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

final class ActivityLogService
{
    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function record(
        ActivityLogAction $action,
        string $description,
        ?string $actorUserId = null,
        ?int $enrollmentRequestId = null,
        ?array $metadata = null,
    ): void {
        ActivityLog::create([
            'action_code' => $action->label(),
            'description' => $description,
            'actor_user_id' => $actorUserId,
            'enrollment_request_id' => $enrollmentRequestId,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }

    public function list(Request $request): LengthAwarePaginator
    {
        $query = ActivityLog::query()->orderByDesc('created_at');

        if ($request->filled('q')) {
            $q = $request->input('q');
            $query->where('description', 'like', "%{$q}%");
        }

        if ($request->filled('action')) {
            $query->where('action_code', 'like', '%'.$request->input('action').'%');
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', Carbon::parse($request->input('from'))->toDateString());
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', Carbon::parse($request->input('to'))->toDateString());
        }

        $perPage = min((int) $request->input('per_page', $request->input('perPage', 15)), 100);

        return $query->paginate($perPage);
    }

    public static function actorLabel(?User $user): string
    {
        if (! $user) {
            return 'Système';
        }

        return trim(($user->first_name ?? '').' '.($user->name ?? '')) ?: ($user->email ?? 'Utilisateur');
    }
}
