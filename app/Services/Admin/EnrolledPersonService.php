<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Enums\EnrollmentStatus;
use App\Models\EnrollmentRequest;
use App\Models\User;
use App\Services\ServiceResult;
use Carbon\Carbon;
use Exception;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class EnrolledPersonService
{
    public function list(Request $request): LengthAwarePaginator
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
                'users.*',
                'enrollment_requests.updated_at as enrolled_at',
            ])
            ->distinct();

        if ($request->filled('q')) {
            $q = $request->input('q');
            $query->where(function ($sub) use ($q) {
                $sub->where('users.name', 'like', "%{$q}%")
                    ->orWhere('users.first_name', 'like', "%{$q}%")
                    ->orWhere('users.email', 'like', "%{$q}%")
                    ->orWhere('users.npi', 'like', "%{$q}%");
            });
        }

        $orderBy = $request->input('order_by', 'enrolled_at');
        $orderDir = strtolower($request->input('order_dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        if (! in_array($orderBy, ['enrolled_at', 'name', 'email'], true)) {
            $orderBy = 'enrolled_at';
        }

        $query->orderBy($orderBy, $orderDir);

        $perPage = min((int) $request->input('per_page', $request->input('perPage', 15)), 100);

        return $query->paginate($perPage);
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

            $kyc = $user->enrollment_kyc;
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
