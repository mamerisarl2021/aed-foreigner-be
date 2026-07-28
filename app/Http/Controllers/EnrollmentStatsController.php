<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\EnrollmentRequest;
use App\Services\Enrollment\EnrollmentStatsService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Admin')]
class EnrollmentStatsController extends BaseController
{
    public function __construct(
        private readonly EnrollmentStatsService $statsService,
    ) {}

    /**
     * Enrollment dashboard statistics
     */
    public function index(): JsonResponse
    {
        $this->authorize('viewEnrollmentStats', EnrollmentRequest::class);

        return $this->sendResponse(
            'Statistiques d\'enrôlement.',
            $this->statsService->dashboard()
        );
    }
}
