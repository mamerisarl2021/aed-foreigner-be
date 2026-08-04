<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Enrollment\SendFinalisationOtpRequest;
use App\Http\Requests\Enrollment\ShowFinalisationRequest;
use App\Http\Requests\Enrollment\StoreFinalisationRequest;
use App\Http\Requests\Enrollment\VerifyFinalisationOtpRequest;
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
     * Diagram §4 — open link from invitation email. Returns numero_suivi, npi, demande_id.
     */
    public function show(ShowFinalisationRequest $request): JsonResponse
    {
        return $this->respond($this->finalizationService->showByToken($request->input('token')));
    }

    /**
     * Send finalisation email OTP for a tracking number (APPROUVEE only)
     */
    public function sendOtp(SendFinalisationOtpRequest $request): JsonResponse
    {
        return $this->respond($this->finalizationService->sendOtp($request->input('numero_suivi')));
    }

    /**
     * Verify finalisation email OTP
     */
    public function verifyOtp(VerifyFinalisationOtpRequest $request): JsonResponse
    {
        return $this->respond($this->finalizationService->verifyOtp(
            $request->input('numero_suivi'),
            $request->input('otp'),
        ));
    }

    /**
     * Complete enrollment (password + security questions)
     *
     * Requires prior OTP verify for numero_suivi. PIN is generated server-side for TrustedX.
     * Optional invitation token may be sent alongside numero_suivi.
     */
    public function store(StoreFinalisationRequest $request, string $id): JsonResponse
    {
        /** @var array<int, array{question: string, answer: string}> $questions */
        $questions = $request->input('security_questions', []);

        return $this->respond($this->finalizationService->finalize(
            $id,
            $request->input('password'),
            $questions,
            $request->input('token'),
            $request->input('numero_suivi'),
        ));
    }
}
