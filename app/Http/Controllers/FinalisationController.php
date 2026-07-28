<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Enrollment\ShowFinalisationRequest;
use App\Http\Requests\Enrollment\StoreFinalisationRequest;
use App\Services\Enrollment\ForeignerFinalizationService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Enrollment - Physique')]
final class FinalisationController extends BaseController
{
    public function __construct(
        private readonly ForeignerFinalizationService $finalizationService,
    ) {}

    /**
     * Validate finalisation token and return UI payload
     *
     * Diagram §4 — open link from invitation email.
     */
    public function show(ShowFinalisationRequest $request): JsonResponse
    {
        return $this->respond($this->finalizationService->showByToken($request->input('token')));
    }

    /**
     * Complete enrollment (password, PIN, security questions)
     *
     * Diagram §4. TrustedX identity must already exist (APPROUVEE). Sets statut ENROLEE.
     */
    public function store(StoreFinalisationRequest $request, int $id): JsonResponse
    {
        return $this->respond($this->finalizationService->finalize(
            $id,
            $request->input('token'),
            $request->input('password'),
            $request->input('pin'),
            $request->input('security_questions'),
        ));
    }
}
