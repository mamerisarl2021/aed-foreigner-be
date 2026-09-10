<?php

declare(strict_types=1);

namespace App\Services\ActivityLog;

use App\DataTransferObjects\ActivityLogListFilters;
use App\Enums\ActivityLogAction;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\ServiceResult;
use App\Support\SqlLike;
use Carbon\Carbon;
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
        ?string $enrollmentRequestId = null,
        ?array $metadata = null,
    ): void {
        $psceqClientId = is_array($metadata) && is_string($metadata['psceq_client_id'] ?? null)
            ? $metadata['psceq_client_id']
            : null;

        ActivityLog::create([
            'action_code' => $action->label(),
            'description' => $description,
            'actor_user_id' => $actorUserId,
            'enrollment_request_id' => $enrollmentRequestId,
            'psceq_client_id' => $psceqClientId,
            'metadata' => $metadata,
            // Résolue ici, au moment de l'écriture : les appelants sont des
            // services métier qui n'ont pas à connaître la couche HTTP. Nulle
            // hors requête (commande Artisan, job en file).
            'ip_address' => request()->ip(),
            'created_at' => now(),
        ]);
    }

    /**
     * @return LengthAwarePaginator<int, ActivityLog>
     */
    public function list(ActivityLogListFilters $filters): LengthAwarePaginator
    {
        $query = ActivityLog::query()->orderByDesc('created_at');

        if (is_string($filters->q) && $filters->q !== '') {
            $pattern = SqlLike::contains($filters->q);
            $query->where(function ($sub) use ($pattern) {
                $sub->where('description', 'like', $pattern)
                    ->orWhere('action_code', 'like', $pattern);
            });
        }

        if (is_string($filters->action) && $filters->action !== '') {
            $action = ActivityLogAction::tryFrom($filters->action);
            if ($action !== null) {
                $query->where('action_code', $action->label());
            }
        }

        if (is_string($filters->from) && $filters->from !== '') {
            $query->whereDate('created_at', '>=', Carbon::parse($filters->from)->toDateString());
        }

        if (is_string($filters->to) && $filters->to !== '') {
            $query->whereDate('created_at', '<=', Carbon::parse($filters->to)->toDateString());
        }

        return $query->paginate($filters->perPage);
    }

    public function show(string $id): ServiceResult
    {
        $log = ActivityLog::query()->with('actor')->find($id);
        if (! $log) {
            return ServiceResult::fail('Journal introuvable.', null, 404);
        }

        return ServiceResult::ok('Détail du journal.', $log);
    }

    public static function actorLabel(?User $user): string
    {
        if (! $user) {
            return 'Système';
        }

        return trim(($user->first_name ?? '').' '.($user->name ?? '')) ?: ($user->email ?? 'Utilisateur');
    }
}
