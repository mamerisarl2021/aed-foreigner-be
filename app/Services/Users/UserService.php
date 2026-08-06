<?php

declare(strict_types=1);

namespace App\Services\Users;

use App\Enums\ActivityLogAction;
use App\Models\User;
use App\Services\ActivityLog\ActivityLogService;
use App\Services\ServiceResult;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class UserService
{
    public function __construct(
        private readonly ActivityLogService $activityLog,
    ) {}

    public function search(string $query, int $limit, string $excludeUserId): ServiceResult
    {
        try {
            $limit = min(max($limit, 1), 100);

            $users = User::where(function ($q) use ($query) {
                $q->where('email', 'LIKE', "%{$query}%")
                    ->orWhere('name', 'LIKE', "%{$query}%")
                    ->orWhere('npi', 'LIKE', "%{$query}%");
            })
                ->where('id', '!=', $excludeUserId)
                ->limit($limit)
                ->get();

            return ServiceResult::ok('Résultats de recherche.', $users);
        } catch (Exception $e) {
            Log::error('Searching users failed: '.$e->getMessage());

            return ServiceResult::fail('Searching users failed.', null, 500);
        }
    }

    public function searchByEmail(string $email, int $limit, string $excludeUserId): ServiceResult
    {
        try {
            $limit = min(max($limit, 1), 100);

            $users = User::where('email', 'LIKE', "%{$email}%")
                ->where('id', '!=', $excludeUserId)
                ->limit($limit)
                ->get();

            return ServiceResult::ok('Résultats de recherche.', $users);
        } catch (Exception $e) {
            Log::error('Searching users failed: '.$e->getMessage());

            return ServiceResult::fail('Searching users failed.', null, 500);
        }
    }

    /**
     * @param  list<array{id: string, status: string}>  $users
     */
    public function updateStatuses(array $users, ?string $actorUserId = null): ServiceResult
    {
        try {
            DB::transaction(function () use ($users) {
                foreach ($users as $userData) {
                    $user = User::findOrFail($userData['id']);
                    $user->update(['status' => $userData['status']]);
                }
            });

            $this->activityLog->record(
                ActivityLogAction::StatutUtilisateurModifie,
                sprintf('%d statut(s) utilisateur mis à jour.', count($users)),
                $actorUserId,
                null,
                ['users' => $users],
            );

            return ServiceResult::ok("Le statut de l'utilisateur à bien été mis à jour", $users);
        } catch (Exception $e) {
            Log::error('Failed to update user statuses: '.$e->getMessage());

            return ServiceResult::fail('Failed to update user statuses.', null, 500);
        }
    }
}
