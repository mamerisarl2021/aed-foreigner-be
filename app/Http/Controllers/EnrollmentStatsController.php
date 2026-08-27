<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Enrollment\DashboardEnrollmentStatsRequest;
use App\Http\Resources\EnrollmentDashboardStatsResource;
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
     * `par_type` breaks the counters down by PERSONNE_PHYSIQUE / PERSONNE_MORALE — each one
     * carrying its own period-over-period variation, which is what the dashboard cards read.
     * `approved` (APPROUVEE) and `enrolled` (ENROLEE) are counted apart: a favourable decision
     * is not yet an enrolment.
     * Query: granularite=semaine|mois (default semaine).
     */
    public function index(DashboardEnrollmentStatsRequest $request): JsonResponse
    {
        $this->authorize('viewEnrollmentStats', EnrollmentRequest::class);

        $granularite = $request->validated('granularite') === 'mois' ? 'mois' : 'semaine';

        return $this->sendResponse(
            'Statistiques d\'enrôlement.',
            new EnrollmentDashboardStatsResource($this->statsService->dashboard($granularite))
        );
    }
}
