<?php

declare(strict_types=1);

namespace App\Services\Stats;

use App\Models\EnrollmentRequest;
use App\Models\Identity;
use App\Models\OTP;
use App\Models\User;
use App\Services\ServiceResult;

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

        $roles = [
            'administrateur_plateforme' => User::role(config('roles.administrateur_plateforme'))->count(),
            'client' => User::role(config('roles.client'))->count(),
            'agent' => User::role(config('roles.agent'))->count(),
            'responsable_de_validation' => User::role(config('roles.responsable_de_validation'))->count(),
            'manager' => User::role(config('roles.manager'))->count(),
            'auditeur' => User::role(config('roles.auditeur'))->count(),
        ];

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
