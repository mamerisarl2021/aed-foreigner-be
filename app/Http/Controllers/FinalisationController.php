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
     * Validate finalisation invitation token
     *
     * Open the secure link from the approval email (`?token=`).
     * Success data: demande_id, numero_suivi (PK…), npi, email, statut (APPROUVEE).
     * Token TTL: 60 minutes from issuance.
     */
    public function show(ShowFinalisationRequest $request): JsonResponse
    {
        return $this->respond($this->finalizationService->showByToken($request->input('token')));
    }

    /**
     * Send finalisation email OTP
     *
     * Body: numero_suivi (required) — tracking code from submit / invitation email.
     * Demand must be APPROUVEE. Sends a 6-digit OTP to the enrollment email (AED OTP, not TrustedX MFA).
     */
    public function sendOtp(SendFinalisationOtpRequest $request): JsonResponse
    {
        return $this->respond($this->finalizationService->sendOtp($request->input('numero_suivi')));
    }

    /**
     * Verify finalisation email OTP
     *
     * Body: numero_suivi, otp (6 digits). On success stores a short-lived OTP proof (~15 min) required before POST …/finalisation.
     * Success data: numero_suivi, demande_id, otp_verified=true.
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
     * Path `{id}` = demande_id. Requires prior POST …/finalisation/otp/verify for the same numero_suivi.
     * Body: password (min 8), security_questions (min 2 items with question + answer),
     * numero_suivi (required for FE flow; must match the demand), token (optional invitation token from the email link).
     * No client PIN — server generates a 4-digit PIN for TrustedX.
     * Success: statut ENROLEE, npi, numero_suivi, demande_id.
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
