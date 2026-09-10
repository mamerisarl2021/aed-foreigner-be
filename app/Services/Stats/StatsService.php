<?php

declare(strict_types=1);

namespace App\Services\Stats;

use App\Models\EnrollmentRequest;
use App\Models\Identity;
use App\Models\OTP;
use App\Models\User;
use App\Services\ServiceResult;
use Illuminate\Support\Facades\DB;

final class StatsService
{
    public function overview(): ServiceResult
    {
        $stats = [
            'total_users' => User::count(),
            'total_identities' => Identity::count(),
            'total_enrollment_requests' => EnrollmentRequest::count(),
            'total_otps' => OTP::count(),
        ];

        $roleKeys = [
            'administrateur_plateforme' => (string) config('roles.administrateur_plateforme'),
            'client' => (string) config('roles.client'),
            'agent' => (string) config('roles.agent'),
            'responsable_de_validation' => (string) config('roles.responsable_de_validation'),
            'manager' => (string) config('roles.manager'),
        ];

        $roleCounts = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', User::class)
            ->whereIn('roles.name', array_values($roleKeys))
            ->select('roles.name', DB::raw('COUNT(*)::int as total'))
            ->groupBy('roles.name')
            ->pluck('total', 'name');

        $roles = [];
        foreach ($roleKeys as $payloadKey => $roleName) {
            $roles[$payloadKey] = (int) ($roleCounts[$roleName] ?? 0);
        }

        $enrollmentByStatus = EnrollmentRequest::query()
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        return ServiceResult::ok('Statistiques de la plateforme.', [
            'stats' => $stats,
            'roles' => $roles,
            'enrollment_by_status' => $enrollmentByStatus,
        ]);
    }
}
