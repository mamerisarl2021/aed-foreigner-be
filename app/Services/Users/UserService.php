<?php

declare(strict_types=1);

namespace App\Services\Users;

use App\Models\User;
use App\Services\ServiceResult;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class UserService
{
    public function show(string $id): ServiceResult
    {
        try {
            $user = User::findOrFail($id);

            return ServiceResult::ok('Utilisateur récupéré.', $user);
        } catch (ModelNotFoundException) {
            return ServiceResult::fail('Utilisateur introuvable.', null, 404);
        } catch (Exception $e) {
            Log::error('Fetching user failed: '.$e->getMessage());

            return ServiceResult::fail('Fetching user failed.', null, 500);
        }
    }

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

    public function destroy(string $id): ServiceResult
    {
        try {
            $user = User::findOrFail($id);
            $user->delete();

            return ServiceResult::ok('User deleted successfully.', []);
        } catch (ModelNotFoundException) {
            return ServiceResult::fail('Utilisateur introuvable.', null, 404);
        } catch (Exception $e) {
            Log::error('Deleting user failed: '.$e->getMessage());

            return ServiceResult::fail('Deleting user failed.', null, 500);
        }
    }

    /**
     * @param  list<array{id: string, status: string}>  $users
     */
    public function updateStatuses(array $users): ServiceResult
    {
        try {
            DB::transaction(function () use ($users) {
                foreach ($users as $userData) {
                    $user = User::findOrFail($userData['id']);
                    $user->update(['status' => $userData['status']]);
                }
            });

            return ServiceResult::ok("Le statut de l'utilisateur à bien été mis à jour", $users);
        } catch (Exception $e) {
            Log::error('Failed to update user statuses: '.$e->getMessage());

            return ServiceResult::fail('Failed to update user statuses.', null, 500);
        }
    }
}
