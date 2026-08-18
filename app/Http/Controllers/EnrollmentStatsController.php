<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Enrollment\DashboardEnrollmentStatsRequest;
use App\Models\EnrollmentRequest;
use App\Services\Enrollment\EnrollmentStatsService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Enrollment - Manager')]
class EnrollmentStatsController extends BaseController
{
    public function __construct(
        private readonly EnrollmentStatsService $statsService,
    ) {}

    /**
     * Enrollment dashboard statistics (manager)
     *
     * Global counts, rejection rate, average handling time, motifs, evolution, and period trends.
     * Query: granularite=semaine|mois (default semaine).
     */
    public function index(DashboardEnrollmentStatsRequest $request): JsonResponse
    {
        $this->authorize('viewEnrollmentStats', EnrollmentRequest::class);

        $granularite = $request->validated('granularite') === 'mois' ? 'mois' : 'semaine';

        return $this->sendResponse(
            'Statistiques d\'enrôlement.',
            $this->statsService->dashboard($granularite)
        );
    }
}
