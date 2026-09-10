<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\DataTransferObjects\EnrolledPersonListFilters;
use App\Enums\EnrollmentStatus;
use App\Models\User;
use App\Services\ServiceResult;
use App\Support\SqlLike;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;

final class EnrolledPersonService
{
    /**
     * @return LengthAwarePaginator<int, User>
     */
    public function list(EnrolledPersonListFilters $filters): LengthAwarePaginator
    {
        $query = User::query()
            ->role(config('roles.client'))
            ->where('users.status', 'ACTIVE')
            ->join('enrollment_requests', function ($join) {
                $join->on('enrollment_requests.email', '=', 'users.email')
                    ->where('enrollment_requests.status', EnrollmentStatus::Enrolee->value)
                    ->where('enrollment_requests.type', 'PERSONNE_PHYSIQUE');
            })
            ->select([
                'users.id',
                'users.name',
                'users.first_name',
                'users.email',
                'users.npi',
                'users.last_login_at',
                'enrollment_requests.updated_at as enrolled_at',
            ])
            ->distinct();

        if (is_string($filters->q) && $filters->q !== '') {
            $pattern = SqlLike::contains($filters->q);
            $query->where(function ($sub) use ($pattern) {
                $sub->where('users.name', 'like', $pattern)
                    ->orWhere('users.first_name', 'like', $pattern)
                    ->orWhere('users.email', 'like', $pattern)
                    ->orWhere('users.npi', 'like', $pattern);
            });
        }

        $query->orderBy($filters->orderBy, $filters->orderDir);

        return $query->paginate($filters->perPage);
    }

    public function show(string $id): ServiceResult
    {
        try {
            $user = User::query()
                ->role(config('roles.client'))
                ->where('users.status', 'ACTIVE')
                ->where('users.id', $id)
                ->join('enrollment_requests', function ($join) {
                    $join->on('enrollment_requests.email', '=', 'users.email')
                        ->where('enrollment_requests.status', EnrollmentStatus::Enrolee->value)
                        ->where('enrollment_requests.type', 'PERSONNE_PHYSIQUE');
                })
                ->select([
                    'users.*',
                    'enrollment_requests.id as enrollment_id',
                    'enrollment_requests.kyc_data as enrollment_kyc',
                    'enrollment_requests.updated_at as enrolled_at',
                ])
                ->firstOrFail();

            $kyc = $user->getAttribute('enrollment_kyc');
            if (is_string($kyc)) {
                $user->setAttribute('enrollment_kyc', json_decode($kyc, true) ?? []);
            }

            return ServiceResult::ok('Personne enrôlée récupérée.', $user);
        } catch (ModelNotFoundException $e) {
            return ServiceResult::fail('Personne enrôlée introuvable.', null, 404);
        } catch (Exception $e) {
            Log::error('Failed to retrieve enrolled person: '.$e->getMessage());

            return ServiceResult::fail('Impossible de récupérer la personne enrôlée.', null, 500);
        }
    }
}
