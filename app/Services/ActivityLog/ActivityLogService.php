<?php

declare(strict_types=1);

namespace App\Services\ActivityLog;

use App\Enums\ActivityLogAction;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\ServiceResult;
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
        ?string $enrollmentRequestId = null,
        ?array $metadata = null,
    ): void {
        ActivityLog::create([
            'action_code' => $action->label(),
            'description' => $description,
            'actor_user_id' => $actorUserId,
            'enrollment_request_id' => $enrollmentRequestId,
            'metadata' => $metadata,
            // Résolue ici, au moment de l'écriture : les appelants sont des
            // services métier qui n'ont pas à connaître la couche HTTP. Nulle
            // hors requête (commande Artisan, job en file).
            'ip_address' => request()->ip(),
            'created_at' => now(),
        ]);
    }

    public function list(Request $request): LengthAwarePaginator
    {
        $query = ActivityLog::query()->orderByDesc('created_at');

        if ($request->filled('q')) {
            $q = (string) $request->input('q');
            $query->where(function ($sub) use ($q) {
                $sub->where('description', 'like', "%{$q}%")
                    ->orWhere('action_code', 'like', "%{$q}%");
            });
        }

        if ($request->filled('action')) {
            $action = ActivityLogAction::tryFrom((string) $request->input('action'));
            if ($action) {
                $query->where('action_code', $action->label());
            } else {
                $query->where('action_code', 'like', '%'.$request->input('action').'%');
            }
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', Carbon::parse((string) $request->input('from'))->toDateString());
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', Carbon::parse((string) $request->input('to'))->toDateString());
        }

        $perPage = min((int) $request->input('per_page', $request->input('perPage', 20)), 100);

        return $query->paginate($perPage);
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
